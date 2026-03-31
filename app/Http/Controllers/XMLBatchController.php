<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class XMLBatchController extends Controller
{
    protected $xmlFolder;
    protected $batchSize = 10000;
    protected $cfdiNamespace = 'http://www.sat.gob.mx/cfd/4';
    protected $tfdNamespace = 'http://www.sat.gob.mx/TimbreFiscalDigital';

    public function __construct()
    {
        $this->xmlFolder = storage_path('app/todosXML');
    }

    /**
     * 1. INICIAR BATCH: Escanea archivos y divide en bloques de 10,000
     */
    public function initBatch(Request $request)
    {
        try {
            // Escanear todos los archivos (solo paths, no contenido)
            $allFiles = $this->scanXmlFiles();
            $totalFiles = count($allFiles);
            $totalBatches = ceil($totalFiles / $this->batchSize);

            $batchId = uniqid('batch_', true);

            // Guardar índice en cache (24 horas)
            Cache::put("batch_{$batchId}_files", $allFiles, now()->addDay());
            Cache::put("batch_{$batchId}_total", $totalFiles, now()->addDay());
            Cache::put("batch_{$batchId}_batches", $totalBatches, now()->addDay());

            return response()->json([
                'success' => true,
                'batch_id' => $batchId,
                'total_files' => $totalFiles,
                'batch_size' => $this->batchSize,
                'total_batches' => $totalBatches,
                'download_urls' => [
                    'batch_format' => url("/cfdi/batch/download/{batch_index}?batch_id={$batchId}"),
                    'all_batches_zip' => url("/cfdi/batch/download-all?batch_id={$batchId}"),
                    'status' => url("/cfdi/batch/status/{$batchId}")
                ],
                'batches' => range(0, $totalBatches - 1)
            ]);

        } catch (\Exception $e) {
            Log::error('Error iniciando batch: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 2. DESCARGAR BATCH ESPECÍFICO (índice 0, 1, 2...)
     */
    public function downloadBatch(Request $request, $batchIndex)
    {
        $batchId = $request->get('batch_id');

        if (!$batchId || !Cache::has("batch_{$batchId}_files")) {
            return response()->json([
                'success' => false,
                'message' => 'Batch ID inválido o expirado. Inicie nuevo batch con /cfdi/batch/init'
            ], 400);
        }

        try {
            $allFiles = Cache::get("batch_{$batchId}_files");
            $totalFiles = Cache::get("batch_{$batchId}_total");

            // Calcular rango para este batch
            $start = $batchIndex * $this->batchSize;
            $end = min($start + $this->batchSize, $totalFiles);

            if ($start >= $totalFiles) {
                return response()->json([
                    'success' => false,
                    'message' => 'Índice de batch fuera de rango'
                ], 404);
            }

            $batchFiles = array_slice($allFiles, $start, $this->batchSize);

            // Generar CSV en streaming
            $filename = "cfdi_batch_{$batchIndex}_" . date('Y-m-d_H-i-s') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, must-revalidate'
            ];

            $callback = function() use ($batchFiles, $batchIndex, $start, $end) {
                $output = fopen('php://output', 'w');

                // Headers CSV
                fputcsv($output, [
                    'Batch_Index', 'File_Index', 'Nombre_Archivo', 'UUID', 'RFC_Receptor',
                    'Nombre_Receptor', 'Total', 'Fecha', 'Moneda', 'RFC_Emisor',
                    'Nombre_Emisor', 'Tipo_Comprobante', 'Subtotal', 'IVA',
                    'Version_TFD', 'Fecha_Timbrado', 'Carpeta'
                ]);

                $fileCounter = $start;

                foreach ($batchFiles as $fileInfo) {
                    $cfdiData = $this->extractCfdiDataLight($fileInfo['full_path']);

                    if ($cfdiData) {
                        fputcsv($output, [
                            $batchIndex,
                            $fileCounter++,
                            $fileInfo['filename'],
                            $cfdiData['UUID'] ?? '',
                            $cfdiData['RFC_Receptor'] ?? '',
                            $this->cleanString($cfdiData['Nombre_Receptor'] ?? ''),
                            $cfdiData['Total'] ?? '0',
                            $cfdiData['Fecha'] ?? '',
                            $cfdiData['Moneda'] ?? '',
                            $cfdiData['RFC_Emisor'] ?? '',
                            $this->cleanString($cfdiData['Nombre_Emisor'] ?? ''),
                            $cfdiData['TipoDeComprobante'] ?? '',
                            $cfdiData['SubTotal'] ?? '0',
                            $cfdiData['TotalImpuestosTrasladados'] ?? '0',
                            $cfdiData['Version_TFD'] ?? '',
                            $cfdiData['FechaTimbrado'] ?? '',
                            $fileInfo['folder']
                        ]);
                    } else {
                        // Registrar archivo inválido
                        fputcsv($output, [
                            $batchIndex, $fileCounter++, $fileInfo['filename'],
                            'ERROR', 'ERROR', 'Archivo XML inválido o no es CFDI',
                            '0', '', '', '', '', '', '0', '0', '', '', $fileInfo['folder']
                        ]);
                    }

                    // Liberar memoria cada 100 archivos
                    if ($fileCounter % 100 === 0) {
                        flush();
                    }
                }

                fclose($output);
            };

            return new StreamedResponse($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error("Error generando batch {$batchIndex}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 3. DESCARGAR TODOS LOS BATCHES COMO ZIP
     */
    public function downloadAllBatches(Request $request)
    {
        $batchId = $request->get('batch_id');

        if (!$batchId || !Cache::has("batch_{$batchId}_files")) {
            return response()->json([
                'success' => false,
                'message' => 'Batch ID inválido o expirado'
            ], 400);
        }

        try {
            $allFiles = Cache::get("batch_{$batchId}_files");
            $totalBatches = Cache::get("batch_{$batchId}_batches");

            $zipFilename = "cfdi_complete_{$batchId}_" . date('Y-m-d_H-i-s') . '.zip';
            $zipPath = storage_path("app/temp/{$zipFilename}");

            // Crear directorio temporal si no existe
            if (!File::isDirectory(storage_path('app/temp'))) {
                File::makeDirectory(storage_path('app/temp'), 0755, true);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \Exception('No se pudo crear archivo ZIP');
            }

            // Procesar por batches y agregar al ZIP
            for ($i = 0; $i < $totalBatches; $i++) {
                $csvContent = $this->generateBatchContent($allFiles, $i);
                $csvFilename = "batch_{$i}_of_{$totalBatches}.csv";
                $zip->addFromString($csvFilename, $csvContent);

                // Liberar memoria
                unset($csvContent);
                gc_collect_cycles();
            }

            $zip->close();

            // Retornar ZIP y luego eliminarlo
            return response()->download($zipPath, $zipFilename, [
                'Content-Type' => 'application/zip',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Error creando ZIP: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 4. STREAMING DIRECTO: Para descargas masivas sin batch ID
     */
    public function streamLargeCsv(Request $request)
    {
        $folder = $request->get('folder');
        $rfc = $request->get('rfc');

        $filename = "cfdi_streaming_" . date('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($folder, $rfc) {
            $output = fopen('php://output', 'w');

            // Headers
            fputcsv($output, [
                'UUID', 'RFC_Receptor', 'Nombre_Receptor', 'Total', 'Fecha',
                'RFC_Emisor', 'Nombre_Emisor', 'Tipo_Comprobante', 'Archivo'
            ]);

            // Usar Generador para lazy loading
            $fileGenerator = $this->getXmlFilesGenerator($folder);
            $count = 0;

            foreach ($fileGenerator as $fileInfo) {
                // Filtro opcional por RFC
                if ($rfc) {
                    $data = $this->extractCfdiDataLight($fileInfo['full_path']);
                    if (!$data || strtoupper($data['RFC_Receptor'] ?? '') !== strtoupper($rfc)) {
                        continue;
                    }
                } else {
                    $data = $this->extractCfdiDataLight($fileInfo['full_path']);
                }

                if ($data) {
                    fputcsv($output, [
                        $data['UUID'],
                        $data['RFC_Receptor'],
                        $data['Nombre_Receptor'],
                        $data['Total'],
                        $data['Fecha'],
                        $data['RFC_Emisor'],
                        $data['Nombre_Emisor'],
                        $data['TipoDeComprobante'],
                        $fileInfo['filename']
                    ]);
                }

                $count++;

                // Flush cada 500 registros para evitar timeout
                if ($count % 500 === 0) {
                    flush();
                    ob_flush();
                }
            }

            fclose($output);
        };

        return new StreamedResponse($callback, 200, $headers);
    }

    /**
     * 5. DESCARGAR POR RANGO DE FECHAS
     */
    public function downloadByDateRange(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $filename = "cfdi_{$startDate}_to_{$endDate}.csv";

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($startDate, $endDate) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['UUID', 'RFC_Receptor', 'Nombre_Receptor', 'Total', 'Fecha', 'Archivo']);

            $generator = $this->getXmlFilesGenerator();

            foreach ($generator as $fileInfo) {
                $data = $this->extractCfdiDataLight($fileInfo['full_path']);

                if ($data && isset($data['Fecha'])) {
                    $fileDate = substr($data['Fecha'], 0, 10); // YYYY-MM-DD

                    if ($fileDate >= $startDate && $fileDate <= $endDate) {
                        fputcsv($output, [
                            $data['UUID'],
                            $data['RFC_Receptor'],
                            $data['Nombre_Receptor'],
                            $data['Total'],
                            $data['Fecha'],
                            $fileInfo['filename']
                        ]);
                    }
                }
            }

            fclose($output);
        };

        return new StreamedResponse($callback, 200, $headers);
    }

    // ============================================
    // MÉTODOS AUXILIARES OPTIMIZADOS
    // ============================================

    /**
     * Generador PHP para lazy loading de archivos (bajo consumo de memoria)
     */
    protected function getXmlFilesGenerator($folder = null)
    {
        $baseFolder = $folder ? $this->xmlFolder . '/' . $folder : $this->xmlFolder;

        if (!File::isDirectory($baseFolder)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseFolder, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'xml') {
                yield [
                    'filename' => $file->getFilename(),
                    'full_path' => $file->getPathname(),
                    'folder' => str_replace($this->xmlFolder . '/', '', $file->getPath())
                ];
            }
        }
    }

    /**
     * Escanear solo paths (sin cargar contenido)
     */
    protected function scanXmlFiles()
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->xmlFolder, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'xml') {
                $files[] = [
                    'filename' => $file->getFilename(),
                    'full_path' => $file->getPathname(),
                    'folder' => str_replace($this->xmlFolder . '/', '', $file->getPath())
                ];
            }
        }

        return $files;
    }

    /**
     * Extracción ligera de datos CFDI (solo campos esenciales)
     */
    protected function extractCfdiDataLight($filePath)
    {
        try {
            if (!File::exists($filePath)) return null;

            libxml_use_internal_errors(true);

            // Usar XMLReader para archivos grandes (streaming)
            $reader = new \XMLReader();
            if (!$reader->open($filePath)) {
                return null;
            }

            $data = [];
            $inComprobante = false;
            $inEmisor = false;
            $inReceptor = false;
            $inImpuestos = false;

            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $name = $reader->name;

                    // Comprobante
                    if (strpos($name, 'Comprobante') !== false) {
                        $data['Fecha'] = $reader->getAttribute('Fecha');
                        $data['TipoDeComprobante'] = $reader->getAttribute('TipoDeComprobante');
                        $data['Moneda'] = $reader->getAttribute('Moneda');
                        $data['Total'] = $reader->getAttribute('Total');
                        $data['SubTotal'] = $reader->getAttribute('SubTotal');
                    }

                    // Emisor
                    if (strpos($name, 'Emisor') !== false) {
                        $data['RFC_Emisor'] = $reader->getAttribute('Rfc');
                        $data['Nombre_Emisor'] = $reader->getAttribute('Nombre');
                    }

                    // Receptor
                    if (strpos($name, 'Receptor') !== false) {
                        $data['RFC_Receptor'] = $reader->getAttribute('Rfc');
                        $data['Nombre_Receptor'] = $reader->getAttribute('Nombre');
                    }

                    // Impuestos
                    if (strpos($name, 'Impuestos') !== false) {
                        $data['TotalImpuestosTrasladados'] = $reader->getAttribute('TotalImpuestosTrasladados');
                    }

                    // TimbreFiscalDigital
                    if (strpos($name, 'TimbreFiscalDigital') !== false) {
                        $data['UUID'] = $reader->getAttribute('UUID');
                        $data['FechaTimbrado'] = $reader->getAttribute('FechaTimbrado');
                        $data['Version_TFD'] = $reader->getAttribute('Version');
                    }
                }
            }

            $reader->close();
            return $data;

        } catch (\Exception $e) {
            Log::warning("Error leyendo {$filePath}: " . $e->getMessage());
            return null;
        }
    }

    protected function cleanString($string)
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ' ', $string)));
    }

    protected function generateBatchContent($allFiles, $batchIndex)
    {
        $start = $batchIndex * $this->batchSize;
        $batchFiles = array_slice($allFiles, $start, $this->batchSize);

        ob_start();
        $output = fopen('php://output', 'w');

        fputcsv($output, ['UUID', 'RFC_Receptor', 'Nombre_Receptor', 'Total', 'Fecha', 'Archivo']);

        foreach ($batchFiles as $fileInfo) {
            $data = $this->extractCfdiDataLight($fileInfo['full_path']);
            if ($data) {
                fputcsv($output, [
                    $data['UUID'],
                    $data['RFC_Receptor'],
                    $data['Nombre_Receptor'],
                    $data['Total'],
                    $data['Fecha'],
                    $fileInfo['filename']
                ]);
            }
        }

        fclose($output);
        return ob_get_clean();
    }

    public function getStatus($batchId)
    {
        if (!Cache::has("batch_{$batchId}_files")) {
            return response()->json([
                'success' => false,
                'message' => 'Batch no encontrado o expirado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'batch_id' => $batchId,
            'total_files' => Cache::get("batch_{$batchId}_total"),
            'total_batches' => Cache::get("batch_{$batchId}_batches"),
            'expires_at' => now()->addDay()->toDateTimeString()
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class XMLController2 extends Controller
{
    protected $xmlFolder;
    protected $allowedExtensions = ['xml'];
    protected $cfdiNamespace = 'http://www.sat.gob.mx/cfd/4';
    protected $tfdNamespace = 'http://www.sat.gob.mx/TimbreFiscalDigital';

    public function __construct()
    {
        // Configurar la carpeta de XML (para CFDI 4.0)
        //xml_files/FACTURAS/bimbo_gs/Vigencia 2025
        $this->xmlFolder = storage_path('app/todosXML');

        // Crear la carpeta si no existe
        if (!File::isDirectory($this->xmlFolder)) {
            File::makeDirectory($this->xmlFolder, 0775, true, true);
        }
    }

    /**
     * Método index que genera y descarga resumen CSV
     */
    public function index(Request $request)
    {
        set_time_limit(0); // 0 = sin límite
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '4G'); // Aumentar memoria también


        try {
            $format = $request->get('format', 'json'); // 'json' o 'csv'

            if ($format === 'csv') {
                return $this->generateSummaryCsv();
            }

            // Formato JSON por defecto
            $files = $this->getXmlFiles();
            $summary = $this->generateSummaryData();

            return response()->json([
                'success' => true,
                'total_files' => count($files),
                'summary_available' => true,
                'summary_count' => count($summary['data']),
                'download_csv' => url('/cfdi?format=csv'),
                'summary' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar archivos XML',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generar y descargar resumen en CSV
     */
    protected function generateSummaryCsv()
    {
        set_time_limit(300);
        ini_set('max_execution_time', 300);

        try {
            $summary = $this->generateSummaryData();

            if (empty($summary['data'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron datos CFDI para exportar'
                ], 404);
            }

            // Encabezados del CSV (sin duplicados)
            $headers = [
                'Nombre de Archivo',
                'UUID',
                'RFC Receptor',
                'Nombre Receptor',
                'Total',
                'Fecha',
                'Moneda',
                'RFC Emisor',
                'Nombre Emisor',
                'Tipo Comprobante',
                'Subtotal',
                'IVA',
                'Version TFD',
                'Fecha Timbrado',
                'RFC Proveedor Certif',
                'Sello CFD',
                'No Certificado SAT',
                'Sello SAT',
                'Carpeta'
            ];

            // Crear contenido CSV
            $csvContent = $this->arrayToCsv($summary['data'], $headers);

            // Nombre del archivo con timestamp
            $filename = 'resumen_cfdi_' . date('Y-m-d_H-i-s') . '.csv';

            return response($csvContent, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0'
            ]);

        } catch (\Exception $e) {
            Log::error('Error generando CSV: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al generar archivo CSV',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generar datos del resumen
     */
    protected function generateSummaryData()
    {
        $files = $this->getXmlFiles();
        $summaryData = [];
        $totalAmount = 0;
        $validFiles = 0;

        foreach ($files as $file) {
            $filePath = $file['full_path'];
            $cfdiData = $this->extractCfdiData($filePath);

            if ($cfdiData &&
                isset($cfdiData['UUID']) &&
                isset($cfdiData['RFC_Receptor']) &&
                isset($cfdiData['Nombre_Receptor']) &&
                isset($cfdiData['Total'])) {

                $validFiles++;

                // Sumar al total general
                $totalAmount += floatval($cfdiData['Total'] ?? 0);

                // Extraer datos del complemento de manera segura
                $complemento = $cfdiData['Complemento'] ?? [];

                // Agregar datos al resumen
                $summaryData[] = [
                    'filename' => $file['filename'],
                    'uuid' => $cfdiData['UUID'] ?? '',
                    'rfc_receptor' => $cfdiData['RFC_Receptor'] ?? '',
                    'nombre_receptor' => $this->cleanString($cfdiData['Nombre_Receptor'] ?? ''),
                    'total' => number_format(floatval($cfdiData['Total'] ?? 0), 2, '.', ''),
                    'fecha' => $cfdiData['Fecha'] ?? '',
                    'moneda' => $cfdiData['Moneda'] ?? '',
                    'rfc_emisor' => $cfdiData['RFC_Emisor'] ?? '',
                    'nombre_emisor' => $this->cleanString($cfdiData['Nombre_Emisor'] ?? ''),
                    'tipo_comprobante' => $cfdiData['TipoDeComprobante'] ?? '',
                    'subtotal' => $cfdiData['SubTotal'] ?? '0',
                    'iva' => $cfdiData['TotalImpuestosTrasladados'] ?? '0',
                    'Version' => $complemento['Version'] ?? '',
                    'FechaTimbrado' => $complemento['FechaTimbrado'] ?? '',
                    'RfcProvCertif' => $complemento['RfcProvCertif'] ?? '',
                    'SelloCFD' => $complemento['SelloCFD'] ?? '',
                    'NoCertificadoSAT' => $complemento['NoCertificadoSAT'] ?? '',
                    'SelloSAT' => $complemento['SelloSAT'] ?? '',
                    'folder' => $file['folder'],
                ];
            }
        }

        return [
            'total_files' => count($files),
            'valid_cfdi_files' => $validFiles,
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'data' => $summaryData
        ];
    }

    /**
     * Convertir array a CSV
     */
    protected function arrayToCsv($data, $headers = null)
    {
        ob_start();
        $output = fopen('php://output', 'w');

        // Si se proporcionan headers, usarlos
        if ($headers) {
            $safeHeaders = array_map(function($header) {
                return $this->csvEscape($header);
            }, $headers);
            fputcsv($output, $safeHeaders);
        }

        // Agregar datos
        foreach ($data as $row) {
            $csvRow = [
                $this->csvEscape($row['filename'] ?? ''),
                $this->csvEscape($row['uuid'] ?? ''),
                $this->csvEscape($row['rfc_receptor'] ?? ''),
                $this->csvEscape($row['nombre_receptor'] ?? ''),
                $this->csvEscape($row['total'] ?? '0.00'),
                $this->csvEscape($row['fecha'] ?? ''),
                $this->csvEscape($row['moneda'] ?? ''),
                $this->csvEscape($row['rfc_emisor'] ?? ''),
                $this->csvEscape($row['nombre_emisor'] ?? ''),
                $this->csvEscape($row['tipo_comprobante'] ?? ''),
                $this->csvEscape($row['subtotal'] ?? '0'),
                $this->csvEscape($row['iva'] ?? '0'),
                $this->csvEscape($row['Version'] ?? ''),
                $this->csvEscape($row['FechaTimbrado'] ?? ''),
                $this->csvEscape($row['RfcProvCertif'] ?? ''),
                $this->csvEscape($row['SelloCFD'] ?? ''),
                $this->csvEscape($row['NoCertificadoSAT'] ?? ''),
                $this->csvEscape($row['SelloSAT'] ?? ''),
                $this->csvEscape($row['folder'] ?? '')
            ];

            fputcsv($output, $csvRow);
        }

        fclose($output);
        return ob_get_clean();
    }

    /**
     * Escapar caracteres para CSV
     */
    protected function csvEscape($value)
    {
        // Si el valor contiene comas, comillas o saltos de línea, encerrar en comillas
        if (preg_match('/[,"\n\r]/', $value)) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    /**
     * Limpiar cadena de texto
     */
    protected function cleanString($string)
    {
        // Eliminar múltiples espacios
        $string = preg_replace('/\s+/', ' ', $string);
        // Eliminar caracteres especiales problemáticos
        $string = str_replace(["\r", "\n", "\t"], ' ', $string);
        // Trim
        return trim($string);
    }

    /**
     * Extraer información específica de CFDI
     */
    public function extractCfdiInfo()
    {
        try {
            $files = $this->getXmlFiles();
            $results = [];

            foreach ($files as $file) {
                $filePath = $file['full_path'];
                $info = $this->extractCfdiData($filePath);

                if ($info) {
                    $results[] = array_merge([
                        'filename' => $file['filename'],
                        'file_info' => $file
                    ], $info);
                }
            }

            return response()->json([
                'success' => true,
                'total_files' => count($files),
                'files_with_cfdi' => count($results),
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al extraer información CFDI',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar por UUID específico
     */
    public function findByUuid($uuid)
    {
        try {
            $files = $this->getXmlFiles();
            $results = [];

            foreach ($files as $file) {
                $filePath = $file['full_path'];
                $info = $this->extractCfdiData($filePath);

                if ($info && isset($info['UUID']) && $info['UUID'] === strtoupper($uuid)) {
                    $results[] = array_merge([
                        'filename' => $file['filename'],
                        'file_info' => $file
                    ], $info);
                    break; // UUID es único, podemos parar al encontrar
                }
            }

            return response()->json([
                'success' => true,
                'uuid' => $uuid,
                'found' => count($results) > 0,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al buscar UUID',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar por RFC receptor
     */
    public function findByRfc($rfc)
    {
        try {
            $files = $this->getXmlFiles();
            $results = [];

            foreach ($files as $file) {
                $filePath = $file['full_path'];
                $info = $this->extractCfdiData($filePath);

                if ($info && isset($info['RFC_Receptor']) &&
                    strtoupper($info['RFC_Receptor']) === strtoupper($rfc)) {
                    $results[] = array_merge([
                        'filename' => $file['filename'],
                        'file_info' => $file
                    ], $info);
                }
            }

            return response()->json([
                'success' => true,
                'rfc' => $rfc,
                'total_found' => count($results),
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al buscar por RFC',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resumen de todos los CFDI
     */
    public function summary()
    {
        try {
            $files = $this->getXmlFiles();
            $summary = [
                'total_files' => count($files),
                'total_amount' => 0,
                'by_rfc' => [],
                'by_month' => []
            ];

            foreach ($files as $file) {
                $filePath = $file['full_path'];
                $info = $this->extractCfdiData($filePath);

                if ($info) {
                    // Sumar totales
                    if (isset($info['Total'])) {
                        $summary['total_amount'] += floatval($info['Total']);
                    }

                    // Agrupar por RFC
                    if (isset($info['RFC_Receptor'])) {
                        $rfc = $info['RFC_Receptor'];
                        if (!isset($summary['by_rfc'][$rfc])) {
                            $summary['by_rfc'][$rfc] = [
                                'count' => 0,
                                'total' => 0,
                                'name' => $info['Nombre_Receptor'] ?? 'No disponible'
                            ];
                        }
                        $summary['by_rfc'][$rfc]['count']++;
                        if (isset($info['Total'])) {
                            $summary['by_rfc'][$rfc]['total'] += floatval($info['Total']);
                        }
                    }

                    // Agrupar por mes (si hay fecha)
                    if (isset($info['Fecha'])) {
                        $date = \DateTime::createFromFormat('Y-m-d\TH:i:s', $info['Fecha']);
                        if ($date) {
                            $monthYear = $date->format('Y-m');
                            if (!isset($summary['by_month'][$monthYear])) {
                                $summary['by_month'][$monthYear] = [
                                    'count' => 0,
                                    'total' => 0
                                ];
                            }
                            $summary['by_month'][$monthYear]['count']++;
                            if (isset($info['Total'])) {
                                $summary['by_month'][$monthYear]['total'] += floatval($info['Total']);
                            }
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'summary' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar resumen',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extraer información específica de un CFDI
     */
    protected function extractCfdiData($filePath)
    {
        try {
            if (!File::exists($filePath)) {
                return null;
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($filePath);

            if ($xml === false) {
                Log::warning("Archivo XML inválido: $filePath");
                return null;
            }

            // Registrar namespaces para CFDI 4.0
            $xml->registerXPathNamespace('cfdi', $this->cfdiNamespace);
            $xml->registerXPathNamespace('tfd', $this->tfdNamespace);

            $data = [];

            // 1. Datos del Comprobante
            $comprobante = $xml->xpath('//cfdi:Comprobante');
            if (!empty($comprobante)) {
                $comprobante = $comprobante[0];
                $data['Serie'] = (string)($comprobante['Serie'] ?? '');
                $data['Folio'] = (string)($comprobante['Folio'] ?? '');
                $data['Fecha'] = (string)($comprobante['Fecha'] ?? '');
                $data['FormaPago'] = (string)($comprobante['FormaPago'] ?? '');
                $data['MetodoPago'] = (string)($comprobante['MetodoPago'] ?? '');
                $data['TipoDeComprobante'] = (string)($comprobante['TipoDeComprobante'] ?? '');
                $data['Moneda'] = (string)($comprobante['Moneda'] ?? '');
                $data['Total'] = (string)($comprobante['Total'] ?? '0');
                $data['SubTotal'] = (string)($comprobante['SubTotal'] ?? '0');
                $data['Descuento'] = (string)($comprobante['Descuento'] ?? '0');
            }

            // 2. Emisor
            $emisor = $xml->xpath('//cfdi:Emisor');
            if (!empty($emisor)) {
                $emisor = $emisor[0];
                $data['RFC_Emisor'] = (string)($emisor['Rfc'] ?? '');
                $data['Nombre_Emisor'] = (string)($emisor['Nombre'] ?? '');
                $data['RegimenFiscal_Emisor'] = (string)($emisor['RegimenFiscal'] ?? '');
            }

            // 3. Receptor
            $receptor = $xml->xpath('//cfdi:Receptor');
            if (!empty($receptor)) {
                $receptor = $receptor[0];
                $data['RFC_Receptor'] = (string)($receptor['Rfc'] ?? '');
                $data['Nombre_Receptor'] = (string)($receptor['Nombre'] ?? '');
                $data['UsoCFDI'] = (string)($receptor['UsoCFDI'] ?? '');
                $data['RegimenFiscal_Receptor'] = (string)($receptor['RegimenFiscalReceptor'] ?? '');
                $data['DomicilioFiscal_Receptor'] = (string)($receptor['DomicilioFiscalReceptor'] ?? '');
            }

            // 4. Impuestos
            $impuestos = $xml->xpath('//cfdi:Impuestos');
            if (!empty($impuestos)) {
                $impuestos = $impuestos[0];
                $data['TotalImpuestosTrasladados'] = (string)($impuestos['TotalImpuestosTrasladados'] ?? '0');
                $data['TotalImpuestosRetenidos'] = (string)($impuestos['TotalImpuestosRetenidos'] ?? '0');
            }

            // 5. Complemento (TimbreFiscalDigital)
            $tfd = $xml->xpath('//cfdi:Complemento//tfd:TimbreFiscalDigital');
            if (!empty($tfd)) {
                $tfd = $tfd[0];
                $data['Complemento'] = [
                    'Version' => (string)($tfd['Version'] ?? ''),
                    'UUID' => (string)($tfd['UUID'] ?? ''),
                    'FechaTimbrado' => (string)($tfd['FechaTimbrado'] ?? ''),
                    'RfcProvCertif' => (string)($tfd['RfcProvCertif'] ?? ''),
                    'SelloCFD' => (string)($tfd['SelloCFD'] ?? ''),
                    'NoCertificadoSAT' => (string)($tfd['NoCertificadoSAT'] ?? ''),
                    'SelloSAT' => (string)($tfd['SelloSAT'] ?? ''),
                ];

                // También guardar UUID a nivel raíz para fácil acceso
                $data['UUID'] = (string)($tfd['UUID'] ?? '');
            } else {
                $data['Complemento'] = [];
                $data['UUID'] = '';
            }

            // 6. Conceptos
            $conceptos = $xml->xpath('//cfdi:Conceptos//cfdi:Concepto');
            $data['Conceptos'] = [];
            foreach ($conceptos as $concepto) {
                $data['Conceptos'][] = [
                    'ClaveProdServ' => (string)($concepto['ClaveProdServ'] ?? ''),
                    'Cantidad' => (string)($concepto['Cantidad'] ?? '0'),
                    'ClaveUnidad' => (string)($concepto['ClaveUnidad'] ?? ''),
                    'Descripcion' => (string)($concepto['Descripcion'] ?? ''),
                    'ValorUnitario' => (string)($concepto['ValorUnitario'] ?? '0'),
                    'Importe' => (string)($concepto['Importe'] ?? '0')
                ];
            }
            $data['NumConceptos'] = count($data['Conceptos']);

            return $data;

        } catch (\Exception $e) {
            Log::error("Error extrayendo datos CFDI de $filePath: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verificar si un archivo es CFDI válido
     */
    public function verifyCfdi($filename)
    {
        try {
            $filePath = $this->xmlFolder . '/' . $filename;

            if (!File::exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => "Archivo no encontrado"
                ], 404);
            }

            $data = $this->extractCfdiData($filePath);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => "El archivo no es un CFDI válido"
                ]);
            }

            // Validar campos requeridos
            $requiredFields = ['UUID', 'RFC_Receptor', 'Nombre_Receptor', 'Total'];
            $missingFields = [];

            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $missingFields[] = $field;
                }
            }

            $isValid = empty($missingFields);

            return response()->json([
                'success' => true,
                'is_valid_cfdi' => $isValid,
                'missing_fields' => $missingFields,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error verificando CFDI',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exportar datos a CSV (método original simplificado)
     */
    public function exportToCsv()
    {
        try {
            $files = $this->getXmlFiles();
            $csvData = [];

            // Encabezados
            $headers = [
                'UUID',
                'RFC Receptor',
                'Nombre Receptor',
                'Total',
                'Fecha',
                'RFC Emisor',
                'Nombre Emisor',
                'Tipo Comprobante',
                'Moneda',
                'SubTotal',
                'IVA',
                'Archivo'
            ];

            $csvData[] = $headers;

            foreach ($files as $file) {
                $filePath = $file['full_path'];
                $info = $this->extractCfdiData($filePath);

                if ($info) {
                    $row = [
                        $info['UUID'] ?? '',
                        $info['RFC_Receptor'] ?? '',
                        $info['Nombre_Receptor'] ?? '',
                        $info['Total'] ?? '0',
                        $info['Fecha'] ?? '',
                        $info['RFC_Emisor'] ?? '',
                        $info['Nombre_Emisor'] ?? '',
                        $info['TipoDeComprobante'] ?? '',
                        $info['Moneda'] ?? '',
                        $info['SubTotal'] ?? '0',
                        $info['TotalImpuestosTrasladados'] ?? '0',
                        $file['filename']
                    ];

                    $csvData[] = $row;
                }
            }

            // Generar CSV
            $csvContent = '';
            foreach ($csvData as $row) {
                $csvContent .= implode(',', array_map(function($item) {
                    return '"' . str_replace('"', '""', $item) . '"';
                }, $row)) . "\n";
            }

            return response($csvContent, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="cfdi_export_' . date('Y-m-d') . '.csv"'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error exportando a CSV',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métodos auxiliares
     */
    protected function getXmlFiles($folder = null, $recursive = true)
    {
        $files = [];
        $baseFolder = $folder ?? $this->xmlFolder;

        if (!File::isDirectory($baseFolder)) {
            return $files;
        }

        // Obtener todos los elementos en la carpeta
        $items = File::files($baseFolder);

        foreach ($items as $item) {
            $filename = $item->getFilename();

            if ($this->isValidXmlFile($filename)) {
                // Calcular la ruta relativa desde la carpeta base
                $relativePath = str_replace($this->xmlFolder . DIRECTORY_SEPARATOR, '', $item->getPathname());

                $files[] = [
                    'filename' => $filename,
                    'relative_path' => $relativePath,
                    'folder' => dirname($relativePath) === '.' ? 'root' : dirname($relativePath),
                    'full_path' => $item->getPathname(),
                    'size' => $this->formatBytes($item->getSize()),
                    'bytes' => $item->getSize(),
                    'last_modified' => date('Y-m-d H:i:s', $item->getMTime()),
                    'extension' => strtolower(pathinfo($filename, PATHINFO_EXTENSION))
                ];
            }
        }

        // Si es recursivo, buscar en subcarpetas
        if ($recursive) {
            $subdirectories = File::directories($baseFolder);

            foreach ($subdirectories as $subdirectory) {
                $subFiles = $this->getXmlFiles($subdirectory, true);
                $files = array_merge($files, $subFiles);
            }
        }

        // Ordenar por carpeta y luego por fecha (más reciente primero)
        usort($files, function($a, $b) {
            // Primero por carpeta
            if ($a['folder'] !== $b['folder']) {
                return strcmp($a['folder'], $b['folder']);
            }
            // Luego por fecha de modificación (más reciente primero)
            return strtotime($b['last_modified']) - strtotime($a['last_modified']);
        });

        return $files;
    }

    protected function isValidXmlFile($filename)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowedExtensions);
    }

    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

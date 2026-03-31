<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class PagosController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // RUTAS
    // ──────────────────────────────────────────────────────────────────────────

    public function index()
    {
        return view('pagos.index');
    }

    /**
     * Procesa múltiples archivos TXT y guarda el Excel en disco.
     */
    public function convertir(Request $request)
    {
        $request->validate([
            'archivos'   => 'required|array|min:1',
            'archivos.*' => 'required|file|mimes:txt,TXT|max:102400',
            'ruta_excel' => 'nullable|string',
            'modo_hoja'  => 'nullable|in:unica,separada',
        ]);

        $rutaExcel = $request->input('ruta_excel', 'C:\\Users\\Rpalafox\\Downloads\\pagos prueba.xlsx');
        $modoHoja  = $request->input('modo_hoja', 'unica');

        [$spreadsheet, $resumen] = $this->procesarArchivos($request->file('archivos'), $modoHoja);

        $writer = new Xlsx($spreadsheet);
        $writer->save($rutaExcel);

        $totalReg   = array_sum(array_column($resumen, 'registros'));
        $totalMonto = array_sum(array_column($resumen, 'monto_total'));

        return back()->with([
            'success' => "Excel guardado en: {$rutaExcel}",
            'resumen' => $resumen,
            'totales' => ['registros' => $totalReg, 'monto' => $totalMonto],
        ]);
    }

    /**
     * Procesa múltiples archivos TXT y descarga el Excel en el navegador.
     */
    public function descargar(Request $request)
    {

        $request->validate([
            'archivos' => 'required|array',
            'modo_hoja' => 'required|in:unica,separada'
        ]);

        $archivos = $request->file('archivos');
        $modoHoja = $request->input('modo_hoja', 'separada');

        //dd( $request->all() , $archivos , $modoHoja);

        [$spreadsheet, $resumen] = $this->procesarArchivos($archivos, $modoHoja);

        //dd($spreadsheet, $resumen );

        $filename = 'pagos_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // LÓGICA PRINCIPAL
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Itera los archivos subidos, parsea cada uno y construye el Spreadsheet.
     * Retorna [$spreadsheet, $resumen].
     */
    private function procesarArchivos(array $archivos, string $modoHoja): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0); // quitar hoja vacía inicial

        $resumen         = [];
        $todosRegistros  = [];   // solo se usa en modo hoja única

        foreach ($archivos as $archivo) {
            $nombreOriginal = $archivo->getClientOriginalName();
            $registros      = $this->parsearTxt($archivo->getRealPath(), $nombreOriginal);

            $montoTotal = array_sum(array_column($registros, 'monto'));

            $resumen[] = [
                'archivo'    => $nombreOriginal,
                'registros'  => count($registros),
                'monto_total' => $montoTotal,
            ];

            if ($modoHoja === 'separada') {
                // Cada archivo → su propia hoja
                $titulo = $this->sanitizarTituloHoja($nombreOriginal, $spreadsheet);
                $sheet  = $spreadsheet->createSheet();
                $sheet->setTitle($titulo);
                $this->escribirDatos($sheet, $registros, $nombreOriginal, false);
            } else {
                // Acumular para hoja única
                $todosRegistros = array_merge($todosRegistros, $registros);
            }
        }

        // if ($modoHoja === 'unica') {
        //     $sheet = $spreadsheet->createSheet();
        //     $sheet->setTitle('Pagos');
        //     $mostrarColumnaArchivo = count($archivos) > 1;
        //     $this->escribirDatos($sheet, $todosRegistros, null, $mostrarColumnaArchivo);
        // }

        // Hoja de resumen (siempre al inicio)
        $sheetResumen = $spreadsheet->createSheet(0);
        $sheetResumen->setTitle('Resumen');
        $this->escribirResumen($sheetResumen, $resumen);

        $spreadsheet->setActiveSheetIndex(0);

        $spreadsheet->getProperties()
            ->setCreator('Sistema de Pagos')
            ->setTitle('Pagos Nómina - ' . date('d/m/Y H:i'));

        return [$spreadsheet, $resumen];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PARSER TXT (posiciones fijas)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Parsea un archivo TXT de ancho fijo.
     *
     * Posiciones (base 1):
     *  1- 3  Tipo de registro
     *  4     Subtipo
     *  5-10  Registro patronal
     * 11-12  Mes
     * 13-14  Período / Quincena
     * 15-19  Código de departamento
     * 20-21  Tipo de pago
     * 22-28  ID Empleado
     * 29     Signo del monto (+/-)
     * 30-38  Monto en centavos
     * 39-73  Nombre (35 chars)
     * 74-81  Código interno
     * 82-83  Tipo período pago
     * 84-93  NSS
     * 94-108 Espacios
     * 109-116 Fecha (YYYYMMDD)
     * 118-121 Código organización
     * 122-131 NSS secundario
     */
    private function parsearTxt(string $ruta, string $nombreArchivo): array
    {
        $registros = [];

        $handle = fopen($ruta, 'r');
        if ($handle === false) {
            return [];
        }

        while (($linea = fgets($handle)) !== false) {
            $linea = rtrim($linea, "\r\n");

            if (strlen($linea) < 38) {
                continue;
            }

            $signo = substr($linea, 28, 1);
            $monto = (int) substr($linea, 29, 9);
            if ($signo === '-') {
                $monto = -$monto;
            }
            $montoDecimal = round($monto / 100, 2);

            $fechaRaw = (strlen($linea) >= 116) ? substr($linea, 108, 8) : '';
            $fecha    = '';
            if (preg_match('/^\d{8}$/', $fechaRaw)) {
                $fecha = substr($fechaRaw, 0, 4) . '-'
                       . substr($fechaRaw, 4, 2) . '-'
                       . substr($fechaRaw, 6, 2);
            }

             $montoStr = trim(substr($linea, 28, 10)); // +000011552
    // Convertir formato +000011552 a 11552.00
    $montoDecimal = floatval(ltrim($montoStr, '+0')) / 100;

    $fechaStr = trim(substr($linea, 100, 8)); // 20260201
    $fecha = $fechaStr ? substr($fechaStr, 0, 4) . '-' .
                        substr($fechaStr, 4, 2) . '-' .
                        substr($fechaStr, 6, 2) : null;

            $registros[] = [
                 'archivo'      => $nombreArchivo,
                 'cadena'       => trim(substr($linea, 0, 21)),
                 'expediente'   => trim(substr($linea, 22, 6)),       // +000011552
                 'monto'        => trim(substr($linea, 28, 10)),       // +000011552
                 'importe'      => $montoDecimal,                       // 1155.20
                 'nombre'       => trim(substr($linea, 38, 35)),       // CHAVIRA BURROLA, OMAR RENE
                 'folio'        => trim(substr($linea, 79, 9)),        // 10
                 'poliza'       => trim(substr($linea, 121, 15)),     // 00
                 'id_empleado'  => ltrim(substr($linea, 21, 7), '0'),  // 71
                 'monto'        => $montoDecimal,                       // 1155.20
                 'codigo_int'   => trim(substr($linea, 73, 8)),        // 00000132
                 'tipo_periodo' => trim(substr($linea, 81, 2)),        // (vacío)
                 'nss'          => trim(substr($linea, 83, 10)),       // 0323030189
                 'fecha'        => trim(substr($linea, 108, 8)),                             // 2026-02-01
                 'concepto'     => trim(substr($linea, 116 , 1)),
                ];
        }

        fclose($handle);

        return $registros;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ESCRITURA DE HOJAS
    // ──────────────────────────────────────────────────────────────────────────

    private function escribirDatos(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $registros,
        ?string $nombreArchivo,
        bool $mostrarColumnaArchivo
    ): void {
        // Definir columnas dinámicamente según si se muestra columna "Archivo"
        $columnas = [];
        $col = 'A';

        if ($mostrarColumnaArchivo) {
            $columnas[$col++] = ['key' => 'archivo',       'label' => 'Archivo'];
        }


         //$columnas[$col++] = ['key' => 'archivo',  'label' => 'Tipo'];
         //$columnas[$col++] = ['key' => 'cadena', 'label' => 'Cadena'];     // 116400019505100107401
         $columnas[$col++] = ['key' => 'expediente' , 'label' => 'Num. Expediente'];  // +000011552
         $columnas[$col++] = ['key' => 'monto',  'label' => 'Importe'];                     // 1155.20
         $columnas[$col++] = ['key' => 'nombre' , 'label' => 'Nombre'];     // CHAVIRA BURROLA, OMAR RENE
         $columnas[$col++] = ['key' => 'folio', 'label' => 'Folio'];      // 10
         $columnas[$col++] = ['key' => 'fecha',  'label' => 'Fecha'];
         $columnas[$col++] = ['key' => 'concepto' ,  'label' => 'Concepto'];   // 74010
         $columnas[$col++] = ['key' => 'poliza', 'label' => 'Poliza'];
        $colFin = $col;
        $ultimaCol = chr(ord($col) - 1);

        // Subtítulo con nombre de archivo (solo en modo hoja separada)
        $filaInicio = 1;
        if ($nombreArchivo !== null) {
            $sheet->mergeCells('A1:' . $ultimaCol . '1');
            $sheet->setCellValue('A1', $nombreArchivo);
            $sheet->getStyle('A1')->applyFromArray([
                'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF4A5568']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF7FAFC']],
            ]);
            $filaInicio = 2;
        }

        // ── Encabezados ────────────────────────────────────────────────────────
        $filaEncabezado = $filaInicio;
        foreach ($columnas as $c => $def) {
            $sheet->setCellValue($c . $filaEncabezado, $def['label']);
        }

        $rangoEnc = 'A' . $filaEncabezado . ':' . $ultimaCol . $filaEncabezado;
        $sheet->getStyle($rangoEnc)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E79']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']],
            ],
        ]);
        $sheet->getRowDimension($filaEncabezado)->setRowHeight(22);

        // ── Datos ──────────────────────────────────────────────────────────────
        $fila = $filaEncabezado + 1;
        $colMonto = null;

        foreach ($columnas as $c => $def) {
            if ($def['key'] === 'monto') {
                $colMonto = $c;
                break;
            }
        }

        foreach ($registros as $reg) {
            foreach ($columnas as $c => $def) {
                $sheet->setCellValue($c . $fila, $reg[$def['key']] ?? '');
            }

            if ($colMonto) {
                $sheet->getStyle($colMonto . $fila)
                      ->getNumberFormat()
                      ->setFormatCode('"$"#,##0.00');

                if (($reg['monto'] ?? 0) == 0) {
                    $sheet->getStyle($colMonto . $fila)
                          ->getFont()->getColor()->setARGB('FFCC0000');
                }
            }

            // Filas alternadas
            if ($fila % 2 === 0) {
                $sheet->getStyle('A' . $fila . ':' . $ultimaCol . $fila)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE9F0FB']],
                ]);
            }

            $fila++;
        }

        // ── Bordes en datos ────────────────────────────────────────────────────
        $ultimaFila = $fila - 1;
        if ($ultimaFila >= $filaEncabezado + 1) {
            $sheet->getStyle('A' . ($filaEncabezado + 1) . ':' . $ultimaCol . $ultimaFila)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD0D0D0']],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
        }

        // ── Fila de totales ────────────────────────────────────────────────────
        if ($colMonto && $ultimaFila >= $filaEncabezado + 1) {
            $colAntesMonto = chr(ord($colMonto) - 1);
            $sheet->setCellValue($colAntesMonto . $fila, 'TOTAL:');
            $sheet->setCellValue($colMonto . $fila, '=SUM(' . $colMonto . ($filaEncabezado + 1) . ':' . $colMonto . $ultimaFila . ')');
            $sheet->getStyle($colAntesMonto . $fila . ':' . $colMonto . $fila)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF2CC']],
            ]);
            $sheet->getStyle($colMonto . $fila)
                  ->getNumberFormat()->setFormatCode('"$"#,##0.00');
        }

        // ── Anchos de columna ──────────────────────────────────────────────────
        $anchosPorKey = [
            'archivo'      => 30,
            'tipo'         => 8,
            'subtipo'      => 9,
            'reg_patronal' => 14,
            'mes'          => 7,
            'periodo'      => 9,
            'departamento' => 13,
            'tipo_pago'    => 11,
            'id_empleado'  => 12,
            'nombre'       => 38,
            'monto'        => 14,
            'codigo_int'   => 13,
            'tipo_periodo' => 13,
            'nss'          => 13,
            'fecha'        => 13,
            'org'          => 14,
            'nss2'         => 16,
        ];

        foreach ($columnas as $c => $def) {
            $sheet->getColumnDimension($c)
                  ->setWidth($anchosPorKey[$def['key']] ?? 12);
        }

        $sheet->freezePane('A' . ($filaEncabezado + 1));
        $sheet->setAutoFilter('A' . $filaEncabezado . ':' . $ultimaCol . $filaEncabezado);
    }

    private function escribirResumen(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $resumen
    ): void {
        // Título
        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'RESUMEN DE ARCHIVOS PROCESADOS');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Fecha de generación
        $sheet->setCellValue('A2', 'Generado el: ' . date('d/m/Y H:i:s'));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle('A2')->getFont()->getColor()->setARGB('FF718096');

        // Encabezados tabla
        $sheet->setCellValue('A4', '#');
        $sheet->setCellValue('B4', 'Archivo');
        $sheet->setCellValue('C4', 'Registros');
        $sheet->setCellValue('D4', 'Monto Total');
        $sheet->setCellValue('E4', 'Promedio por Registro');

        $sheet->getStyle('A4:E4')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E86C1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']]],
        ]);

        // Datos de resumen
        $fila = 5;
        foreach ($resumen as $i => $r) {
            $promedio = $r['registros'] > 0 ? $r['monto_total'] / $r['registros'] : 0;

            $sheet->setCellValue('A' . $fila, $i + 1);
            $sheet->setCellValue('B' . $fila, $r['archivo']);
            $sheet->setCellValue('C' . $fila, $r['registros']);
            $sheet->setCellValue('D' . $fila, $r['monto_total']);
            $sheet->setCellValue('E' . $fila, round($promedio, 2));

            foreach (['D', 'E'] as $c) {
                $sheet->getStyle($c . $fila)->getNumberFormat()->setFormatCode('"$"#,##0.00');
            }

            if ($fila % 2 === 0) {
                $sheet->getStyle('A' . $fila . ':E' . $fila)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE9F0FB']],
                ]);
            }

            $sheet->getStyle('A' . $fila . ':E' . $fila)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD0D0D0']]],
            ]);

            $fila++;
        }

        // Fila de totales generales
        $totalReg   = array_sum(array_column($resumen, 'registros'));
        $totalMonto = array_sum(array_column($resumen, 'monto_total'));
        $ultimaFila = $fila - 1;

        $sheet->setCellValue('B' . $fila, 'TOTAL GENERAL');
        $sheet->setCellValue('C' . $fila, $totalReg);
        $sheet->setCellValue('D' . $fila, $totalMonto);

        $sheet->getStyle('B' . $fila . ':E' . $fila)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF2CC']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']]],
        ]);
        $sheet->getStyle('D' . $fila)->getNumberFormat()->setFormatCode('"$"#,##0.00');

        // Anchos
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(45);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(22);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // UTILIDADES
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Sanitiza el nombre de archivo para usarlo como título de hoja Excel.
     * Evita duplicados añadiendo un sufijo numérico.
     */
    private function sanitizarTituloHoja(string $nombre, Spreadsheet $spreadsheet): string
    {
        // Quitar extensión y caracteres inválidos para hoja Excel
        $titulo = pathinfo($nombre, PATHINFO_FILENAME);
        //$titulo = preg_replace('/[:\\\/\?\*\[\]]/', '_', $titulo);
        $titulo = substr($titulo, 0, 31);

        // Verificar duplicados
        $titulos = array_map(
            fn($s) => $s->getTitle(),
            $spreadsheet->getAllSheets()
        );

        if (!in_array($titulo, $titulos)) {
            return $titulo;
        }

        $i = 2;
        while (in_array($titulo . '_' . $i, $titulos)) {
            $i++;
        }

        return substr($titulo, 0, 28) . '_' . $i;
    }
}

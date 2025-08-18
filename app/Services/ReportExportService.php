<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ReportExportService
{
    public function exportToExcel(array $reportData): BinaryFileResponse
    {
        // Export report data to Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $numberOfColumns = count($reportData['columns_dimensions_for_excel_file'] ?? []);

        // ------------------- HEADER -------------------
        $lastLetter = Coordinate::stringFromColumnIndex(max(1, $numberOfColumns));

        $sheet->mergeCells('A1:' . $lastLetter . '1');
        $sheet->setCellValue('A1', $reportData['title'] ?? 'Reporte General');
        $sheet->setCellValue('A2', $reportData['date']);

        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_BOTTOM],
        ]);

        $sheet->mergeCells('A2:' . $lastLetter . '2');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP],
        ]);
        // ------------------------------------------------

        // ------------------- SUMMARY ---------------------
        $summary = $reportData['summary'] ?? null;
        $row = 3;
        if ($summary) {
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->setCellValue("A{$row}", $summary['title'] ?? 'Resumen');

            $sheet->getStyle("A{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ltrim($summary['color'] ?? 'eeece1', '#')]],
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $row++;
            foreach ($summary['rows'] ?? [] as $summaryRow) {
                $sheet->setCellValue("A{$row}", $summaryRow['title']);
                $sheet->setCellValue("B{$row}", $summaryRow['value']);
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ltrim($summary['color'] ?? 'eeece1', '#')]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
                ]);
                $sheet->getStyle("B{$row}")->applyFromArray([
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
                ]);
                $row++;
            }

            $sheet->getStyle("A3:B" . ($row - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ]
            ]);
        }

        $row += 1;

        function deepMerge(array $default, array $custom): array
        {
            foreach ($custom as $key => $value) {
                if (isset($default[$key]) && is_array($default[$key]) && is_array($value)) {
                    $default[$key] = deepMerge($default[$key], $value);
                } else {
                    $default[$key] = $value;
                }
            }
            return $default;
        }

        // ------------------- DATA ---------------------
        $writeGroup = function ($group, &$row, $sheet, $depth = 0) use (&$writeGroup, $lastLetter) {
            $columns = $group['content']['columns'] ?? [];

            $sheet->setCellValue("A{$row}", $group['groupLabel']);
            $sheet->mergeCells("A{$row}:{$lastLetter}{$row}");

            $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => ltrim($group['bgColor'] ?? 'eeece1', '#')]
                ],
                'font' => ['bold' => true],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
            ]);
            $row++;

            if (isset($group['content']) && isset($group['content']['rows']) && isset($group['content']['columns'])) {
                $content = $group['content'];
                $columns = $content['columns'] ?? [];

                $colIndex = 1;
                foreach ($columns as $col) {
                    $cell = Coordinate::stringFromColumnIndex($colIndex) . $row;
                    $sheet->setCellValue($cell, $col['name'] ?? '');

                    $headerStyle = deepMerge([
                        'font' => ['bold' => true],
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => ltrim($content['bgColor'] ?? 'EBF1DE', '#')]
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                'color' => ['rgb' => '000000']
                            ]
                        ],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
                    ], $col['style'] ?? []);

                    if (
                        isset($headerStyle['alignment']['horizontal']) &&
                        is_array($headerStyle['alignment']['horizontal'])
                    ) {
                        $headerStyle['alignment']['horizontal'] = $headerStyle['alignment']['horizontal'][0] ?? Alignment::HORIZONTAL_LEFT;
                    }

                    $sheet->getStyle($cell)->applyFromArray($headerStyle);
                    $colIndex++;
                }

                $row++;

                foreach ($content['rows'] as $dataRow) {
                    $colIndex = 1;
                    foreach ($columns as $col) {
                        $key = $col['key'];
                        $cellData = $dataRow[$key] ?? ['value' => null, 'style' => []];
                        $value = $cellData['value'] ?? null;
                        $style = $cellData['style'] ?? [];

                        $cell = Coordinate::stringFromColumnIndex($colIndex) . $row;

                        if (in_array($key, ['imei', 'phone', 'sap_code'])) {
                            $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                        } else {
                            $sheet->setCellValue($cell, $value);
                        }

                        $finalStyle = deepMerge([
                            'borders' => [
                                'allBorders' => [
                                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                    'color' => ['rgb' => '000000']
                                ]
                            ],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
                        ], $style);

                        $sheet->getStyle($cell)->applyFromArray($finalStyle);

                        $colIndex++;
                    }

                    $row++;
                }
            } elseif (isset($group['content'])) {
                foreach ($group['content'] ?? [] as $childGroup) {
                    $writeGroup($childGroup, $row, $sheet, $depth + 1);
                }
            }

            if ($depth === 0) {
                $sheet->mergeCells("A{$row}:{$lastLetter}{$row}");
                $row++;
            }
        };

        foreach ($reportData['data'] ?? [] as $group) {
            $writeGroup($group, $row, $sheet);
        }

        // ------------------ Final styling -------------------
        $sheet->getRowDimension(1)->setRowHeight(28.2);
        $sheet->getRowDimension(2)->setRowHeight(27.6);

        $columnsDimensions = $reportData['columns_dimensions_for_excel_file'] ?? null;
        if ($columnsDimensions) {
            foreach ($columnsDimensions as $col => $width) {
                $sheet->getColumnDimension($col)->setWidth($width);
            }
        }

        $filename = 'Reporte.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $filename);
        (new Xlsx($spreadsheet))->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    public function exportToPDF(array $reportData, $reportId): BinaryFileResponse
    {
        ini_set('max_execution_time', 300);
        ini_set('memory_limit', '1024M');
        $pdf = Pdf::loadView('report_template', compact('reportData', 'reportId'))->setPaper('a4');

        $tempFile = tempnam(sys_get_temp_dir(), 'report') . '.pdf';
        $pdf->save($tempFile);

        return response()->download($tempFile, 'report.pdf')->deleteFileAfterSend(true);
    }
}

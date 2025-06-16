<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Service\ProGpsApiService;
use App\Jobs\ProcessReportJob;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ReportController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}

    public function createAutomaticReport(Request $request) {}

    public function generateOdometerReport(Request $request)
    {
        $date = $request->query('date', now()->format('Y-m-d'));

        // Fetch data
        $trackers = collect($this->apiService->getTrackers()['list']);
        $trackersIds = $trackers->pluck('id');
        $groups = collect($this->apiService->getGroups()['list'])->keyBy('id');

        $GroupedTrackers = $trackers->groupBy(function ($tracker) use ($groups) {
            return $groups[$tracker['group_id']]['title'] ?? 'Grupo Principal';
        })->sortByDesc(function ($trackers) {
            return count($trackers);
        })->map(function ($trackers, $name) use ($groups) {
            $firstTracker = $trackers->first();
            $groupId = $firstTracker['group_id'] ?? null;
            $color = $groups[$groupId]['color'] ?? 'cacaca';
            return [
                'name' => $name,
                'color' => $color,
                'trackers' => array_values($trackers->toArray()),
            ];
        })->values();

        $OdometerReport = $this->apiService->getOdometersOfListOfTrackersInPeriodRange($trackersIds, $date);
        $vehicles = collect($this->apiService->getVehicles()['list'])
            ->where('tracker_id', '!=', null)
            ->keyBy('tracker_id');

        // Create Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // ------------------------ DATE AND TITLE  ---------------------------------
        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'Informe de Odómetro');

        $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
        $bold = $richText->createTextRun("Fecha: ");
        $bold->getFont()->setBold(true);
        $richText->createText(now()->format('d/m/Y h:i A'));
        $sheet->setCellValue('A2', $richText);

        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_BOTTOM],
        ]);

        $sheet->mergeCells('A2:E2');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP],
        ]);
        // --------------------------------------------------------------------------

        // --------------------------- General Summary ------------------------------
        $sheet->mergeCells('A3:B3');
        $sheet->setCellValue('A3', 'Resumen General');

        $sheet->setCellValue('A4', 'Total de Objetos:');
        $totalObjects = $GroupedTrackers->sum(function ($group) {
            return $group['name'] == "Grupo Principal" ? 0 : count($group['trackers']);
        });
        $sheet->setCellValue('B4', $totalObjects);

        $sheet->setCellValue('A5', 'Total Km: ');
        $totalKm = collect($OdometerReport)->sum(function ($odometer) {
            return $odometer['value'] ?? 0;
        });
        $sheet->setCellValue('B5', number_format($totalKm, 2, '.', ','));

        $sheet->getStyle('A4:A5')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'eeece1']
            ],
        ]);
        $sheet->getStyle('A3')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'eeece1']
            ],
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle('A3:B5')->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ],
        ]);
        // ---------------------------------------------------------------------------

        // ----------------------------- RECORDS ------------------------------------
        $row = 7;

        foreach ($GroupedTrackers as $group) {
            // Group Header
            if ($group['name'] === 'Grupo Principal') {
                continue;
            }

            // Split group title and count
            $groupName = $group['name'];
            $groupKm = collect($group['trackers'])->sum(function ($tracker) use ($OdometerReport) {
                return $OdometerReport[$tracker['id']]['value'] ?? 0;
            });
            $vehicleCount = ' (' . count($group['trackers']) . ' Vehículos) (' . number_format($groupKm, 2, '.', ',') . ' Km)';
            $groupTitle = $groupName . $vehicleCount;

            $sheet->setCellValue("A{$row}", $groupTitle);
            $sheet->mergeCells("A{$row}:E{$row}");

            $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
            $bold = $richText->createTextRun($groupName);
            $bold->getFont()->setBold(true);
            $richText->createText($vehicleCount);
            $sheet->setCellValue("A{$row}", $richText);

            // Apply fill and alignment
            $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'eeece1']
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
            ]);
            $row++;

            // Table headers
            $headers = ['Nombre del Objeto', 'Placa (Matrícula)', 'Odómetro en Km', 'Última Actividad', 'Código SAP'];
            $sheet->fromArray($headers, null, "A{$row}");
            $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'EBF1DE']
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
            ]);
            $row++;

            // Trackers in the group
            foreach ($group['trackers'] as $tracker) {
                $vehicle = $vehicles[$tracker['id']] ?? null;
                $odometerData = $OdometerReport[$tracker['id']] ?? null;

                $sheet->fromArray([
                    $tracker['label'] ?? '-',
                    $vehicle['reg_number'] ?? '-',
                    isset($odometerData['value']) ? number_format($odometerData['value'], 2, '.', ',') : '0.00',
                    isset($odometerData['update_time']) ? date('d/m/Y h:i A', strtotime($odometerData['update_time'])) : '-',
                    $vehicle['trailer_reg_number'] ?? '-'
                ], null, "A{$row}");
                $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000']
                        ]
                    ],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
                ]);

                $row++;
            }

            $sheet->mergeCells("A{$row}:E{$row}");
            $row++;
        }
        // --------------------------------------------------------------------

        // Align all data to the left
        $sheet->getStyle("A4:E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // ------------ SET COLUMN WIDTHS AND ROW HEIGHTS ---------------------
        $sheet->getColumnDimension('A')->setWidth(43); // Object name
        $sheet->getColumnDimension('B')->setWidth(20); // Reg_number
        $sheet->getColumnDimension('C')->setWidth(16); // Odometer
        $sheet->getColumnDimension('D')->setWidth(19); // Last activity
        $sheet->getColumnDimension('E')->setWidth(23); // SAP code
        $sheet->getRowDimension(1)->setRowHeight(28.2); // FIRST ROW
        $sheet->getRowDimension(2)->setRowHeight(27.6); // SECOND ROW
        // --------------------------------------------------------------------

        // Download
        $filename = 'Reporte_Odometro_' . date('Y_m_d') . '.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $filename);
        (new Xlsx($spreadsheet))->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    public function getGroupedTrackers(Request $request)
    {
        $trackers = collect($this->apiService->getTrackers()['list'])->keyBy('id');
        $groups = collect($this->apiService->getGroups()['list'])->keyBy('id');

        $groupedTrackers = $trackers->groupBy(function ($tracker) use ($groups) {
            return $groups[$tracker['group_id']]['title'] ?? 'Grupo Principal';
        })->sortByDesc(function ($trackers) {
            return count($trackers);
        })->map(function ($trackers, $name) use ($groups) {
            $firstTracker = $trackers->first();
            $groupId = $firstTracker['group_id'] ?? null;
            $color = $groups[$groupId]['color'] ?? 'cacaca';
            return [
                'id' => $groupId,
                'name' => $name,
                'color' => $color,
                'trackers' => array_values($trackers->toArray()),
            ];
        })->values()->toArray();

        return response()->json($groupedTrackers);
    }

    public function getListOfUsersGeneratedReports(Request $request)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $reports = Report::where('user_id', $userId)->orderBy('created_at', 'desc')->get();

        return response()->json($reports);
    }

    public function processResult(Request $request, $id)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $secret = $request->input('secret');
        $reportData = $request->input('data');

        // if ($secret !== $this->apiService->getSecretKey()) {
        //     return response()->json(['message' => 'Invalid secret key.'], 403);
        // }
        if ($secret !== "xd") {
            return response()->json(['message' => 'Invalid secret key.'], 403);
        }

        if (!$reportData || !is_array($reportData)) {
            return response()->json(['message' => 'Invalid report data.'], 400);
        }

        $report = Report::where('id', $id)->where('user_id', $userId)->first();
        if (!$report) return response()->json(['message' => 'Report not found.'], 404);

        // Update report with the result

        $jsonDir = storage_path('app/reports');
        $jsonPath = $jsonDir . "/report_{$id}.json";
        file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT));

        $report->percent = 100; // Assuming the report is completed
        $report->file_path = $jsonPath;
        $report->save();
    }

    // Get status of report for polling
    public function getStatusOfReport($id)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $report = Report::where('id', $id)->where('user_id', $userId)->first();
        if (!$report) return response()->json(['message' => 'Report not found.'], 404);

        return response()->json([
            'id' => $report->id,
            'percent' => $report->percent,
            'status' => $report->percent < 100 ? 'processing' : 'completed',
        ]);
    }

    // Update report status | Private Endpoint
    public function updateReportStatus(Request $request, $id)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $report = Report::where('id', $id)->where('user_id', $userId)->first();
        if (!$report) return response()->json(['message' => 'Report not found.'], 404);

        $percent = $request->input('percent');
        if (!is_numeric($percent)) {
            return response()->json(['message' => 'Invalid percent value.'], 400);
        }

        $report->percent = (int)$percent;
        $report->save();

        return response()->json(['message' => 'Report status updated successfully.', 'report' => $report]);
    }

    // Retrieve report data
    public function retrieveReport($id)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $report = Report::where('id', $id)->where('user_id', $userId)->first();
        if (!$report) return response()->json(['message' => 'Report not found.'], 404);

        if ($report->percent < 100) {
            return response()->json(['message' => 'Report is still being processed. Please check back later.', 'percent' => $report->percent], 202);
        }

        if ($report->file_path && file_exists($report->file_path)) {
            return response()->json(json_decode(file_get_contents($report->file_path), true));
        }

        return response()->json(['message' => 'Report file not available. Something happened during the creation or storing process.'], 404);
    }

    // Delete report
    public function deleteReport($id)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $report = Report::where('id', $id)->where('user_id', $userId)->first();
        if (!$report) return response()->json(['message' => 'Report not found.'], 404);

        if ($report->file_path && file_exists($report->file_path)) {
            unlink($report->file_path);
        }

        $report->delete();

        return response()->json(['message' => 'Report deleted successfully.']);
    }

    // Generate report
    public function generateReport(Request $request)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $reportTypeId = $request->input('report_type_id') ?? 0;
        if (!in_array($reportTypeId, $this->apiService->validReportTypeIds)) return response()->json(['message' => 'Invalid report type id.'], 400);

        $isValidReport = $this->validateReportPayload($request->input('report_payload'), $reportTypeId);
        if (!$isValidReport) return response()->json(['message' => 'Invalid report payload.'], 400);

        $payload = [
            'user_id' => $userId,
            'title' => $request->input('report_payload')['title'],
            'report_type_id' => $reportTypeId,
            'report_payload' => $request->input('report_payload'),
            'percent' => 0,
            'file_path' => null,
        ];

        $report = Report::create($payload);
        if (!$report) return response()->json(['message' => 'Failed to create report.'], 500);

        $requestData = [
            'headers' => $request->headers->all(),
            'cookies' => $request->cookies->all(),
            'server' => $request->server->all(),
        ];

        ProcessReportJob::dispatch($report, $requestData, $this->apiService->apiKey);

        return response()->json([
            'message' => 'Report is being created. You can check the status later.',
            'report' => $report->toArray(),
        ], 201);
    }

    // Download Report on format XLS
    public function downloadReport(Request $request, $id)
    {
        $format = $request->query('format');
        if (!in_array($format, ['xlsx', 'pdf'])) return response()->json(['message' => 'Invalid format specified.'], 400);

        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $report = Report::where('id', $id)->where('user_id', $userId)->first();
        if (!$report) return response()->json(['message' => 'Report not found.'], 404);

        if ($report->percent === -1) {
            return response()->json(['message' => 'Something happened with the report', 'percent' => $report->percent], 500);
        } else if ($report->percent < 100) {
            return response()->json(['message' => 'Report is still being processed. Please check back later.', 'percent' => $report->percent], 202);
        }

        if ($report->file_path && file_exists($report->file_path)) {
            if ($format === 'xlsx') {
                return $this->exportGenericReportToExcel(json_decode(file_get_contents($report->file_path), true));
            }
        }

        return response()->json(['message' => 'Report file not available. Something happened during the creation or storing process.'], 404);
    }

    public function exportGenericReportToExcel(array $reportData)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // ------------------- HEADER -------------------
        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', $reportData['title'] ?? 'Reporte General');
        $sheet->setCellValue('A2', $reportData['date']);

        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_BOTTOM],
        ]);

        $sheet->mergeCells('A2:E2');
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
        $startDataRow = $row;

        // ------------------- DATA ---------------------
        $writeGroup = function ($group, &$row, $sheet, $depth = 0) use (&$writeGroup) {
            $columns = $group['content']['columns'] ?? [];
            $colCount = count($columns);
            $lastColLetter = Coordinate::stringFromColumnIndex(max(1, $colCount));

            $sheet->setCellValue("A{$row}", $group['groupLabel']);
            $sheet->mergeCells("A{$row}:{$lastColLetter}{$row}");

            $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->applyFromArray([
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

            if (isset($group['content'])) {
                $content = $group['content'];
                $columns = $content['columns'] ?? [];

                $colNames = array_map(fn($col) => $col['name'], $columns);
                $sheet->fromArray($colNames, null, "A{$row}");

                $colCount = count($columns);
                $lastColLetter = Coordinate::stringFromColumnIndex($colCount);

                $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->applyFromArray([
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
                ]);
                $row++;

                foreach ($content['rows'] as $dataRow) {
                    $sheet->fromArray(array_map(fn($col) => $dataRow[$col['key']] ?? '', $columns), null, "A{$row}");
                    $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->applyFromArray([
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                'color' => ['rgb' => '000000']
                            ]
                        ],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
                    ]);
                    $row++;
                }

                $sheet->mergeCells("A{$row}:{$lastColLetter}{$row}");
                $row++;
            }

            foreach ($group['children'] ?? [] as $childGroup) {
                $writeGroup($childGroup, $row, $sheet, $depth + 1);
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

        $filename = 'Reporte_General_' . date('Y_m_d') . '.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $filename);
        (new Xlsx($spreadsheet))->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    // VALIDATION METHODS FOR REPORTS PAYLOADS
    public function validateReportPayload(array $payload,  $reportTypeId)
    {
        switch ($reportTypeId) {
            case 1:
                return $this->validateOdometerReportPayload($payload);
            case 2:
                return $this->validateSpeedupReportPayload($payload);
            case 3:
                return $this->validateNotificationsReportPayload($payload);
            default:
                return false; // Invalid report type
        }
    }

    public function validateNotificationsReportPayload(array $payload)
    {
        $hasFromDate = isset($payload['from']) && !empty($payload['from']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[\+\-]\d{2}:\d{2})?)?$/', $payload['from']);
        $hasToDate = isset($payload['to']) && !empty($payload['to']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[\+\-]\d{2}:\d{2})?)?$/', $payload['to']);
        $hasTitle = isset($payload['title']) && !empty($payload['title']);
        $hasTrackers = (isset($payload['trackers']) && is_array($payload['trackers']) && count($payload['trackers']) > 0) || $payload['trackers'] == "all";
        $hasNotificationsFilter = (isset($payload['notifications']) && is_array($payload['notifications']) && count($payload['notifications']) > 0) || $payload['notifications'] == "all";
        $hasGroups = (isset($payload['groups']) && is_array($payload['groups']) && count($payload['groups']) > 0) || $payload['groups'] == "all";

        return $hasFromDate && $hasToDate && $hasTrackers && $hasTitle && $hasNotificationsFilter && $hasGroups;
    }

    public function validateOdometerReportPayload(array $payload)
    {
        $hasDate = isset($payload['date']) && !empty($payload['date']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[\+\-]\d{2}:\d{2})?)?$/', $payload['date']);
        $hasTrackers = isset($payload['trackers']) && is_array($payload['trackers']) && count($payload['trackers']) > 0;
        $hasTitle = isset($payload['title']) && !empty($payload['title']);

        return $hasDate && $hasTrackers && $hasTitle;
    }

    public function validateSpeedupReportPayload(array $payload)
    {
        $hasFromDate = isset($payload['from']) && !empty($payload['from']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[\+\-]\d{2}:\d{2})?)?$/', $payload['from']);
        $hasToDate = isset($payload['to']) && !empty($payload['to']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[\+\-]\d{2}:\d{2})?)?$/', $payload['to']);
        $min_duration = isset($payload['min_duration']) && is_numeric($payload['min_duration']) && $payload['min_duration'] > 0;
        $allowedSpeed = isset($payload['allowed_speed']) && is_numeric($payload['allowed_speed']) && $payload['allowed_speed'] > 0;
        $hasTrackers = isset($payload['trackers']) && is_array($payload['trackers']) && count($payload['trackers']) > 0;
        $hasTitle = isset($payload['title']) && !empty($payload['title']);

        return $hasToDate && $hasFromDate && $hasTrackers && $hasTitle && $min_duration && $allowedSpeed;
    }
}

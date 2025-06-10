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
        $from = $request->query('from', now()->startOfDay()->format('Y-m-d H:i:s'));
        $to = $request->query('to', now()->endOfDay()->format('Y-m-d H:i:s'));

        $trackers = collect($this->apiService->getTrackers()['list']);
        $trackersIds = $trackers->pluck('id');
        $OdometerReport = $this->apiService->getOdometersOfListOfTrackersInPeriodRange($trackersIds, $from, $to);

        $vehicles = collect($this->apiService->getVehicles()['list'])
            ->where('tracker_id', '!=', null)
            ->keyBy('tracker_id');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header row
        $headers = ['Placa', 'Última Actividad', 'Odómetro', 'Código SAP', 'Nombre'];
        $sheet->fromArray($headers, null, 'A1');

        // Header styling`
        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EBF1DE']
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
        ]);

        // // Create a new array with update times
        $sortedTrackers = $trackers->toArray();

        usort($sortedTrackers, function ($a, $b) use ($OdometerReport) {
            $aTime = $OdometerReport[$a['id']]['update_time'] ?? null;
            $bTime = $OdometerReport[$b['id']]['update_time'] ?? null;

            if ($aTime === null && $bTime === null) return 0;
            if ($aTime === null) return 1;
            if ($bTime === null) return -1;

            return strtotime($aTime) <=> strtotime($bTime);
        });

        // Now loop through sorted trackers
        $row = 2;
        foreach ($sortedTrackers as $tracker) {
            $updateTime = $OdometerReport[$tracker['id']]['update_time'] ?? null;
            $vehicle = $vehicles[$tracker['id']] ?? null;

            if ($updateTime) {
                $sheet->fromArray([
                    $vehicle['reg_number'] ?? "-",
                    $updateTime ? date('m/d/Y h:i:s A', strtotime($updateTime)) : "-",
                    isset($OdometerReport[$tracker['id']]['value']) ? number_format($OdometerReport[$tracker['id']]['value'], 0, '.', '') : "-",
                    ($vehicle['trailer_reg_number'] ?? null) ?: "-",
                    $tracker['label']
                ], null, 'A' . $row);
                $row++;
            }
        }


        // Apply left alignment to all data cells
        $sheet->getStyle('A2:E' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Manually set column widths
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(23);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(17);
        $sheet->getColumnDimension('E')->setWidth(43);

        // Save to temp file and stream response
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

    public function processResult(Request $request)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $reports = Report::where('user_id', $userId)->where('percent', '<', 100)->get();
        if ($reports->isEmpty()) {
            return response()->json(['message' => 'No reports are currently being processed for this user.'], 404);
        }

        return response()->json($reports);
    }

    // Retrieve, generate, download, updateStatus, getStatus reports methods
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

        ProcessReportJob::dispatch($report, $this->apiService);

        return response()->json([
            'message' => 'Report is being created. You can check the status later.',
            'report' => $report->toArray(),
        ], 201);
    }

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
            default:
                return false; // Invalid report type
        }
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

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Service\ProGpsApiService;
use App\Models\Report;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ReportController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}

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

    public function createAutomaticReport(Request $request) {}

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

    public function retrieveReport(Request $request) {}

    public function generateReport(Request $request)
    {
        $userId = $this->apiService->getUserInfo()['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $reportTypeId = $request->input('report_type_id');
        if (!$reportTypeId) return response()->json(['message' => 'Report type id is required.'], 400);

        if (!in_array($reportTypeId, $this->apiService->validReportTypeIds)) return response()->json(['message' => 'Invalid report type id.'], 400);

        $isValidReport = $this->validateReportPayload($request->input('json_payload'), $reportTypeId);
        if (!$isValidReport) return response()->json(['message' => 'Invalid report payload.'], 400);

        $payload = [
            'user_id' => $userId,
            'report_type_id' => $reportTypeId,
            'json_payload' => $request->input('json_payload'),
        ];

        // $reportId = Report::create($payload)->id;
    }

    public function validateReportPayload(array $payload,  $reportTypeId)
    {
        // Implement validation logic based on report type
        switch ($reportTypeId) {
            case 1:
                return $this->validateOdometerReportPayload($payload);
            default:
                return false; // Invalid report type
        }
    }

    public function validateOdometerReportPayload(array $payload)
    {
        return isset($payload['date']) && !empty($payload['date']) &&
            isset($payload['trackers']) && is_array($payload['trackers']) && count($payload['trackers']) > 0;
    }
}

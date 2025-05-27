<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Service\ProGpsApiService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}

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

    public function createAutomaticReport(Request $request) {}

    public function getGroupedTrackers()
    {
        $trackers = collect($this->apiService->getTrackers()['list']);
        $trackersGroups = collect($this->apiService->getGroups()['list']);

        $groupedTrackers = $trackers->groupBy(function ($tracker) use ($trackersGroups) {
            $group = $trackersGroups->firstWhere('id', $tracker['group_id']);
            return $group ? $group['title'] : 'Sin Agrupar';
        });

        $groupedTrackers = $groupedTrackers->sortByDesc(function ($trackers, $group) {
            return count($trackers);
        })->toArray();

        return response()->json($groupedTrackers);
    }
}

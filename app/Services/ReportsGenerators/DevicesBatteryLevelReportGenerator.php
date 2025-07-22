<?php

namespace App\Services\ReportsGenerators;

use Illuminate\Support\Facades\Log;
use App\Services\ProGpsApiService;
use Carbon\Carbon;

class DevicesBatteryLevelReportGenerator
{

    public function generate($report)
    {
        try {
            $hash = $report->user->hash;
            $apiService = new ProGpsApiService($hash);
            $date = $report->report_payload['date'];

            $trackers = collect($apiService->getTrackers()['list'])->filter(function ($tracker) use ($report) {
                return in_array($tracker['id'], $report->report_payload['trackers']);
            });
            $trackersIds = $trackers->pluck('id');
            $vehicles = collect($apiService->getVehicles()['list'])
                ->where('tracker_id', '!=', null)
                ->keyBy('tracker_id');

            $trackersStates = $apiService->getTrackersStates($trackersIds)['states'];
            $trackers = $trackers->filter(function ($tracker) use ($trackersStates) {
                return $trackersStates[$tracker['id']]['connection_status'] !== 'just_registered';
            });

            $groups = collect($apiService->getGroups()['list'])->keyBy('id');

            $enriched = $trackers->map(function ($tracker) use ($trackersStates, $vehicles, $groups) {
                $groupTitle = $groups[$tracker['group_id']]['title'] ?? 'Grupo Principal';
                $groupColor = $groups[$tracker['group_id']]['color'] ?? '#cacaca';

                $vehicle = $vehicles[$tracker['id']] ?? null;

                return [
                    'group_name' => $groupTitle,
                    'group_color' => $groupColor,
                    'tracker_name' => $tracker['label'] ?? '-',
                    'reg_number' => $vehicle['reg_number'] ?? '-',
                    'battery_level' => isset($trackersStates[$tracker['id']]['battery_level']) ? $trackersStates[$tracker['id']]['battery_level'] . '%' : 'N/A',
                    'last_activity' => isset($trackersStates[$tracker['id']]['battery_update']) ? date('d/m/Y h:i A', strtotime($trackersStates[$tracker['id']]['battery_update'])) : '-',
                    'sap_code' => $vehicle['trailer_reg_number'] ?? '-',
                ];
            });

            // Update report status
            $report->percent = 50;
            $report->save();

            // --------------------------------------------------------------------------
            $reportData = [
                'title' => 'Informe de Nivel de Batería',
                'date' => 'Fecha: ' . date('d/m/Y h:i A', strtotime($date)),
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#EFEFEF',
                    'rows' => [
                        [
                            'title' => 'Total de Objetos',
                            'value' => (string) $trackers->count()
                        ],
                    ],
                ],
                'data' => $enriched->groupBy('group_name')->map(function ($rows, $groupName) {
                    return [
                        'groupLabel' => $groupName,
                        'bgColor' => '#C5D9F1',
                        'content' => [
                            'bgColor' => '#f2f2f2',
                            'columns' => [
                                ['name' => 'Nombre del objeto', 'key' => 'tracker_name'],
                                ['name' => 'Placa (Matrícula)', 'key' => 'reg_number'],
                                ['name' => 'Nivel de batería', 'key' => 'battery_level'],
                                ['name' => 'Última Actividad', 'key' => 'last_activity'],
                                ['name' => 'Código SAP', 'key' => 'sap_code'],
                            ],
                            'rows' => $rows->map(function ($r) {
                                return [
                                    'tracker_name' => $r['tracker_name'],
                                    'reg_number' => $r['reg_number'],
                                    'battery_level' => $r['battery_level'],
                                    'last_activity' => $r['last_activity'],
                                    'sap_code' => $r['sap_code'],
                                ];
                            })->values()->toArray()
                        ]
                    ];
                })->values()->toArray(),
                'columns_dimensions_for_excel_file' => [
                    'A' => 43,
                    'B' => 20,
                    'C' => 16,
                    'D' => 19,
                    'E' => 23,
                ],
            ];


            // Save JSON output locally
            $jsonDir = storage_path('app/reports');
            $jsonPath = $jsonDir . "/report_{$report->id}.json";
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Update report status
            $report->file_path = $jsonPath;
            $report->percent = 100;
        } catch (\Throwable $e) {
            Log::error('Error generating odometer report: ' . $e->getMessage(), [
                'exception' => $e,
                'report_id' => $report->id ?? null,
            ]);
            $report->percent = -1;
        } finally {
            $report->save();
        }
    }
}

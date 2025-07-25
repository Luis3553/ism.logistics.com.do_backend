<?php

namespace App\Services\ReportsGenerators;

use App\Services\ProGpsApiService;
use Illuminate\Support\Facades\Log;

class OdometerReportGenerator
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

            $endpoints = [
                ['key' => 'tracker_states', 'params' => ['trackers' => $trackersIds]],
                ['key' => 'vehicles'],
                ['key' => 'groups']
            ];

            $responses = $apiService->fetchBatchRequests($endpoints);

            $OdometerReport = $apiService->getOdometersOfListOfTrackersInPeriodRange($trackersIds, $date);
            $vehicles = collect($responses['vehicles']['list'])
                ->where('tracker_id', '!=', null)
                ->keyBy('tracker_id');

            $trackersStates = $responses['tracker_states']['states'];
            $trackers = $trackers->filter(function ($tracker) use ($trackersStates) {
                return $trackersStates[$tracker['id']]['connection_status'] !== 'just_registered';
            });

            $groups = collect($responses['groups']['list'])->keyBy('id');

            $enriched = $trackers->map(function ($tracker) use ($OdometerReport, $vehicles, $groups) {
                $groupTitle = $groups[$tracker['group_id']]['title'] ?? 'Grupo Principal';
                $groupColor = $groups[$tracker['group_id']]['color'] ?? '#cacaca';

                $vehicle = $vehicles[$tracker['id']] ?? null;
                $odometer = $OdometerReport[$tracker['id']] ?? null;

                return [
                    'group_name' => $groupTitle,
                    'group_color' => $groupColor,
                    'tracker_name' => $tracker['label'] ?? '-',
                    'reg_number' => $vehicle['reg_number'] ?? '-',
                    'odometer' => isset($odometer['value']) ? number_format($odometer['value'], 2, '.', ',') : '0.00',
                    'last_activity' => isset($odometer['update_time']) ? date('d/m/Y h:i A', strtotime($odometer['update_time'])) : '-',
                    'sap_code' => $vehicle['trailer_reg_number'] ?? '-',
                    'odometer_raw' => $odometer['value'] ?? 0,
                ];
            });

            // Update report status
            $report->percent = 50;
            $report->save();

            // --------------------------------------------------------------------------
            $reportData = [
                'title' => 'Informe de Odómetro',
                'date' => 'Fecha: ' . date('d/m/Y h:i A', strtotime($date)),
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#EFEFEF',
                    'rows' => [
                        [
                            'title' => 'Total de Objetos',
                            'value' => (string) $trackers->count()
                        ],
                        [
                            'title' => 'Total Km',
                            'value' => number_format(collect($OdometerReport)->sum('value'), 2, '.', ',')
                        ],
                    ],
                ],
                'data' => $enriched->groupBy('group_name')->map(function ($rows, $groupName) {
                    $totalKm = $rows->sum('odometer_raw');

                    return [
                        'groupLabel' => $groupName . " (" . $rows->count() . " Vehículos) (" . number_format($totalKm, 2, '.', ',') . " Km)",
                        'bgColor' => '#C5D9F1',
                        'content' => [
                            'bgColor' => '#f2f2f2',
                            'columns' => [
                                ['name' => 'Nombre del objeto', 'key' => 'tracker_name'],
                                ['name' => 'Placa (Matrícula)', 'key' => 'reg_number'],
                                ['name' => 'Odómetro en Km', 'key' => 'odometer'],
                                ['name' => 'Última Actividad', 'key' => 'last_activity'],
                                ['name' => 'Código SAP', 'key' => 'sap_code'],
                            ],
                            'rows' => $rows->map(function ($r) {
                                return [
                                    'tracker_name' => ["value" => $r['tracker_name']],
                                    'reg_number' => ["value" => $r['reg_number']],
                                    'odometer' => ["value" => $r['odometer']],
                                    'last_activity' => ["value" => $r['last_activity']],
                                    'sap_code' => ["value" => $r['sap_code']],
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

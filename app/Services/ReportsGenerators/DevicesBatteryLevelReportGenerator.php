<?php

namespace App\Services\ReportsGenerators;

use Illuminate\Support\Facades\Log;
use App\Services\ProGpsApiService;
use Carbon\Carbon;

class DevicesBatteryLevelReportGenerator
{

    public function generate($report)
    {
        $hash = $report->user->hash;
        $apiService = new ProGpsApiService($hash);

        $trackers = collect($apiService->getTrackers()['list'])->filter(function ($tracker) use ($report) {
            return in_array($tracker['id'], $report->report_payload['trackers']);
        });
        $trackersIds = $trackers->pluck('id');

        $endpoints = [
            ['key' => 'tracker_engine_hours_counter', 'params' => ['trackers' => $trackersIds, 'type' => 'engine_hours']],
            ['key' => 'tracker_odometer_counter', 'params' => ['trackers' => $trackersIds, 'type' => 'odometer']],
            ['key' => 'tracker_states', 'params' => ['trackers' => $trackersIds]],
            ['key' => 'vehicles'],
            ['key' => 'groups']
        ];

        $responses = $apiService->fetchBatchRequests($endpoints);

        $vehicles = collect($responses['vehicles']['list'])
            ->where('tracker_id', '!=', null)
            ->keyBy('tracker_id');
        $trackersStates = $responses['tracker_states']['states'];
        $trackers = $trackers->filter(function ($tracker) use ($trackersStates) {
            return $trackersStates[$tracker['id']]['connection_status'] !== 'just_registered';
        });

        $odometers = $responses['tracker_odometer_counter']['value'];
        $engineHours = $responses['tracker_engine_hours_counter']['value'];
        $groups = collect($responses['groups']['list'])->keyBy('id');

        $enriched = $trackers->map(function ($tracker) use ($trackersStates, $vehicles, $groups, $odometers, $engineHours) {
            $groupTitle = $groups[$tracker['group_id']]['title'] ?? 'Grupo Principal';
            $groupColor = $groups[$tracker['group_id']]['color'] ?? '#cacaca';

            $vehicle = $vehicles[$tracker['id']] ?? null;

            return [
                'group_name' => $groupTitle,
                'group_color' => $groupColor,
                'tracker_name' => $tracker['label'] ?? '-',
                'reg_number' => $vehicle['reg_number'] ?? '-',
                'battery_level' => isset($trackersStates[$tracker['id']]['battery_level']) ? $trackersStates[$tracker['id']]['battery_level'] . '%' : 'N/A',
                'odometer' => isset($odometers[$tracker['id']]) ? number_format($odometers[$tracker['id']], 2, '.', ',') : '0.00',
                'engine_hours' => isset($engineHours[$tracker['id']]) ? number_format($engineHours[$tracker['id']], 2, '.', ',') . ' h' : '0.00',
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
            'date' => 'Fecha: ' . date('d/m/Y h:i A', now()->timestamp),
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
                            ['name' => 'Batería', 'key' => 'battery_level'],
                            ['name' => 'Odómetro', 'key' => 'odometer'],
                            ['name' => 'Horas de Motor', 'key' => 'engine_hours'],
                            ['name' => 'Última Actividad', 'key' => 'last_activity'],
                            ['name' => 'Código SAP', 'key' => 'sap_code'],
                        ],
                        'rows' => $rows->map(function ($r) {
                            return [
                                'tracker_name' => ["value" => $r['tracker_name']],
                                'reg_number' => ["value" => $r['reg_number']],
                                'battery_level' => ["value" => $r['battery_level']],
                                'odometer' => ["value" => $r['odometer']],
                                'engine_hours' => ["value" => $r['engine_hours']],
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
                'C' => 7,
                'D' => 11,
                'E' => 14,
                'F' => 19,
                'G' => 11,
            ],
        ];

        return $reportData;
    }
}

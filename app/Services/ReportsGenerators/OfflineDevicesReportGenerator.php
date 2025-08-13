<?php

namespace App\Services\ReportsGenerators;

use App\Services\ProGpsApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OfflineDevicesReportGenerator
{
    public function generate($report)
    {
        $hash = $report->user->hash;
        $apiService = new ProGpsApiService($hash);

        // Fetch & Filter data
        $trackers = collect($apiService->getTrackers()['list'])->filter(function ($tracker) use ($report) {
            return in_array($tracker['id'], $report->report_payload['trackers']);
        });

        $trackersIds = $trackers->pluck('id');

        $endpoints = [
            ['key' => 'tracker_states', 'params' => ['trackers' => $trackersIds]],
            ['key' => 'vehicles'],
            ['key' => 'groups'],
            ['key' => 'tags']
        ];

        $responses = $apiService->fetchBatchRequests($endpoints);

        $vehicles = collect($responses['vehicles']['list'])
            ->where('tracker_id', '!=', null)
            ->keyBy('tracker_id');

        $trackersStates = $responses['tracker_states']['states'];

        $trackers = $trackers->filter(function ($tracker) use ($trackersStates) {
            return $trackersStates[$tracker['id']]['connection_status'] === 'offline';
        });

        $groups = collect($responses['groups']['list'])->keyBy('id');
        $tags = collect($responses['tags']['list'])->keyBy('id');

        $enriched = $trackers->map(function ($tracker) use ($vehicles, $groups, $trackersStates, $tags) {
            $groupTitle = $groups[$tracker['group_id']]['title'] ?? 'Grupo Principal';

            $vehicle = $vehicles[$tracker['id']] ?? null;
            $last_update = $trackersStates[$tracker['id']]['last_update'];

            $tagNames = collect($tracker['tag_bindings'] ?? [])
                ->map(function ($tagObject) use ($tags) {
                    return $tags[$tagObject['tag_id']]['name'] ?? '-';
                })->implode(', ');

            return [
                'group_name' => $groupTitle,
                'tracker_name' => $tracker['label'],
                'reg_number' => $vehicle['reg_number'] ?? "-",
                'sap_code' => $vehicle['trailer_reg_number'] ?? "-",
                'brand_and_model' => $vehicle ? (explode(' ', trim($vehicle['label']))[0] . " " . ($vehicle['model'])) : "-",
                'imei' => $tracker['source']['device_id'],
                'phone' => $this->decodePhoneNumber($tracker['source']['phone']) ?? "-",
                'last_activity' => $last_update ? date('d/m/Y h:i A', strtotime($last_update)) : "-",
                'offline_since' => Carbon::parse($last_update)->locale('es')->diffForHumans(now(), true, false, 7),
                'tags' => $tagNames
            ];
        });

        // Update report status
        $report->percent = 50;
        $report->save();

        // --------------------------------------------------------------------------
        $reportData = [
            'title' => 'Informe de Dispositivos Fuera de Línea',
            'date' => 'Fecha: ' . now()->format('d/m/Y h:i A'),
            'summary' => [
                'title' => 'Resumen General',
                'color' => '#EFEFEF',
                'rows' => [
                    [
                        'title' => 'Total de Objetos',
                        'value' => (string) $enriched->count()
                    ],
                ],
            ],
            'data' => $enriched->groupBy('group_name')->map(function ($rows, $groupName) {
                return [
                    'groupLabel' => $groupName . " (" . $rows->count() . " Vehículos)",
                    'bgColor' => '#C5D9F1',
                    'content' => [
                        'bgColor' => '#f2f2f2',
                        'columns' => [
                            ['name' => 'Nombre del objeto', 'key' => 'tracker_name'],
                            ['name' => 'Placa (Matrícula)', 'key' => 'reg_number'],
                            ['name' => 'Código SAP', 'key' => 'sap_code'],
                            ['name' => 'Marca y Modelo', 'key' => 'brand_and_model'],
                            ['name' => 'IMEI', 'key' => 'imei'],
                            ['name' => 'Teléfono', 'key' => 'phone'],
                            ['name' => 'Última Actividad', 'key' => 'last_activity'],
                            ['name' => 'Tiempo fuera de línea', 'key' => 'offline_since'],
                            ['name' => 'Etiquetas', 'key' => 'tags']
                        ],
                        'rows' => $rows->map(function ($r) {
                            return [
                                'tracker_name' => ["value" => $r['tracker_name']],
                                'reg_number' => ["value" => $r['reg_number']],
                                'sap_code' => ["value" => $r['sap_code']],
                                'brand_and_model' => ["value" => $r['brand_and_model']],
                                'imei' => ["value" => $r['imei']],
                                'phone' => ["value" => $r['phone']],
                                'last_activity' => ["value" => $r['last_activity']],
                                'offline_since' => ["value" => $r['offline_since']],
                                'tags' => ["value" => $r['tags']]
                            ];
                        })->values()->toArray()
                    ]
                ];
            })->values()->toArray(),
            'columns_dimensions_for_excel_file' => [
                'A' => 43,
                'B' => 16,
                'C' => 11,
                'D' => 23,
                'E' => 18,
                'F' => 12,
                'G' => 19,
                'H' => 58,
                'I' => 80,
            ],
        ];

        return $reportData;
    }

    private function decodePhoneNumber($phone)
    {
        $firstPart = substr($phone, 3, 3);
        $secondPart = substr($phone, 0, 3);
        $thirdPart = substr($phone, 8, 2);
        $fourthPart = substr($phone, 6, 2);

        return "{$firstPart}{$secondPart}{$thirdPart}{$fourthPart}";
    }
}

<?php

namespace App\Services\ReportsGenerators;

use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use App\Services\ProGpsApiService;

class EventsReportGenerator
{

    public function generate($report)
    {
        try {
            $hash = $report->user->hash;
            $payload = $report->report_payload;

            // 1. Merge in the new query values
            $params = [
                'from' => $payload['from'],
                'to' => $payload['to'],
                'trackers' => $payload['trackers'],
                'groups' => $payload['groups'],
                'notifications' => $payload['notifications'],
                'groupBy' => $payload['groupBy'],
            ];

            // 3. Call service to get notifications
            $notificationService = new NotificationService($hash);
            $response = $notificationService->getNotifications($params);
            $result = $response->getData(true);

            function normalizeResultForReport($result)
            {
                $columns = [
                    ['name' => 'Nombre del objeto', 'key' => 'name'],
                    ['name' => 'Inicio', 'key' => 'start_date'],
                    ['name' => 'Fin', 'key' => 'end_date'],
                    ['name' => 'Duración', 'key' => 'time'],
                    ['name' => 'Dirección', 'key' => 'address'],
                    ['name' => 'Emergencia', 'key' => 'emergency'],
                ];

                $formatEventRow = function ($event, $name = '-') {
                    return [
                        'name' => $name,
                        'start_date' => $event['start_date'] ?? '-',
                        'end_date' => $event['end_date'] ?? '-',
                        'time' => $event['time'] ?? '-',
                        'address' => $event['address'] ?? '-',
                        'emergency' => !empty($event['emergency']) ? 'Sí' : 'No',
                    ];
                };

                // Format: group → notifications → trackers
                if (isset($result['groups'])) {
                    return collect($result['groups'])->map(function ($group) use ($columns, $formatEventRow) {
                        $totalTrackers = collect($group['notifications'] ?? [])
                            ->pluck('trackers')
                            ->flatten(1)
                            ->count();

                        return [
                            'groupLabel' => ($group['name'] ?? 'Grupo') . " ({$totalTrackers} Alertas)",
                            'bgColor' => '#C5D9F1',
                            'content' => collect($group['notifications'] ?? [])->map(function ($notification) use ($columns, $formatEventRow) {
                                return [
                                    'groupLabel' => $notification['name'] ?? 'Alerta',
                                    'bgColor' => '#DFDFDF',
                                    'content' => [
                                        'bgColor' => '#f2f2f2',
                                        'columns' => $columns,
                                        'rows' => collect($notification['trackers'] ?? [])->map(
                                            fn($tracker) =>
                                            $formatEventRow($tracker, $tracker['name'] ?? '-')
                                        )->toArray(),
                                    ],
                                ];
                            })->toArray(),
                        ];
                    })->toArray();
                }


                // Format: notification → groups → trackers
                if (isset($result['notifications'])) {
                    return collect($result['notifications'])->map(function ($notification) use ($columns, $formatEventRow) {
                        // 🔢 Count total trackers across all groups
                        $totalTrackers = collect($notification['groups'] ?? [])
                            ->pluck('trackers')->flatten(1)->count();

                        return [
                            'groupLabel' => ($notification['name'] ?? 'Alerta') . " ({$totalTrackers} Alertas)",
                            'bgColor' => '#C5D9F1',
                            'content' => collect($notification['groups'] ?? [])->map(function ($group) use ($columns, $formatEventRow) {
                                return [
                                    'groupLabel' => $group['name'] ?? 'Grupo',
                                    'bgColor' => '#DFDFDF',
                                    'content' => [
                                        'bgColor' => '#f2f2f2',
                                        'columns' => $columns,
                                        'rows' => collect($group['trackers'] ?? [])->map(
                                            fn($tracker) =>
                                            $formatEventRow($tracker, $tracker['name'] ?? '-')
                                        )->toArray(),
                                    ],
                                ];
                            })->toArray(),
                        ];
                    })->toArray();
                }


                // Format: tracker → alerts → events
                if (isset($result['trackers'])) {
                    return collect($result['trackers'])->map(function ($tracker) use ($columns, $formatEventRow) {
                        $alerts = collect($tracker['alerts'] ?? []);

                        $totalEvents = $alerts
                            ->pluck('events')
                            ->flatten(1)
                            ->count();

                        return [
                            'groupLabel' => ($tracker['name'] ?? 'Tracker') . " ({$totalEvents} Alertas)",
                            'bgColor' => '#C5D9F1',
                            'content' => $alerts->map(function ($alert) use ($columns, $formatEventRow, $tracker) {
                                return [
                                    'groupLabel' => $alert['name'] ?? 'Alerta',
                                    'bgColor' => '#DFDFDF',
                                    'content' => [
                                        'bgColor' => '#f2f2f2',
                                        'columns' => $columns,
                                        'rows' => collect($alert['events'] ?? [])->map(
                                            fn($event) => $formatEventRow($event, $tracker['name'] ?? '-')
                                        )->toArray(),
                                    ],
                                ];
                            })->toArray(),
                        ];
                    })->toArray();
                }


                return [];
            }

            function extractTrackerNames($result)
            {
                // Format: groups -> notifications -> trackers
                if (isset($result['groups'])) {
                    return collect($result['groups'])->flatMap(function ($group) {
                        return collect($group['notifications'] ?? [])->flatMap(function ($notification) {
                            return collect($notification['trackers'] ?? [])->pluck('name');
                        });
                    });
                }

                // Format: notifications -> groups -> trackers
                if (isset($result['notifications'])) {
                    return collect($result['notifications'])->flatMap(function ($notification) {
                        return collect($notification['groups'] ?? [])->flatMap(function ($group) {
                            return collect($group['trackers'] ?? [])->pluck('name');
                        });
                    });
                }

                // Format: trackers -> alerts -> events
                if (isset($result['trackers'])) {
                    return collect($result['trackers'])->flatMap(function ($tracker) {
                        return collect($tracker['alerts'] ?? [])->flatMap(function ($alert) use ($tracker) {
                            return collect($alert['events'] ?? [])->map(function ($event) use ($tracker) {
                                return $tracker['name'] ?? ($event['name'] ?? null);
                            });
                        });
                    });
                }

                // If unknown format, return empty collection
                return collect();
            }

            // --------------------------------------------------------------------------
            $groupByLabel = "";

            switch ($params['groupBy']) {
                case 'trackers':
                    $groupByLabel = 'objetos';
                    break;
                case 'groups':
                    $groupByLabel = 'grupos';
                    break;
                case 'notifications':
                    $groupByLabel = 'notificaciones';
                    break;
            }

            $trackerNames = extractTrackerNames($result);
            $uniqueTrackerCount = $trackerNames->unique()->count();
            $totalEvents = $trackerNames->count();

            $reportData = [
                'title' => "Informe de alertas - Por $groupByLabel",
                'date' => 'Desde ' . date('d/m/Y h:i A', strtotime($params['from'])) . ' hasta ' . date('d/m/Y h:i A', strtotime($params['to'])),
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#EFEFEF',
                    'rows' => [
                        [
                            'title' => 'Total de objetos',
                            'value' => $uniqueTrackerCount
                        ],
                        [
                            'title' => 'Total de eventos',
                            'value' => $totalEvents
                        ],
                    ],
                ],
                'data' => normalizeResultForReport($result),
                'columns_dimensions_for_excel_file' => [
                    'A' => 43,
                    'B' => 21,
                    'C' => 21,
                    'D' => 10,
                    'E' => 65,
                    'F' => 12,
                ],
            ];

            // 4. Save report
            $jsonDir = storage_path('app/reports');
            $jsonPath = $jsonDir . "/report_{$report->id}.json";
            $report->file_path = $jsonPath;
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $report->percent = 100;
        } catch (\Throwable $e) {
            Log::error('Error generating events report: ' . $e->getMessage(), [
                'exception' => $e,
                'report_id' => $report->id ?? null,
            ]);
            $report->percent = -1;
        } finally {
            $report->save();
        }
    }
}

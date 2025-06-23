<?php

namespace App\Jobs;

use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\Service\ProGpsApiService;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Illuminate\Http\Request;
use App\Http\Controllers\TargetController;
use App\Services\NotificationService;
use Carbon\Carbon;

class ProcessReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $report;
    public $hash;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Report $report, $hash)
    {
        $this->report = $report;
        $this->hash = $hash;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $reportTypeId = $this->report->report_type_id;

        switch ($reportTypeId) {
            case 1:
                $this->generateOdometerReport();
                break;
            case 2:
                $this->generateSpeedupReport();
                break;
            case 3:
                $this->generateEventsReport();
                break;
            case 4:
                $this->generateVehicleInsurancePoliceExpirationReport();
                break;
            default:
                return false;
        }
    }

    public function generateVehicleInsurancePoliceExpirationReport()
    {
        try {
            $payload = $this->report->report_payload;
            $trackersIds = $payload['trackers'];
            $from = $payload['from'];
            $to = $payload['to'];
            $creationDate = Carbon::createFromFormat('Y-m-d H:i:s', $this->report->created_at)->startOfDay();

            $apiService = new ProGpsApiService($this->hash);
            $vehicles = collect($apiService->getVehicles()['list']);
            $vehicles = $vehicles->filter(function ($vehicle) use ($trackersIds) {
                return in_array($vehicle['tracker_id'], $trackersIds);
            })->values();

            $odometers = $apiService->getOdometerOfListOfTrackers($trackersIds)['value'];

            $records = $vehicles->map(function ($vehicle) use ($odometers, $creationDate) {
                $record = [];

                if (isset($vehicle['liability_insurance_valid_till'])) {
                    $dateValidTill = Carbon::createFromFormat('Y-m-d', $vehicle['liability_insurance_valid_till'])->startOfDay();
                    $diff = $dateValidTill->diffInDays($creationDate, false);

                    $record[] = [
                        'id' => $vehicle['tracker_id'],
                        'tracker_label' => $vehicle['tracker_label'],
                        'reg_number' => $vehicle['reg_number'],
                        'odometer' => $odometers[$vehicle['tracker_id']],
                        'insurance_policy_number' => $vehicle['liability_insurance_policy_number'],
                        'insurance_valid_till' => $dateValidTill->format('Y/m/d'),
                        'days_left' =>  $diff < 0 ? abs($diff) : '-',
                        'days_exceeded' => $diff > 0 ? $diff : '-',
                    ];
                }

                if (isset($vehicle['free_insurance_valid_till'])) {
                    $dateValidTill = Carbon::createFromFormat('Y-m-d', $vehicle['free_insurance_valid_till'])->startOfDay();
                    $diff = $dateValidTill->diffInDays($creationDate, false);

                    $record[] = [
                        'id' => $vehicle['tracker_id'],
                        'tracker_label' => $vehicle['tracker_label'],
                        'reg_number' => $vehicle['reg_number'],
                        'odometer' => $odometers[$vehicle['tracker_id']],
                        'insurance_policy_number' => $vehicle['free_insurance_policy_number'],
                        'insurance_valid_till' => $dateValidTill->format('Y/m/d'),
                        'days_left' => $diff < 0 ? abs($diff) : '-',
                        'days_exceeded' => $diff > 0 ? $diff : '-',
                    ];
                }

                return $record;
            })->flatten(1);

            $dateTitle = "";

            if ($from !== null && $to !== null) {
                $records = $records->filter(function ($record) use ($from, $to) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['insurance_valid_till']);
                    return $validTill->between(Carbon::parse($from), Carbon::parse($to));
                });
                $dateTitle = 'Desde ' . date('Y/m/d', strtotime($from)) . ' hasta ' . date('Y/m/d', strtotime($to));
            }
            if ($from == null && isset($to)) {
                $records = $records->filter(function ($record) use ($to) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['insurance_valid_till']);
                    return $validTill->lessThanOrEqualTo(Carbon::parse($to));
                });
                $dateTitle = 'Hasta ' . date('Y/m/d', strtotime($to));
            }
            if ($to == null && isset($from)) {
                $records = $records->filter(function ($record) use ($from) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['insurance_valid_till']);
                    return $validTill->greaterThanOrEqualTo(Carbon::parse($from));
                });
                $dateTitle = 'Desde ' . date('Y/m/d', strtotime($from));
            }

            $dateTitle = empty($dateTitle) ? 'Todos los registros' : $dateTitle;

            $reportData = [
                'title' => 'Informe de Vencimiento de Pólizas de Seguro',
                'date' => $dateTitle,
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#EFEFEF',
                    'rows' => [
                        [
                            'title' => 'Total de Vehículos',
                            'value' => count(collect($records)->pluck('id')->unique())
                        ],
                        [
                            'title' => 'Total de Pólizas de Seguro',
                            'value' => count($records)
                        ],
                        [
                            'title' => 'Total de Pólizas Vencidas',
                            'value' => collect($records)->filter(function ($record) {
                                return $record['days_left'] === '-' && $record['days_exceeded'] !== '-';
                            })->count()
                        ],
                        [
                            'title' => 'Total de Pólizas Vigentes',
                            'value' => collect($records)->filter(function ($record) {
                                return $record['days_exceeded'] === '-';
                            })->count()
                        ],
                    ],
                ],
                'data' => [
                    [
                        'groupLabel' => 'Listado de Vehículos',
                        'bgColor' => '#C5D9F1',
                        'content' => [
                            'bgColor' => '#f2f2f2',
                            'columns' => [
                                ['name' => 'Nombre del objeto', 'key' => 'tracker_label'],
                                ['name' => 'Placa (Matrícula)', 'key' => 'reg_number'],
                                ['name' => 'Odómetro', 'key' => 'odometer'],
                                ['name' => 'Número de Póliza', 'key' => 'insurance_policy_number'],
                                ['name' => 'Fecha de vencimiento', 'key' => 'insurance_valid_till'],
                                ['name' => 'Días restantes', 'key' => 'days_left'],
                                ['name' => 'Días excedidos', 'key' => 'days_exceeded'],
                            ],
                            'rows' => $records->map(function ($record) {
                                return [
                                    'tracker_label' => $record['tracker_label'],
                                    'reg_number' => $record['reg_number'],
                                    'odometer' => number_format($record['odometer'], 2, '.', ','),
                                    'insurance_policy_number' => $record['insurance_policy_number'],
                                    'insurance_valid_till' => $record['insurance_valid_till'],
                                    'days_left' => $record['days_left'],
                                    'days_exceeded' => $record['days_exceeded'],
                                ];
                            })->values()->toArray(),
                        ],
                    ]
                ],
                'columns_dimensions_for_excel_file' => [
                    'A' => 43,
                    'B' => 16,
                    'C' => 14,
                    'D' => 20,
                    'E' => 20,
                    'F' => 13,
                    'G' => 15,
                ],
            ];

            $jsonDir = storage_path('app/reports');
            $jsonPath = $jsonDir . "/report_{$this->report->id}.json";
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->report->file_path = $jsonPath;
            $this->report->percent = 100;
        } catch (\Throwable $e) {
            Log::error('Error generating driver insurance police expiration report: ' . $e->getMessage());
            $this->report->percent = -1;
        } finally {
            $this->report->save();
        }
    }

    public function generateEventsReport()
    {
        try {
            $payload = $this->report->report_payload;

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
            $notificationService = new NotificationService($this->hash);
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
            $jsonPath = $jsonDir . "/report_{$this->report->id}.json";
            $this->report->file_path = $jsonPath;
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->report->percent = 100;
        } catch (\Throwable $e) {
            Log::error('Error generating events report: ' . $e->getMessage(), [
                'exception' => $e,
                'report_id' => $this->report->id ?? null,
            ]);
            $this->report->percent = -1;
        } finally {
            $this->report->save();
        }
    }


    public function generateOdometerReport()
    {
        try {
            $apiService = new ProGpsApiService($this->hash);
            $date = $this->report->report_payload['date'];

            // Fetch & Filter data
            $trackers = collect($apiService->getTrackers()['list'])->filter(function ($tracker) {
                return in_array($tracker['id'], $this->report->report_payload['trackers']);
            });
            $trackersIds = $trackers->pluck('id');

            $trackersStates = $apiService->getTrackersStates($trackersIds)['states'];
            // Filter out trackers that are just registered
            $trackers = $trackers->filter(function ($tracker) use ($trackersStates) {
                return $trackersStates[$tracker['id']]['connection_status'] !== 'just_registered';
            });

            $groups = collect($apiService->getGroups()['list'])->keyBy('id');

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

            // Update report status
            $this->report->percent = 33;
            $this->report->save();

            $OdometerReport = $apiService->getOdometersOfListOfTrackersInPeriodRange($trackersIds, $date);
            $vehicles = collect($apiService->getVehicles()['list'])
                ->where('tracker_id', '!=', null)
                ->keyBy('tracker_id');

            // Update report status
            $this->report->percent = 66;
            $this->report->save();

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
                            'value' => (string) $GroupedTrackers->sum(fn($g) => count($g['trackers'] ?? []))
                        ],
                        [
                            'title' => 'Total Km',
                            'value' => number_format(collect($OdometerReport)->sum('value'), 2, '.', ',')
                        ],
                    ],
                ],
                'data' => $GroupedTrackers->map(function ($group) use ($OdometerReport, $vehicles) {
                    $build = function ($group, $depth = 0) use (&$build, $OdometerReport, $vehicles) {
                        $groupKm = collect($group['trackers'] ?? [])->sum(fn($t) => $OdometerReport[$t['id']]['value'] ?? 0);

                        $node = [
                            'groupLabel' => ($group['name'] ?? 'Grupo') . " (" . count($group['trackers'] ?? []) . ' Vehículos) (' . number_format($groupKm, 2, '.', ',') . ' Km)',
                            'bgColor' => '#C5D9F1',
                        ];

                        if (!empty($group['children'])) {
                            $node['children'] = collect($group['children'])->map(function ($child) use (&$build, $depth) {
                                return $build($child, $depth + 1);
                            })->values()->toArray();
                        } else {
                            $node['content'] = [
                                'bgColor' => '#f2f2f2',
                                'columns' => [
                                    ['name' => 'Nombre del objeto', 'key' => 'name'],
                                    ['name' => 'Placa (Matrícula)', 'key' => 'reg_number'],
                                    ['name' => 'Odómetro en Km', 'key' => 'odometer'],
                                    ['name' => 'Última Actividad', 'key' => 'last_activity'],
                                    ['name' => 'Código SAP', 'key' => 'sap_code'],
                                ],
                                'rows' => collect($group['trackers'])->map(function ($tracker) use ($OdometerReport, $vehicles) {
                                    $vehicle = $vehicles[$tracker['id']] ?? null;
                                    $odometer = $OdometerReport[$tracker['id']] ?? null;

                                    return [
                                        'name' => $tracker['label'] ?? '-',
                                        'reg_number' => $vehicle['reg_number'] ?? '-',
                                        'odometer' => isset($odometer['value']) ? number_format($odometer['value'], 2, '.', ',') : '0.00',
                                        'last_activity' => isset($odometer['update_time']) ? date('d/m/Y h:i A', strtotime($odometer['update_time'])) : '-',
                                        'sap_code' => $vehicle['trailer_reg_number'] ?? '-',
                                    ];
                                })->toArray(),
                            ];
                        }

                        return $node;
                    };

                    return $build($group);
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
            $jsonPath = $jsonDir . "/report_{$this->report->id}.json";
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Update report status
            $this->report->file_path = $jsonPath;
        } catch (\Throwable $e) {
            Log::error('Error generating odometer report: ' . $e->getMessage(), [
                'exception' => $e,
                'report_id' => $this->report->id ?? null,
            ]);
            $this->report->percent = -1;
        } finally {
            $this->report->percent = 100;
            $this->report->save();
        }
    }

    public function generateSpeedupReport()
    {
        $reportId = $this->report->id;
        $trackerIds = $this->report->report_payload['trackers'];
        $fromDate = $this->report->report_payload['from'];
        $toDate = $this->report->report_payload['to'];
        $allowedSpeed = $this->report->report_payload['allowed_speed'];
        $max_duration = $this->report->report_payload['min_duration'];

        $nodeScript = base_path('node-processor/main.js');
        $cmd = [
            'node',
            $nodeScript,
            '--hash',
            $this->hash,
            '--report_id',
            $reportId,
            '--ids',
            implode(',', $trackerIds),
            '--from',
            $fromDate,
            '--to',
            $toDate,
            '--allowed_speed',
            $allowedSpeed,
            '--min_duration',
            $max_duration
        ];

        $process = new Process($cmd, null, ['APP_URL' => env('APP_URL', 'development')]);
        $process->setTimeout(600); // Set a timeout of 10 minutes
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error($process->getErrorOutput(), [
                'report_id' => $reportId,
            ]);
            $this->report->percent = -1;
            $this->report->save();
        }
    }
}

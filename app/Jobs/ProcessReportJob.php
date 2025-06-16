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

class ProcessReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $report;
    public $requestData;
    public $hash;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Report $report, $requestData, $hash)
    {
        $this->report = $report;
        $this->requestData = $requestData;
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
            default:
                return false;
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
            $result = $notificationService->getNotifications($params);

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

            $trackerNames = collect($result)->flatMap(function ($group) {
                return collect($group['notifications'])->flatMap(function ($notification) {
                    return collect($notification['trackers'])->pluck('name');
                });
            });

            $uniqueTrackerCount = $trackerNames->unique()->count();
            $totalEvents = $trackerNames->count();

            $reportData = [
                'title' => "Informe de alertas - Por $groupByLabel",
                'date' => 'Desde ' . date('d/m/Y h:i A', strtotime($params['from'])) . ' hasta ' . date('d/m/Y h:i A', strtotime($params['to'])),
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#eeece1',
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
                'data' => collect($result)->map(function ($group) {
                    $groupLabel = ($group['name'] ?? 'Grupo') . " (" . count($group['notifications'] ?? []) . " Alertas)";

                    return [
                        'groupLabel' => $groupLabel,
                        'bgColor' => '#eeece1',
                        'children' => collect($group['notifications'] ?? [])->map(function ($notification) {
                            $notificationLabel = $notification['name'];

                            return [
                                'groupLabel' => $notificationLabel,
                                'bgColor' => '#f4f4f4',
                                'content' => [
                                    'columns' => [
                                        ['name' => 'Nombre del objeto', 'key' => 'name'],
                                        ['name' => 'Inicio', 'key' => 'start_date'],
                                        ['name' => 'Fin', 'key' => 'end_date'],
                                        ['name' => 'Duración', 'key' => 'time'],
                                        ['name' => 'Dirección', 'key' => 'address'],
                                        ['name' => 'Emergencia', 'key' => 'emergency'],
                                    ],
                                    'rows' => collect($notification['trackers'] ?? [])->map(function ($event) {
                                        return [
                                            'name' => $event['name'] ?? '-',
                                            'start_date' => $event['start_date'] ?? '-',
                                            'end_date' => $event['end_date'] ?? '-',
                                            'time' => $event['time'] ?? '-',
                                            'address' => $event['address'] ?? '-',
                                            'emergency' => $event['emergency'] ? 'Sí' : 'No',
                                        ];
                                    })->toArray(),
                                ],
                            ];
                        })->toArray(),
                    ];
                })->toArray(),
                'columns_dimensions_for_excel_file' => [
                    'A' => 43,
                    'B' => 20,
                    'C' => 16,
                    'D' => 19,
                    'E' => 23,
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
                    'color' => '#eeece1',
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
                            'bgColor' => '#eeece1',
                        ];

                        if (!empty($group['children'])) {
                            $node['children'] = collect($group['children'])->map(function ($child) use (&$build, $depth) {
                                return $build($child, $depth + 1);
                            })->values()->toArray();
                        } else {
                            $node['content'] = [
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

        $process = new Process($cmd);
        $process->setTimeout(600); // Set a timeout of 10 minutes
        $process->run();
    }
}

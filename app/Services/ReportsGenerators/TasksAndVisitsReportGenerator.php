<?php

namespace App\Services\ReportsGenerators;

use App\Services\ProGpsApiService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Log;

class TasksAndVisitsReportGenerator
{
    public function generate($report)
    {
        try {
            $hash = $report->user->hash;
            $apiService = new ProGpsApiService($hash);
            $from = $report->report_payload['from'];
            $to = $report->report_payload['to'];
            $days = $this->getDays($from, $to);

            $trackersIds = $report->report_payload['trackers'];

            $endpoints = [
                ['key' => 'trackers'],
                ['key' => 'tasks', 'params' => ['from' => $from, 'to' => $to, 'types' => ['route', 'task'], 'trackers' => $trackersIds]],
                ['key' => 'groups']
            ];

            $responses = $apiService->fetchBatchRequests($endpoints);

            $trackersMap = collect($responses['trackers']['list'])->keyBy('id');
            $groupsMap = collect($responses['groups']['list'])->keyBy('id');
            $tasks = collect($responses['tasks']['list'])->groupBy('tracker_id');

            $taskCounted = $tasks->map(function ($taskGroup, $trackerId) use ($days, $trackersMap, $groupsMap) {
                return array_merge(
                    [
                        'group' => $groupsMap[$trackersMap[$trackerId]['group_id']]['title'] ?? 'Grupo Principal',
                        'tracker' => $trackersMap[$trackerId]['label'] ?? "-"
                    ],
                    $this->calculateAmountOfAssignedAndDoneTasksForEachDay($taskGroup, $days)
                );
            })->groupBy('group');

            // Update report status
            $report->percent = 50;
            $report->save();

            // --------------------------------------------------------------------------

            $columnsWidths = array_merge([
                ['width' => 20],
                ['width' => 20],
                ['width' => 20],
                ['width' => 20],
            ], array_map(function ($day) {
                return ['width' => 20];
            }, $days));

            $reportData = [
                'sheets' => [
                    [
                        'name' => "Reporte de Tareas y Visitas",
                        'columns' => $columnsWidths,
                        'rows' => [
                            [
                                'height' => 28.2,
                                'cells' => [
                                    [
                                        'value' => 'Informe de Tareas y Visitas',
                                        'colSpan' => count($columnsWidths),
                                        'style' => ['font' => ['bold' => true, 'size' => 16], 'aligment' => ['horizontal' => 'left', 'vertical' => 'bottom']]
                                    ],
                                ]
                            ],
                            [
                                'height' => 27.6,
                                'cells' => [
                                    [
                                        'value' => 'Desde: ' . date('d/m/Y h:i A', strtotime($from)) . ' hasta ' . date('d/m/Y h:i A', strtotime($to)),
                                        'colSpan' => count($columnsWidths),
                                        'style' => ['font' => ['bold' => true, 'size' => 12], 'aligment' => ['horizontal' => 'left', 'vertical' => 'top']]
                                    ]
                                ]
                            ],
                            [
                                'cells' => [
                                    [
                                        'value' => '',
                                        'colSpan' => count($columnsWidths),
                                    ]
                                ]
                            ],
                            [
                                'cells' => [
                                    [
                                        'value' => 'Resumen General',
                                        'colSpan' => 2,
                                        'styles' => ['font' => ['bold' => true]]
                                    ],
                                ]
                            ],
                            [
                                'cells' => [
                                    [
                                        'value' => 'Total de Objetos',
                                        'style' => ['font' => ['bold' => true]]
                                    ],
                                    [
                                        'value' => count($trackersMap),
                                        'style' => ['font' => ['bold' => true]]
                                    ]
                                ]
                            ],
                            [
                                'cells' => [
                                    [
                                        'value' => 'Total de Tareas Asignadas',
                                        'style' => ['font' => ['bold' => true]]
                                    ],
                                    [
                                        'value' => $tasks->sum(function ($taskGroup) {
                                            return count($taskGroup);
                                        }),
                                        'style' => ['font' => ['bold' => true]]
                                    ]
                                ]
                            ],
                            [
                                'cells' => [
                                    [
                                        'value' => 'Total de tareas terminadas',
                                        'style' => ['font' => ['bold' => true]]
                                    ],
                                    [
                                        'value' => count($days),
                                        'style' => ['font' => ['bold' => true]]
                                    ]
                                ]
                            ],
                        ]
                    ]
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

    public function calculateAmountOfAssignedAndDoneTasksForEachDay($tasks, $days)
    {
        $result = [
            'total' => [
                'assigned' => 0,
                'done' => 0,
            ]
        ];

        foreach ($days as $day) {
            $result[$day] = [
                'assigned' => 0,
                'done' => 0,
            ];
        }

        foreach ($tasks as $task) {
            $taskFrom = Carbon::parse($task['from'])->startOfDay();
            $type = $task['type'] ?? 'task';

            foreach ($days as $day) {
                $dayCarbon = Carbon::parse($day)->startOfDay();

                if (!$dayCarbon->is($taskFrom)) {
                    continue;
                }

                if ($type === 'task') {
                    $result[$day]['assigned']++;
                    $result['total']['assigned']++;

                    if ($task['status'] === 'done') {
                        $result[$day]['done']++;
                        $result['total']['done']++;
                    }
                }

                if ($type === 'route' && isset($task['checkpoints'])) {
                    foreach ($task['checkpoints'] as $checkpoint) {
                        $cpFrom = Carbon::parse($checkpoint['from'])->startOfDay();

                        if ($dayCarbon->is($cpFrom)) {
                            $result[$day]['assigned']++;
                            $result['total']['assigned']++;

                            if ($checkpoint['status'] === 'done') {
                                $result[$day]['done']++;
                                $result['total']['done']++;
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }


    public function getDays($from, $to)
    {
        $days = [];
        $period = CarbonPeriod::create($from, $to);

        foreach ($period as $date) {
            $days[] = $date->toDateString();
        }

        return $days;
    }
}

<?php

namespace App\Services\ReportsGenerators;

use App\Services\ProGpsApiService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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

            $responses = $apiService->fetchBatchRequests([
                ['key' => 'trackers'],
                ['key' => 'tasks', 'params' => ['from' => $from, 'to' => $to, 'types' => ['route', 'task'], 'trackers' => $trackersIds]],
                ['key' => 'groups']
            ]);

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

            $report->percent = 50;
            $report->save();

            $reportData = [
                'title' => 'Informe de Visitas',
                'date' => 'Desde: ' . date('d/m/Y h:i A', strtotime($from)) . ' hasta ' . date('d/m/Y h:i A', strtotime($to)),
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#EFEFEF',
                    'rows' => [
                        [
                            'title' => 'Total de Objetos',
                            'value' => (string) $taskCounted->flatten(1)->count()
                        ],
                        [
                            'title' => 'Total de Visitas Asignadas',
                            'value' => (string) $taskCounted
                                ->flatten(1)
                                ->sum(fn($tracker) => $tracker['total']['assigned'] ?? 0),
                        ],
                        [
                            'title' => 'Total de Visitas Completadas',
                            'value' => (string) $taskCounted
                                ->flatten(1)
                                ->sum(fn($tracker) => $tracker['total']['done'] ?? 0),
                        ],
                        [
                            'title' => 'Eficiencia General',
                            'value' => number_format(($taskCounted->flatten(1)->sum(fn($tracker) => $tracker['total']['done'] ?? 0) /
                                $taskCounted->flatten(1)->sum(fn($tracker) => $tracker['total']['assigned'] ?? 0) * 100), 2, '.') . '%',
                        ]
                    ]
                ],
                'data' => $taskCounted->map(function ($trackers, $groupName) use ($days) {
                    return [
                        'groupLabel' => $groupName . " (" . count($trackers) . " Objetos)",
                        'bgColor' => '#C5D9F1',
                        'content' => [
                            'bgColor' => '#f2f2f2',
                            'columns' => array_merge([
                                ['name' => 'Nombre del objeto', 'key' => 'tracker', "style" => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => '% Eficiencia', 'key' => 'efficiency', "style" => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => 'Días', 'key' => 'label', "style" => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => 'Todos', 'key' => 'total', "style" => ['alignment' => ['horizontal' => 'center']]],
                            ], array_map(function ($day) {
                                return ['name' => date('d', strtotime($day)), 'key' => $day, "style" => ['alignment' => ['horizontal' => 'center']]];
                            }, $days)),
                            'rows' => collect($trackers)->flatMap(function ($tracker, $index) use ($days) {
                                $totalAssigned = collect($days)->sum(fn($day) => $tracker[$day]['assigned'] ?? 0);
                                $totalDone = collect($days)->sum(fn($day) => $tracker[$day]['done'] ?? 0);
                                $efficiency = $totalAssigned === 0 ? '0%' : round(($totalDone / $totalAssigned) * 100, 2) . '%';

                                $assignedRow = [
                                    'tracker' => [
                                        "value" => $tracker['tracker']
                                    ],
                                    'efficiency' => [
                                        "value" => $efficiency,
                                        "style" => ['alignment' => ['horizontal' => 'center']]
                                    ],
                                    'label' => [
                                        "value" => 'Asignado',
                                        "style" => ['alignment' => ['horizontal' => 'right'], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => ($index % 2 === 0) ? 'dce6f1' : 'FFFFFF']]]
                                    ],
                                    'total' => [
                                        "value" => $totalAssigned,
                                        "style" => ["font" => ['bold' => true], 'alignment' => ['horizontal' => 'center'], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => ($index % 2 === 0) ? 'dce6f1' : 'FFFFFF']]]
                                    ]
                                ] + collect($days)->mapWithKeys(function ($day) use ($tracker, $index) {
                                    return [
                                        $day => [
                                            "value" => $tracker[$day]['assigned'] ?? 0,
                                            "style" => ['alignment' => ['horizontal' => 'center'], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => ($index % 2 === 0) ? 'dce6f1' : 'FFFFFF']]]
                                        ]
                                    ];
                                })->toArray();

                                $doneRow = [
                                    'tracker' => ["value" => null, "style" => ['alignment' => ['horizontal' => 'center']]],
                                    'efficiency' => ["value" => null, "style" => ['alignment' => ['horizontal' => 'center']]],
                                    'label' => ["value" => 'Completado', "style" => ['alignment' => ['horizontal' => 'right'], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => ($index % 2 === 0) ? 'dce6f1' : 'FFFFFF']]]],
                                    'total' => ["value" => $totalDone, "style" => ["font" => ['bold' => true], 'alignment' => ['horizontal' => 'center'], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => ($index % 2 === 0) ? 'dce6f1' : 'FFFFFF']]]],
                                ] + collect($days)->mapWithKeys(function ($day) use ($tracker, $index) {
                                    return [
                                        $day => [
                                            "value" => $tracker[$day]['done'] ?? 0,
                                            "style" => ['alignment' => ['horizontal' => 'center'], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => ($index % 2 === 0) ? 'dce6f1' : 'FFFFFF']]]
                                        ]
                                    ];
                                })->toArray();

                                return [$assignedRow, $doneRow];
                            })->values()->toArray()
                        ]
                    ];
                })->values()->toArray(),
                'columns_dimensions_for_excel_file' => $this->generateExcelColumnDimensions($days),
            ];

            // Save locally
            $jsonDir = storage_path('app/reports');
            $jsonPath = $jsonDir . "/report_{$report->id}.json";
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $report->file_path = $jsonPath;
            $report->percent = 100;
        } catch (\Throwable $e) {
            Log::error('Error generating tasks & visits report: ' . $e->getMessage(), [
                'exception' => $e,
                'report_id' => $report->id ?? null,
            ]);
            $report->percent = -1;
        } finally {
            $report->save();
        }
    }

    private function generateExcelColumnDimensions(array $days): array
    {
        $base = [
            'A' => 25,
            'B' => 12,
            'C' => 12,
            'D' => 8,
        ];

        $startIndex = 5;
        foreach (range(0, count($days) - 1) as $i) {
            $colLetter = Coordinate::stringFromColumnIndex($startIndex + $i);
            $base[$colLetter] = 5;
        }

        return $base;
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

                if (!$dayCarbon->is($taskFrom)) continue;

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

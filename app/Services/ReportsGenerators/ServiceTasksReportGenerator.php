<?php

namespace App\Services\ReportsGenerators;

use Illuminate\Support\Facades\Log;
use App\Services\ProGpsApiService;
use Carbon\Carbon;

class ServiceTasksReportGenerator
{
    public function generate($report)
    {
        try {
            $hash = $report->user->hash;
            $api = new ProGpsApiService($hash);

            // Step 1: Fetch all needed data
            $endpoints = [
                ['key' => 'vehicles_service_task'],
                ['key' => 'trackers'],
                ['key' => 'vehicles'],
                ['key' => 'groups']
            ];

            $responses = $api->fetchBatchRequests($endpoints);

            $serviceTasks = collect($responses['vehicles_service_task']['list']);
            $trackers = collect($responses['trackers']['list'])->keyBy('id');
            $vehicles = collect($responses['vehicles']['list'])->keyBy('id');
            $groups = collect($responses['groups']['list'])->keyBy('id');

            // Add group with id 0 for "Grupo Principal"
            $groups[0] = [
                'id' => 0,
                'title' => 'Grupo Principal',
            ];

            // Step 2: Enrich each task with tracker_id and group info
            $enriched = $serviceTasks->map(function ($task) use ($vehicles, $trackers, $groups) {
                $vehicle = $vehicles[$task['vehicle_id']];
                $tracker = $trackers[$vehicle['tracker_id']];
                $group = $groups[$tracker['group_id']];

                $startMileage = data_get($task, 'start.mileage');
                $currentMileage = data_get($task, 'current_position.mileage');
                $requiredDistance = data_get($task, 'conditions.mileage.limit');

                $traveled_distance_percentage = (is_numeric($startMileage) && is_numeric($currentMileage) && is_numeric($requiredDistance) && $requiredDistance > 0)
                    ? number_format(max(0, min(100, (($currentMileage - $startMileage) / $requiredDistance) * 100)), 2, '.', ',')
                    : null;

                $distance_left = is_numeric($requiredDistance) ? number_format($requiredDistance, 2, '.', ',') : null;

                $startHours = data_get($task, 'start.engine_hours');
                $currentHours = data_get($task, 'current_position.engine_hours');
                $relativeHoursLimit = data_get($task, 'conditions.engine_hours.limit');

                $engine_hours_percentage = (is_numeric($startHours) && is_numeric($currentHours) && is_numeric($relativeHoursLimit) && $relativeHoursLimit > 0)
                    ? number_format(max(0, min(100, (($currentHours - $startHours) / $relativeHoursLimit) * 100)), 2, '.', ',')
                    : null;

                $engine_hours_left = is_numeric($relativeHoursLimit) ? $relativeHoursLimit : null;

                $startDate = data_get($task, 'start.date');
                $endDate = data_get($task, 'conditions.date.end');

                $days_passed_percentage = ($startDate && $endDate)
                    ? number_format(max(0, min(100, (
                        Carbon::parse($startDate)->diffInDays(Carbon::now(), false)
                        /
                        Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate), false)
                    ) * 100)), 2, '.', ',')
                    : null;

                $days_left = ($endDate && Carbon::now()->lessThan(Carbon::parse($endDate)))
                    ? Carbon::now()->diffInDays(Carbon::parse($endDate), false)
                    : 0;

                $statusTranslations = [
                    "created" => "Programado",
                    "notified" => "Pendiente",
                    "expired" => "Expirado",
                    "done" => "Terminado"
                ];

                return [
                    'group_name' => ["value" => $group['title']],
                    'tracker_name' => ["value" => $tracker['label']],
                    'description' => ["value" => $task['description']],
                    'status' => ["value" => $statusTranslations[$task['status']]],
                    'traveled_distance_percentage' => ["value" => $traveled_distance_percentage ? $traveled_distance_percentage . '%' : '-'],
                    'distace_left' => ["value" => $distance_left ? $distance_left . " km" : '-'],
                    'engine_hours_percentage' => ["value" => $engine_hours_percentage ? $engine_hours_percentage . '%' : '-'],
                    'engine_hours_left' => ["value" => $engine_hours_left ? $engine_hours_left . " h" : '-'],
                    'days_passed_percentage' => ["value" => $days_passed_percentage ? $days_passed_percentage . '%' : '-'],
                    'days_left' => ["value" => $days_left ? $days_left . " días" : '-'],
                    'cost' => ["value" => $task['cost']],
                    'cost_display' => ["value" => number_format($task['cost'], 2, '.', ',')],
                    'comment' => ["value" => $task['comment']],
                ];
            });

            // Step 3: Group by group_name
            $grouped = $enriched->groupBy('group_name')->map(function ($tasks, $groupName) {

                return [
                    'groupLabel' => $groupName . " (" . $tasks->count() . " servicios)" . " Costo Total (" . number_format($tasks->sum(function ($task) {
                        return $task['cost']['value'];
                    }), 2, '.', ',') . ")",
                    'bgColor' => '#C5D9F1',
                    'content' => [
                        'bgColor' => '#f2f2f2',
                        'columns' => [
                            ['name' => 'Nombre del dispositivo', 'key' => 'tracker_name'],
                            ['name' => 'Servicio', 'key' => 'description'],
                            ['name' => 'Estado', 'key' => 'status'],
                            ['name' => 'Distancia recorrida', 'key' => 'traveled_distance_percentage'],
                            ['name' => 'Distancia restante', 'key' => 'distace_left'],
                            ['name' => 'Horas de motor trabajadas', 'key' => 'engine_hours_percentage'],
                            ['name' => 'Horas restantes', 'key' => 'engine_hours_left'],
                            ['name' => 'Días transcurridos', 'key' => 'days_passed_percentage'],
                            ['name' => 'Días restantes', 'key' => 'days_left'],
                            ['name' => 'Costo', 'key' => 'cost_display'],
                            ['name' => 'Descripción', 'key' => 'comment']
                        ],
                        'rows' => $tasks->toArray()
                    ]
                ];
            })->values();

            // Step 4: Build full report
            $reportData = [
                'title' => 'Informe de Tareas de Servicio',
                'date' => 'Fecha: ' . now()->format('d/m/Y h:i A'),
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#EFEFEF',
                    'rows' => [
                        ['title' => 'Total de Servicios', 'value' => (string) $enriched->count()],
                        ['title' => 'Total de Costo', 'value' => number_format($serviceTasks->sum('cost'), 2, '.', ',')],
                        ['title' => 'Total de Dispositivos', 'value' => (string) $enriched->pluck('tracker_name')->unique()->count()],
                    ],
                ],
                'data' => $grouped->toArray(),
                'columns_dimensions_for_excel_file' => [
                    'A' => 40,
                    'B' => 32,
                    'C' => 12,
                    'D' => 17,
                    'E' => 17,
                    'F' => 24,
                    'G' => 16,
                    'H' => 16,
                    'I' => 14,
                    'J' => 14,
                    'K' => 43,
                ],
            ];

            // Step 5: Save locally
            $jsonDir = storage_path('app/reports');
            $jsonPath = "{$jsonDir}/report_{$report->id}.json";
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Final update
            $report->file_path = $jsonPath;
            $report->percent = 100;
        } catch (\Throwable $e) {
            Log::error('Error generating service tasks report: ' . $e->getMessage(), [
                'exception' => $e,
                'report_id' => $report->id ?? null,
            ]);
            $report->percent = -1;
        } finally {
            $report->save();
        }
    }
}

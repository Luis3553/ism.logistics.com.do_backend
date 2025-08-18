<?php

namespace App\Services\ReportsGenerators;

use App\Services\ProGpsApiService;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class TripReportGenerator
{
    public function generate($report)
    {
        $hash = $report->user->hash;
        $apiService = new ProGpsApiService($hash);

        $from = $report->report_payload['from'];
        $to = $report->report_payload['to'];
        $detailed = $report->report_payload['detailed'] ?? true;
        $trackerIds = $report->report_payload['trackers'];

        // Prepare batch requests: groups, trackers, trips per tracker
        $requests = [
            ['key' => 'groups'],
            ['key' => 'trackers'],
        ];

        foreach ($trackerIds as $id) {
            $requests[] = [
                'key' => 'track',
                'label' => $id,
                'params' => [
                    'tracker_id' => $id,
                    'from' => $from,
                    'to' => $to,
                    'cluster_single_reports' => true,
                    'filter' => true,
                    'include_gsm_lbs' => true,
                    'split' => true,
                ],
            ];
        }

        $responses = $apiService->fetchBatchRequests($requests);

        $groupsMap = collect($responses['groups']['list'] ?? [])->keyBy('id');
        $trackersMap = collect($responses['trackers']['list'] ?? [])->keyBy('id');

        $groupedTrips = [];

        foreach ($trackerIds as $trackerId) {
            $trips = $responses[$trackerId]['list'] ?? [];

            if (empty($trips)) continue;

            $groupId = $trackersMap[$trackerId]['group_id'] ?? null;
            $groupName = $groupId && isset($groupsMap[$groupId])
                ? $groupsMap[$groupId]['title']
                : 'Grupo Principal';

            if (!isset($groupedTrips[$groupId])) {
                $groupedTrips[$groupId] = [
                    'groupLabel' => $groupName,
                    'bgColor' => '#C5D9F1',
                    'content' => []
                ];
            }

            $trackerName = $trackersMap[$trackerId]['label'] ?? ("Tracker {$trackerId}");


            if ($detailed) {
                $groupedTrips[$groupId]['content'][] = $this->buildDetailedReport($trips, $trackerName, $groupName);
            } else {
                $groupedTrips[$groupId]['content'][] = $this->buildNotDetailedReport($trips, $trackerName, $groupName);
            }
        }

        foreach ($groupedTrips as $groupId => &$groupData) {

            $allRows = collect($groupData['content'])
                ->flatMap(function ($trackerBlock) {
                    return Arr::get($trackerBlock, 'content.rows', []);
                });

            $totalTrips = collect($groupData['content'])
                ->sum(function ($trackerBlock) {
                    return $trackerBlock['tripCount'] ?? count(Arr::get($trackerBlock, 'content.rows', []));
                });

            $totalDistance = $allRows->sum(fn($r) => (float) Arr::get($r, 'distance_km.raw', 0));

            $totalTimeSeconds = $allRows->sum(fn($r) => (int) Arr::get($r, 'travel_time.raw', 0));

            $h = (int) floor($totalTimeSeconds / 3600);
            $m = (int) floor(($totalTimeSeconds % 3600) / 60);

            $groupData['groupLabel'] = sprintf(
                '%s   (%d Viajes)   (%.2f Km Distancia recorrida)   (%02d:%02d h Tiempo de Viaje)',
                $groupData['groupLabel'],
                $totalTrips,
                $totalDistance,
                $h,
                $m
            );
        }

        unset($groupData);

        $totalTrips = collect($groupedTrips)
            ->pluck('content')
            ->flatten(1)
            ->sum(function ($trackerBlock) {
                $rows = Arr::get($trackerBlock, 'content.rows', []);
                return is_array($rows) ? count($rows) : 0;
            });

        $totalDistanceTraveled = collect($groupedTrips)
            ->pluck('content')
            ->flatten(1)
            ->sum(function ($trackerBlock) {
                $rows = Arr::get($trackerBlock, 'content.rows', []);
                return collect($rows)->sum(fn($r) => (float) Arr::get($r, 'distance_km.raw', 0));
            });
        $totalDistanceTraveledFormatted = number_format($totalDistanceTraveled, 2, '.', '') . ' Km';

        $totalTripTimeSeconds = collect($groupedTrips)
            ->pluck('content')
            ->flatten(1)
            ->sum(function ($trackerBlock) {
                $rows = Arr::get($trackerBlock, 'content.rows', []);
                return collect($rows)->sum(fn($r) => (int) Arr::get($r, 'travel_time.raw', 0));
            });
        $hours = (int) floor($totalTripTimeSeconds / 3600);
        $minutes = (int) floor(($totalTripTimeSeconds % 3600) / 60);
        $totalTripTimeFormatted = sprintf('%02d:%02d h', $hours, $minutes);

        $totalObjectsWithTrips = collect($groupedTrips)
            ->pluck('content')
            ->flatten(1)
            ->count();

        $reportData = [
            'title' => 'Informe de Viajes',
            'date' => 'Desde: ' . date('d/m/Y H:i', strtotime($from)) . ' hasta ' . date('d/m/Y H:i', strtotime($to)),
            'summary' => [
                'title' => 'Resumen General',
                'color' => '#EFEFEF',
                'rows' => [
                    ['title' => 'Total de objetos', 'value' => $totalObjectsWithTrips],
                    ['title' => 'Total de viajes', 'value' => $totalTrips],
                    ['title' => 'Total de distancia recorrida', 'value' => $totalDistanceTraveledFormatted],
                    ['title' => 'Total de tiempo de viaje', 'value' => $totalTripTimeFormatted],
                ],
            ],
            'data' => array_values($groupedTrips),
            'columns_dimensions_for_excel_file' => $detailed ? [
                'A' => 24,
                'B' => 9,
                'C' => 35,
                'D' => 9,
                'E' => 35,
                'F' => 22,
                'G' => 22,
                'H' => 22,
                'I' => 22,
            ] : [
                'A' => 24,
                'B' => 10,
                'C' => 10,
                'D' => 21,
                'E' => 19,
                'F' => 21,
                'G' => 21,
            ],
        ];

        return $reportData;
    }

    private function buildNotDetailedReport($trips, $trackerName, $groupName)
    {
        $grouped = [];

        foreach ($trips as $trip) {
            $startDate = Carbon::parse($trip['start_date']);
            $endDate = Carbon::parse($trip['end_date']);
            $dateKey = $startDate->format('Y-m-d'); // Agrupar por día

            if (!isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'start_date' => $startDate,
                    'start_time' => $startDate,
                    'end_time' => $endDate,
                    'distance_km' => (float)($trip['length'] ?? 0),
                    'avg_speed_sum' => (float)($trip['avg_speed'] ?? 0),
                    'trip_count' => 1,
                    'max_speed' => (float)($trip['max_speed'] ?? 0),
                ];
            } else {
                // Earliest start
                if ($startDate->lt($grouped[$dateKey]['start_time'])) {
                    $grouped[$dateKey]['start_time'] = $startDate;
                }
                // Latest end
                if ($endDate->gt($grouped[$dateKey]['end_time'])) {
                    $grouped[$dateKey]['end_time'] = $endDate;
                }
                // Sum distance
                $grouped[$dateKey]['distance_km'] += (float)($trip['length'] ?? 0);
                // Sum avg_speed for later average
                $grouped[$dateKey]['avg_speed_sum'] += (float)($trip['avg_speed'] ?? 0);
                $grouped[$dateKey]['trip_count']++;
                // Highest max speed
                $grouped[$dateKey]['max_speed'] = max($grouped[$dateKey]['max_speed'], (float)($trip['max_speed'] ?? 0));
            }
        }

        // Formatear
        $formattedTrips = array_map(function ($item) {
            // Tiempo de viaje como diferencia entre primera y última hora
            $travel_time_sec = $item['end_time']->diffInSeconds($item['start_time']);
            $h = floor($travel_time_sec / 3600);
            $m = floor(($travel_time_sec % 3600) / 60);

            // Velocidad media simple
            $avg_speed = $item['trip_count'] > 0
                ? $item['avg_speed_sum'] / $item['trip_count']
                : 0;

            return [
                'start_date' => ['value' => $item['start_date']->format('d/m/Y')],
                'start_time' => ['value' => $item['start_time']->format('h:i A')],
                'end_time' => ['value' => $item['end_time']->format('h:i A')],
                'distance_km' => [
                    'value' => number_format($item['distance_km'], 2, '.', ''),
                    'raw' => $item['distance_km'],
                ],
                'travel_time' => [
                    'value' => sprintf('%02d:%02d', $h, $m),
                    'raw' => $travel_time_sec,
                ],
                'avg_speed' => [
                    'value' => number_format($avg_speed, 1, '.', ''),
                ],
                'max_speed' => [
                    'value' => number_format($item['max_speed'], 1, '.', '')
                ],
            ];
        }, $grouped);

        return [
            'groupLabel' => sprintf(
                '%s   (%d Viajes)   (%.2f Distancia recorrida)   (%02d:%02d h Tiempo de Viaje)',
                $trackerName,
                count($trips),
                collect($formattedTrips)->sum(fn($r) => (float) Arr::get($r, 'distance_km.raw', 0)),
                (int) floor(collect($formattedTrips)->sum(fn($r) => (int) Arr::get($r, 'travel_time.raw', 0)) / 3600),
                (int) floor((collect($formattedTrips)->sum(fn($r) => (int) Arr::get($r, 'travel_time.raw', 0)) % 3600) / 60)
            ),
            'bgColor' => '#DFDFDF',
            'tripCount' => count($trips),
            'content' => [
                'bgColor' => '#f2f2f2',
                'columns' => [
                    ['name' => 'Fecha Inicio', 'key' => 'start_date'],
                    ['name' => 'H Inicio', 'key' => 'start_time'],
                    ['name' => 'H Fin', 'key' => 'end_time'],
                    ['name' => 'Distancia recorrida (Km)', 'key' => 'distance_km'],
                    ['name' => 'Tiempo de viaje (Hora)', 'key' => 'travel_time'],
                    ['name' => 'Velocidad media (Km/h)', 'key' => 'avg_speed'],
                    ['name' => 'Max. Velocidad (Km/h)', 'key' => 'max_speed'],
                ],
                'rows' => array_values($formattedTrips),
            ],
        ];
    }


    private function buildDetailedReport($trips, $trackerName, $groupName)
    {
        $formattedTrips = array_map(function ($trip) {
            $startDate = Carbon::parse($trip['start_date']);
            $endDate = Carbon::parse($trip['end_date']);
            $durationInSeconds = $endDate->diffInSeconds($startDate);

            $h = (int) floor($durationInSeconds / 3600);
            $m = (int) floor(($durationInSeconds % 3600) / 60);

            return [
                'start_date' => ['value' => $startDate->format('d/m/Y')],
                'start_time' => ['value' => $startDate->format('h:i A')],
                'start_address' => ['value' => $trip['start_address'] ?? '-'],
                'end_time' => ['value' => $endDate->format('h:i A')],
                'end_address' => ['value' => $trip['end_address'] ?? '-'],
                'distance_km' => [
                    'value' => number_format((float)($trip['length'] ?? 0), 2, '.', ''),
                    'raw' => (float)($trip['length'] ?? 0),
                ],
                'travel_time' => [
                    'value' => sprintf('%02d:%02d', $h, $m),
                    'raw' => $durationInSeconds,
                ],
                'avg_speed' => ['value' => number_format((float)($trip['avg_speed'] ?? 0), 1, '.' . '')],
                'max_speed' => ['value' => number_format((float)($trip['max_speed'] ?? 0), 1, '.' . '')],
            ];
        }, $trips);

        return [
            'groupLabel' => sprintf(
                '%s   (%d Viajes)   (%.2f Distancia recorrida)   (%02d:%02d h Tiempo de Viaje)',
                $trackerName,
                count($formattedTrips),
                collect($formattedTrips)->sum(fn($r) => (float) Arr::get($r, 'distance_km.raw', 0)),
                (int) floor(collect($formattedTrips)->sum(fn($r) => (int) Arr::get($r, 'travel_time.raw', 0)) / 3600),
                (int) floor((collect($formattedTrips)->sum(fn($r) => (int) Arr::get($r, 'travel_time.raw', 0)) % 3600) / 60)
            ),
            'bgColor' => '#DFDFDF',
            'tripCount' => count($trips),
            'content' => [
                'bgColor' => '#f2f2f2',
                'columns' => [
                    ['name' => 'Fecha Inicio', 'key' => 'start_date'],
                    ['name' => 'H Inicio', 'key' => 'start_time'],
                    ['name' => 'Inicio Movimiento', 'key' => 'start_address'],
                    ['name' => 'H Fin', 'key' => 'end_time'],
                    ['name' => 'Fin de Movimiento', 'key' => 'end_address'],
                    ['name' => 'Distancia recorrida (Km)', 'key' => 'distance_km'],
                    ['name' => 'Tiempo de viaje (Hora)', 'key' => 'travel_time'],
                    ['name' => 'Velocidad media (Km/h)', 'key' => 'avg_speed'],
                    ['name' => 'Max. Velocidad (Km/h)', 'key' => 'max_speed'],
                ],
                'rows' => $formattedTrips,
            ],
        ];
    }
}

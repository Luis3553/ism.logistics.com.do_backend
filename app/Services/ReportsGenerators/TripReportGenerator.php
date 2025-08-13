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
        $detailed = $report->report_payload['detailed'] ?? false;
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
                    'max_speed' => ['value' => number_format((float)($trip['max_speed'] ?? 0), 1, '.', '')],
                ];
            }, $trips);

            $groupedTrips[$groupId]['content'][] = [
                'groupLabel' => sprintf(
                    '%s   (%d Viajes)   (%.2f Distancia recorrida)   (%02d:%02d h Tiempo de Viaje)',
                    $trackerName,
                    count($formattedTrips),
                    collect($formattedTrips)->sum(fn($r) => (float) Arr::get($r, 'distance_km.raw', 0)),
                    (int) floor(collect($formattedTrips)->sum(fn($r) => (int) Arr::get($r, 'travel_time.raw', 0)) / 3600),
                    (int) floor((collect($formattedTrips)->sum(fn($r) => (int) Arr::get($r, 'travel_time.raw', 0)) % 3600) / 60)
                ),
                'bgColor' => '#DFDFDF',
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

        foreach ($groupedTrips as $groupId => &$groupData) {

            $allRows = collect($groupData['content'])
                ->flatMap(function ($trackerBlock) {
                    return Arr::get($trackerBlock, 'content.rows', []);
                });

            $totalTrips = $allRows->count();

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
        $totalDistanceTraveledFormatted = number_format($totalDistanceTraveled, 2, ',', '') . ' Km';

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
            'columns_dimensions_for_excel_file' => [
                'A' => 24,
                'B' => 9,
                'C' => 35,
                'D' => 9,
                'E' => 35,
                'F' => 22,
                'G' => 22,
                'H' => 22,
                'I' => 22,
            ],
        ];

        return $reportData;
    }
}

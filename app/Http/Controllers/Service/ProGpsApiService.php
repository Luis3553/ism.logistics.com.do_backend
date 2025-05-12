<?php

namespace App\Http\Controllers\Service;

use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProGpsApiService
{
    public string $apiKey;
    protected string $baseUrl;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = 'https://app.progps.com.do/api-v2';
    }

    private function post(string $endpoint, $params = []): array
    {
        $params['hash'] = $this->apiKey;

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/{$endpoint}", $params);

        return $response->json() ?? [];
    }

    function fetchBatchRequests(array $endpoints): array
    {
        $responses = Http::pool(function (Pool $pool) use ($endpoints) {
            $requests = [];

            foreach ($endpoints as $key => $endpoint) {
                $requests[$key] = $pool
                    ->as($key)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post("{$this->baseUrl}/{$endpoint}", ['hash' => $this->apiKey]);
            }

            return array_values($requests);
        });

        return $responses;
    }


    public function getVehicles(): array
    {
        return $this->post('vehicle/list');
    }

    public function getTrackers(): array
    {
        return $this->post('tracker/list');
    }

    public function getGarages(): array
    {
        return $this->post('garage/list');
    }

    public function getGeofences($params = null): array
    {
        return $this->post('zone/list', $params);
    }

    public function getDepartments(): array
    {
        return $this->post('department/list');
    }

    public function getEmployees(): array
    {
        return $this->post('employee/list');
    }

    // IGNORE THIS CODE FOR NOW

    // public function getHistoryOfTrackers(string $from, string $to)
    // {
    //     $trackersIds = array_map(fn($tracker) => $tracker['id'], $this->getTrackers()['list']);
    //     $configurations = User::with('geofenceConfigurations.items')->where('hash', 'LIKE', $this->apiKey)->get();

    //     $events = $this->post(
    //         'history/tracker/list',
    //         [
    //             'from' => $from,
    //             'to' => $to,
    //             'trackers' => $trackersIds,
    //             'events' => ['outzone', 'inzone']
    //         ]
    //     );

    //     return $this->contarViajes($events, $configurations);
    // }

    // public function contarViajes($json, $configuraciones)
    // {
    //     $eventos = collect($json['list'])->sortBy('time'); // Orden cronológico

    //     // Obtener configuraciones de origenes y destinos válidas
    //     $validConfigs = collect();

    //     foreach ($configuraciones as $user) {
    //         foreach ($user->geofenceConfigurations as $config) {
    //             $origenes = $config->items->where('type', 'origin')->pluck('geofence_id')->toArray();
    //             $destinos = $config->items->where('type', 'destiny')->pluck('geofence_id')->toArray();
    //             $validConfigs->push(['origins' => $origenes, 'destinations' => $destinos]);
    //         }
    //     }


    //     // Agrupar eventos por vehículo
    //     $agrupado = $eventos->groupBy('tracker_id');

    //     $viajesPorVehiculo = [];
    //     $totalViajes = 0;

    //     foreach ($agrupado as $trackerId => $eventosVehiculo) {
    //         $viajes = 0;
    //         $estado = [
    //             'esperandoDestino' => false,
    //             'origenId' => null,
    //             'configActual' => null,
    //         ];

    //         foreach ($eventosVehiculo as $evento) {
    //             $zonaId = $evento['extra']['zone_ids'][0] ?? null;
    //             if (!$zonaId) continue;

    //             if ($evento['event'] === 'outzone') {
    //                 // Buscar si la zona es un origen válido
    //                 foreach ($validConfigs as $config) {
    //                     if (in_array($zonaId, $config['origins'])) {
    //                         // Es un origen válido
    //                         $estado['esperandoDestino'] = true;
    //                         $estado['origenId'] = $zonaId;
    //                         $estado['configActual'] = $config;
    //                         break;
    //                     }
    //                 }
    //             }

    //             if (
    //                 $evento['event'] === 'inzone'
    //                 && $estado['esperandoDestino']
    //                 && $estado['configActual']
    //                 && in_array($zonaId, $estado['configActual']['destinations'])
    //             ) {
    //                 // Es un destino válido en la misma configuración del origen anterior
    //                 $viajes++;
    //                 $estado = [ // Reset
    //                     'esperandoDestino' => false,
    //                     'origenId' => null,
    //                     'configActual' => null,
    //                 ];
    //             }
    //         }

    //         $label = $eventosVehiculo->first()['extra']['tracker_label'] ?? $trackerId;
    //         $viajesPorVehiculo[$label] = $viajes;
    //         $totalViajes += $viajes;
    //     }

    //     return [
    //         'travels_count' => $totalViajes,
    //         'detalle' => $viajesPorVehiculo,
    //     ];
    // }


    // public function getSumOfTrackersOdometerActualValue()
    // {
    //     $trackersIds = array_map(fn($tracker) => $tracker['id'], $this->getTrackers()['list']);
    //     $response = $this->post('/tracker/counter/value/list', [
    //         'trackers' => $trackersIds,
    //         'type' => 'odometer'
    //     ]);

    //     if ($response['success']) {
    //         return array_sum($response['value']);
    //     }

    //     return 0;
    // }

    // public function getSumOfOdometersValueInPastDate($date)
    // {
    //     $trackersIds = array_map(fn($tracker) => $tracker['id'], $this->getTrackers()['list']);
    //     $sum = 0;

    //     foreach ($trackersIds as $id) {
    //         $response = $this->post('/tracker/counter/data/read', [
    //             'tracker_id' => $id,
    //             'type' => 'odometer',
    //             'from' => "$date 08:00:00",
    //             'to' => "$date 18:00:00"
    //         ]);

    //         if (!empty($response['success']) && !empty($response['list'])) {
    //             $lastValue = end($response['list'])['value'] ?? 0;
    //             $sum += round($lastValue, 1);
    //         }
    //     }
    // }

    // public function getTripReportsOfTrackersParallel(string $date): array
    // {
    //     $trackersIds = array_map(fn($tracker) => $tracker['id'], $this->getTrackers()['list']);
    //     $apiUrl = $this->baseUrl;
    //     $apiKey = $this->apiKey;

    //     $reports = collect();
    //     $reportIds = [];
    //     $trackerChunks = array_chunk($trackersIds, 20); // Group 20 trackers per report

    //     foreach ($trackerChunks as $chunkIndex => $chunk) {
    //         try {
    //             Log::info("Sending report generation request for tracker group $chunkIndex: " . json_encode($chunk));

    //             $response = Http::post("$apiUrl/report/tracker/generate", [
    //                 'title' => 'Geocercas visitas informe - API_GENERATED',
    //                 'trackers' => $chunk,
    //                 'from' => "$date 00:00:00",
    //                 'to' => "$date 23:59:59",
    //                 'time_filter' => [
    //                     'from' => '08:00:00',
    //                     'to' => '18:00:00',
    //                     'weekdays' => [1, 2, 3, 4, 5],
    //                 ],
    //                 'plugin' => [
    //                     'hide_empty_tabs' => true,
    //                     'plugin_id' => 8,
    //                     'show_seconds' => false,
    //                     'include_summary_sheet' => false,
    //                     'include_summary_sheet_only' => false,
    //                     'show_mileage' => true,
    //                     'show_not_visited_zones' => true,
    //                     'min_minutes_in_zone' => 4,
    //                     'hide_charts' => true,
    //                     'use_all_zones' => true,
    //                     'zone_ids' => null
    //                 ],
    //                 'hash' => $apiKey
    //             ]);

    //             if ($response->status() === 429) {
    //                 Log::warning("429 Too Many Requests. Waiting before retry...");
    //                 sleep(2);
    //                 continue;
    //             }

    //             if (!$response->successful()) {
    //                 Log::error("Failed to generate report for chunk $chunkIndex: " . $response->body());
    //                 continue;
    //             }

    //             $id = $response->json('id');
    //             if ($id) {
    //                 $reportIds[] = $id;
    //             } else {
    //                 Log::warning("Missing report ID in response for chunk $chunkIndex: " . $response->body());
    //             }

    //             usleep(500000); // Throttle between chunk calls
    //         } catch (\Throwable $e) {
    //             Log::error("Exception during report generation for chunk $chunkIndex: " . $e->getMessage());
    //         }
    //     }

    //     Log::info("Generated report IDs: " . json_encode($reportIds));

    //     // Poll for report readiness
    //     $readyReports = [];
    //     $pendingIds = $reportIds;

    //     while (count($pendingIds)) {
    //         usleep(800000);
    //         $chunks = array_chunk($pendingIds, 5);

    //         foreach ($chunks as $chunk) {
    //             $retrieveResponses = Http::pool(
    //                 fn($pool) => array_map(
    //                     fn($reportId) => $pool->post("$apiUrl/report/tracker/retrieve", [
    //                         'report_id' => $reportId,
    //                         'hash' => $apiKey
    //                     ]),
    //                     $chunk
    //                 )
    //             );

    //             foreach ($retrieveResponses as $i => $res) {
    //                 $json = $res->json();

    //                 if (!empty($json['success'])) {
    //                     $id = $chunk[$i];
    //                     $readyReports[$id] = $json;

    //                     $pendingIds = array_filter($pendingIds, fn($pid) => $pid !== $id);
    //                 }
    //             }
    //         }
    //     }

    //     // Delete all reports
    //     Http::pool(
    //         fn($pool) => array_map(
    //             fn($reportId) => $pool->post("$apiUrl/report/tracker/delete", [
    //                 'report_id' => $reportId,
    //                 'hash' => $apiKey
    //             ]),
    //             array_keys($readyReports)
    //         )
    //     );

    //     // Process reports
    //     foreach ($readyReports as $reportId => $report) {
    //         try {
    //             $reports->push([
    //                 'report_id' => $reportId,
    //                 'data' => $report
    //             ]);
    //         } catch (\Throwable $e) {
    //             Log::error("Failed to parse report $reportId: " . $e->getMessage());
    //         }
    //     }

    //     return $reports->toArray();
    // }



    // public function getEngineHoursReport($date, array $trackerIDs)
    // {

    //     $params = [
    //         'title' => 'Informe de horas de motor',
    //         'trackers' => $trackerIDs,
    //         'from' => "$date 00:00:00",
    //         'to' => "$date 23:59:59",
    //         'time_filter' => [
    //             'from' => '08:00:00',
    //             'to' => '18:00:00',
    //             'weekdays' => [1, 2, 3, 4, 5, 6],
    //         ],
    //         'plugin' => [
    //             'hide_empty_tabs' => true,
    //             'plugin_id' => 7,
    //             'show_seconds' => false,
    //             'show_detailed' => false,
    //             'include_summary_sheet' => true,
    //             'include_summary_sheet_only' => true,
    //             'filter' => true,
    //         ],
    //     ];

    //     $generate = $this->post('/report/tracker/generate', $params);
    //     $reportId = $generate['id'];

    //     do {
    //         usleep(500000); // 500ms
    //         $data = $this->post('/report/tracker/retrieve', ['report_id' => $reportId]);
    //     } while (empty($data['success']));

    //     $this->post('/report/tracker/delete', ['report_id' => $reportId]);

    //     $sheets = $data['report']['sheets'][0]['sections'];
    //     $tableSection = collect($sheets)->firstWhere('type', 'table');
    //     $mapTableSection = collect($sheets)->firstWhere('type', 'map_table');

    //     $rows = $tableSection['data'][0]['rows'] ?? [];
    //     $summary = $mapTableSection['rows'] ?? [];

    //     $length = count($rows);
    //     $movementHoursRaw = $summary[1]['raw'] ?? 0;

    //     return [
    //         'vehicles_count' => $length,
    //         'average_movement_hours' => $length ? $this->convertToHoursAndMinutes($movementHoursRaw / $length) : '00:00',
    //         'movement_hours' => $summary[1]['v'] ?? '00:00',
    //         'average_velocity' => $summary[5]['v'] ?? 0,
    //     ];
    // }

    // private function convertToHoursAndMinutes($minutes)
    // {
    //     $hours = floor($minutes / 60);
    //     $mins = round($minutes % 60);
    //     return sprintf('%02d:%02d', $hours, $mins);
    // }
}

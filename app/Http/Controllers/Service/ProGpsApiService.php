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

    private function post(string $endpoint, $params = [])
    {
        $params['hash'] = $this->apiKey;

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/{$endpoint}", $params);

        return $response;
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
        return $this->post('vehicle/list')->json() ?? [];
    }

    public function getVehicle(int $id)
    {
        return $this->post('vehicle/read', ['vehicle_id' => $id])->json() ?? [];
    }

    public function getTrackers($params = null): array
    {
        return $this->post('tracker/list', $params)->json() ?? [];
    }

    public function getTracker(int $id)
    {
        return $this->post('tracker/read', ['tracker_id' => $id])->json() ?? [];
    }

    public function getTrackersStates($ids)
    {
        return $this->post('tracker/get_states', ['trackers' => $ids]) ?? [];
    }

    public function getEventTypes(): array
    {
        return $this->post('tracker/rule/list')->json() ?? [];
    }

    public function getHistoryOfTrackers($ids, string $from, string $to, $events): array
    {

        return $this->post('history/tracker/list', [
            'trackers' => $ids,
            'from' => $from,
            'to' => $to,
            'limit' => 1000,
            'events' => $events,
            'ascending' => false
        ])->json() ?? [];
    }

    public function getGarages(): array
    {
        return $this->post('garage/list')->json() ?? [];
    }

    public function getGeofences($params = null): array
    {
        return $this->post('zone/list', $params)->json() ?? [];
    }

    public function getDepartments(): array
    {
        return $this->post('department/list')->json() ?? [];
    }

    public function getEmployees(): array
    {
        return $this->post('employee/list')->json() ?? [];
    }

    public function getGroups(): array
    {
        return $this->post('tracker/group/list')->json() ?? [];
    }

    public function getModels(): array
    {
        return $this->post('tracker/list_models')->json() ?? [];
    }

    public function getTags(): array
    {
        return $this->post('tag/list')->json() ?? [];
    }

    public function getUserInfo(): array
    {
        return $this->post('user/get_info')->json() ?? [];
    }

    public function getAddressUsingCoordinates($latitude, $longitude): string
    {
        $response = $this->post('geocoder/search_location', [
            'hash' => $this->apiKey,
            'lat' => $latitude,
            'lng' => $$longitude,
            'lang' => 'es_ES',
            'geocoder' => 'google'
        ]);

        if ($response->successful()) {
            return $response->json()['value'] ?? '';
        } else {
            return "Failed to get coordinates";
        }
    }

    public function getRawData($params = [])
    {
        $response = $this->post("tracker/raw_data/read", $params);
        $csv = $response->body();

        return $csv;
    }


    public function getOdometersOfListOfTrackersInPeriodRange($trackersIds, $date)
    {
        $responses = [];
        $trackerChunks = array_chunk($trackersIds->toArray(), 50);

        foreach ($trackerChunks as $chunk) {
            $batchResponses = Http::pool(function (Pool $pool) use ($chunk, $date) {
                $requests = [];

                foreach ($chunk as $id) {
                    $requests[$id] = $pool
                        ->as($id)
                        ->withHeaders(['Content-Type' => 'application/json'])
                        ->timeout(60)
                        ->post("{$this->baseUrl}/tracker/counter/data/read", [
                            'hash' => $this->apiKey,
                            'tracker_id' => $id,
                            'type' => 'odometer',
                            'from' => explode('T', $date)[0] . "T00:00:00",
                            'to' => explode('T', $date)[0] . "T23:59:59",
                        ]);
                }

                return array_values($requests);
            });

            $responses = array_replace($responses, $batchResponses);
        }

        $result = [];
        $idsOfTrackersWithNotReportOfTheDate = [];

        foreach ($responses as $id => $response) {
            $res = $response->json();

            if ($res['success'] ?? false) {
                $record = [];

                if (!empty($res['list']) && is_array($res['list'])) {
                    $lastEntry = end($res['list']);
                    $record = ['value' => $lastEntry['value'] ?? 0, 'update_time' => $lastEntry['update_time']];
                    $result[$id] = $record;
                } else {
                    $idsOfTrackersWithNotReportOfTheDate[] = $id;
                }
            }
        }

        $responses1 = [];

        if (!empty($idsOfTrackersWithNotReportOfTheDate)) {
            $responses1 = Http::pool(function (Pool $pool) use ($idsOfTrackersWithNotReportOfTheDate) {
                $requests = [];

                foreach ($idsOfTrackersWithNotReportOfTheDate as $id) {
                    $requests[$id] = $pool
                        ->as($id)
                        ->withHeaders(['Content-Type' => 'application/json'])
                        ->post("{$this->baseUrl}/tracker/get_counters", [
                            'hash' => $this->apiKey,
                            'tracker_id' => $id,
                            'type' => 'odometer',
                        ]);
                }

                return array_values($requests);
            });
        }

        foreach ($responses1 as $id => $response) {
            $res = $response->json();

            if (!empty($res['list']) && is_array($res['list'])) {
                $lastEntry = collect($res['list'])->firstWhere('type', 'odometer');
                $record = ['value' => $lastEntry['value'] ?: 0, 'update_time' => $lastEntry['update_time'] ?: ''];
                $result[$id] = $record;
            }
        }

        return $result;
    }

    public function translateEventType(string $type): ?string
    {
        return $this->eventTypesTranslations[$type] ?? null;
    }

    public $validReportTypeIds = [1, 2, 3];

    public $listIds = [
        10273187,
        10273191,
        10275230,
        10280388,
        10280389,
        10280390,
        10343018,
        10343205,
        10348855,
        10351350,
        10357019,
        10361179,
        10363013,
        10363081,
        10367160,
        10367178,
        10367188,
        10367199,
        10367211,
        10367434,
        10367435,
        10367436,
        10367438,
        10367439,
        10367440,
        10367441,
        10367442,
        10367443,
        10367444,
        10367445,
        10367446,
        10367447,
        10367448,
        10367449,
        10367450,
        10367451,
        10367452,
        10367453,
        10367454,
        10367455,
        10367456,
        10367457,
        10367458,
        10367459,
        10367460,
        10367461,
        10367462,
        10367463,
        10367464,
        10367465,
        10367466,
        10367467,
        10367469,
        10367470,
        10367471,
        10367472,
        10367473,
        10367474,
        10367475,
        10367477,
        10367478,
        10367479,
        10367480,
        10367481,
        10367482,
        10367483,
        10367484,
        10367485,
        10367486,
        10367590,
        10367676,
        10369910,
        10369911,
        10369912,
        10369913,
        10369914,
        10369915,
        10369916,
        10369917,
        10369918,
        10369919,
        10369920,
        10369921,
        10369922,
        10369923,
        10369924,
        10369925,
        10369926,
        10369927,
        10369928,
        10369929,
        10369930,
        10369931,
        10369932,
        10369933,
        10369934,
        10369935,
        10369936,
        10369937,
        10369938,
        10369939,
        10369940,
        10369941,
        10369942,
        10369943,
        10369944,
        10369945,
        10369946,
        10369947,
        10369948,
        10369949,
        10369950,
        10369951,
        10369952,
        10369953,
        10369954,
        10369955,
        10369956,
        10369957,
        10369958,
        10369959,
        10369960,
        10369962,
        10369963,
        10369964,
        10369965,
        10369966,
        10369967,
        10369968,
        10369969,
        10369970,
        10369971,
        10369972,
        10369973,
        10369974,
        10369975,
        10369976,
        10369977,
        10369978,
        10369979,
        10369980,
        10369981,
        10369982,
        10369983,
        10369984,
        10369985,
        10369986,
        10369987,
        10369988,
        10369989,
        10369990,
        10369991,
        10369992,
        10369993,
        10369994,
        10369995,
        10369996,
        10369997,
        10369998,
        10369999,
        10370000,
        10370001,
        10370002,
        10370003,
        10370004,
        10370005,
        10370006,
        10370007,
        10370008,
        10370009,
        10370010,
        10370020,
        10370138,
        10370139,
        10370140,
        10370141,
        10370336
    ];

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

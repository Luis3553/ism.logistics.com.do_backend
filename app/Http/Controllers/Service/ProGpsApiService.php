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

    public function createRoute($taskData)
    {
        $response = $this->post('task/route/create', $taskData);
        return $response->json() ?? [];
    }

    public function createTask($taskData)
    {
        $response = $this->post('task/create', $taskData);
        return $response->json() ?? [];
    }

    public function getScheduleTaskData($id): array
    {
        $response = $this->post('/task/schedule/read', [
            'id' => $id,
        ]);
        return $response->json() ?? [];
    }

    public function getScheduleTasks($params = null): array
    {
        $response = $this->post('task/schedule/list', $params);
        return $response->json() ?? [];
    }

    public function getVehiclesMaintenance(): array
    {
        $response = $this->post('vehicle/maintenance/list');
        return $response->json() ?? [];
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

    public function getOdometerOfListOfTrackers($trackersIds)
    {
        $response = $this->post("tracker/counter/value/list", [
            'trackers' => $trackersIds,
            'type' => 'odometer',
        ]);

        return $response->json();
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

    public $validReportTypeIds = [1, 2, 3, 4, 5];
}

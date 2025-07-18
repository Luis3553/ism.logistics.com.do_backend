<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProGpsApiService
{
    public string $apiKey;
    public string $baseUrl;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = 'https://app.progps.com.do/api-v2';
    }

    private function post(string $endpointKeyOrPath, $params = [])
    {
        $endpoint = $this->endpoints[$endpointKeyOrPath] ?? $endpointKeyOrPath;
        $params['hash'] = $this->apiKey;

        return Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/{$endpoint}", $params);
    }

    public function fetchBatchRequests(array $requests): array
    {
        return Http::pool(function (Pool $pool) use ($requests) {
            $responses = [];

            foreach ($requests as $index => $request) {
                $key = $request['key'];
                $params = $request['params'] ?? [];
                $endpoint = $this->endpoints[$key] ?? $key;
                $params['hash'] = $this->apiKey;

                $responses[$key] = $pool
                    ->as($key)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post("{$this->baseUrl}/{$endpoint}", $params);
            }

            return $responses;
        });
    }

    public function getTaskList()
    {
        return $this->post('tasks')->json() ?? [];
    }

    public function getFormTemplateList()
    {
        return $this->post('form_template_list')->json() ?? [];
    }

    public function getPlaces($params = null): array
    {
        return $this->post('places', $params)->json() ?? [];
    }

    public function createRoute($taskData)
    {
        return $this->post('create_route', $taskData)->json() ?? [];
    }

    public function createTask($taskData)
    {
        return $this->post('create_task', $taskData)->json() ?? [];
    }

    public function getScheduleTaskData($id): array
    {
        return $this->post('schedule_read', ['id' => $id])->json() ?? [];
    }

    public function getScheduleTasks($params = null): array
    {
        return $this->post('schedule_list', $params)->json() ?? [];
    }

    public function getVehiclesServicesTask(): array
    {
        return $this->post('vehicles_service_task')->json() ?? [];
    }

    public function getVehicles(): array
    {
        return $this->post('vehicles')->json() ?? [];
    }

    public function getVehicle(int $id)
    {
        return $this->post('vehicle_read', ['vehicle_id' => $id])->json() ?? [];
    }

    public function getTrackers($params = null): array
    {
        return $this->post('trackers', $params)->json() ?? [];
    }

    public function getTracker(int $id)
    {
        return $this->post('tracker_read', ['tracker_id' => $id])->json() ?? [];
    }

    public function getTrackersStates($ids)
    {
        return $this->post('tracker_states', ['trackers' => $ids]) ?? [];
    }

    public function getEventTypes(): array
    {
        return $this->post('event_types')->json() ?? [];
    }

    public function getHistoryOfTrackers($ids, string $from, string $to, $events): array
    {

        return $this->post('tracker_history', [
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
        return $this->post('garages')->json() ?? [];
    }

    public function getGeofences($params = null): array
    {
        return $this->post('geofences', $params)->json() ?? [];
    }

    public function getDepartments(): array
    {
        return $this->post('departments')->json() ?? [];
    }

    public function getEmployees(): array
    {
        return $this->post('employees')->json() ?? [];
    }

    public function getGroups(): array
    {
        return $this->post('groups')->json() ?? [];
    }

    public function getModels(): array
    {
        return $this->post('models')->json() ?? [];
    }

    public function getTags(): array
    {
        return $this->post('tags')->json() ?? [];
    }

    public function getUserInfo(): array
    {
        return $this->post('user_info')->json() ?? [];
    }

    public function getOdometerOfListOfTrackers($trackersIds)
    {
        $response = $this->post("odometer_list", [
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

    protected array $endpoints = [
        'tasks' => 'task/list',
        'form_template_list' => 'form/template/list',
        'places' => 'place/list',
        'create_task' => 'task/create',
        'create_route' => 'task/route/create',
        'schedule_read' => 'task/schedule/read',
        'schedule_list' => 'task/schedule/list',
        'vehicles_service_task' => 'vehicle/service_task/list',
        'vehicles' => 'vehicle/list',
        'vehicle_read' => 'vehicle/read',
        'trackers' => 'tracker/list',
        'tracker_read' => 'tracker/read',
        'tracker_states' => 'tracker/get_states',
        'tracker_history' => 'history/tracker/list',
        'event_types' => 'tracker/rule/list',
        'history' => 'history/tracker/list',
        'garages' => 'garage/list',
        'geofences' => 'zone/list',
        'departments' => 'department/list',
        'employees' => 'employee/list',
        'groups' => 'tracker/group/list',
        'models' => 'tracker/list_models',
        'tags' => 'tag/list',
        'user_info' => 'user/get_info',
        'odometer_list' => 'tracker/counter/value/list',
    ];
}

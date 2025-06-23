<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Service\ProGpsApiService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class NotificationsController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}

    public function getNotifications(Request $request)
    {
        $params = $request->query();
        $notificationService = new NotificationService($this->apiService->apiKey);
        $result = $notificationService->getNotifications($params);

        return response()->json($result->original);
    }


    public function getTrackers(Request $request)
    {
        $search = trim($request->query('search', ''));
        $params = $search ? ["labels" => [strtolower($search), strtoupper($search)]] : [];

        $trackers = collect($this->apiService->getTrackers($params)['list'])
            ->map(fn($tracker) => [
                'value' => $tracker['id'],
                'label' => $tracker['label']
            ]);

        return response()->json($trackers);
    }

    public function getRules(Request $request)
    {
        $events = collect($this->apiService->getEventTypes()['list']);

        $events = $events->filter(function ($event) {
            return !in_array($event['type'], ['speedup', 'driver_assistance']);
        })->values();

        $events = $events->map(function ($event) {
            return [
                'value' => $event['type'] . '$' . $event['id'],
                'label' => $event['name']
            ];
        });

        return response()->json($events);
    }

    public function getRelatedVehicle(Request $request, int $id)
    {
        $vehicle = $this->apiService->getVehicle($id);
        if (!$vehicle['success']) return response()->json(['Failed' => 'Bad Request'], 400);

        $tags = collect($this->apiService->getTags()['list'])->keyBy('id');
        $tracker = collect($this->apiService->getTracker($vehicle['value']['tracker_id']));

        $vehicle['value']['tags'] = collect($tracker['value']['tag_bindings'] ?? [])
            ->map(fn($tag) => $tags[$tag['tag_id']] ?? null)
            ->values();

        $driver = collect($this->apiService->getEmployees()['list'])->firstWhere('tracker_id', $vehicle['value']['tracker_id']);
        $vehicle['value']['driver'] = $driver;

        return response()->json($vehicle);
    }

    public function getGroups(Request $request)
    {
        $groups = collect($this->apiService->getGroups()['list'])->map(fn($group) => [
            'value' => $group['id'],
            'label' => $group['title'],
        ]);

        $groups->push([
            'value' => 0,
            'label' => 'Grupo Principal',
        ]);

        return response()->json($groups);
    }
}

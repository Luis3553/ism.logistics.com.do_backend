<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Service\ProGpsApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationsController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}
    public function getNotifications(Request $request)
    {
        // Step 0: Parameters
        $groupBy = $request->query('groupBy', 'groups');

        $trackersFilter = $request->query('trackers', 'all');
        $groupsFilter = $request->query('groups', 'all');
        $notificationsFilter = $request->query('notifications', 'all');

        $trackersFilter = $trackersFilter === 'all' ? null : explode(',', $trackersFilter);
        $groupsFilter = $groupsFilter === 'all' ? null : explode(',', $groupsFilter);
        $notificationsFilter = $notificationsFilter === 'all' ? null : explode(',', $notificationsFilter);

        $from = $request->query('from', now()->subDays(119)->startOfDay()->format('Y-m-d H:i:s'));
        $to = $request->query('to', now()->endOfDay()->format('Y-m-d H:i:s'));

        // Step 1: Fetch API data
        $responses = $this->apiService->fetchBatchRequests([
            'trackers' => 'tracker/list',
            'groups' => 'tracker/group/list',
            'rules' => 'tracker/rule/list'
        ]);

        $trackers = $responses['trackers']['list'];
        $groups = $responses['groups']['list'];
        $rules = $responses['rules']['list'];

        $notifications = $this->apiService->getHistoryOfTrackers(collect($trackers)->pluck('id'), $from, $to);
        if (!isset($notifications['list'])) {
            return response()->json(['failed' => 'bad request']);
        }
        $notifications = $notifications['list'];

        // Step 2: Index maps
        $groupsMap = collect($groups)->keyBy('id');
        $trackersMap = collect($trackers)->keyBy('id');
        $rulesMap = collect($rules)->keyBy('id');

        // Step 3: Event categorization
        $eventPairs = [
            "track_end" => "track_start",
            "excessive_driving_start" => "excessive_driving_end",
            "excessive_parking" => "excessive_parking_finished",
            "idle_start" => "idle_end",
            "fueling" => "drain",
            "cruise_control_on" => "cruise_control_off",
            "distance_breached" => "distance_restored",
            "driver_absence" => "driver_enter",
            "driver_distraction_started" => "driver_distraction_finished",
            "fatigue_driving" => "fatigue_driving_finished",
            "driver_identified" => "driver_not_identified",
            "proximity_violation_start" => "proximity_violation_end",
            "bracelet_open" => "bracelet_close",
            "obd_plug_in" => "obd_unplug",
            "external_device_connected" => "external_device_disconnected",
            "battery_off" => "battery_on",
            "gps_lost" => "gps_recover",
            "light_sensor_bright" => "light_sensor_dark",
            "lock_opened" => "lock_closed",
            "strap_bolt_cut" => "strap_bolt_ins",
            "poweroff" => "poweron",
            "vibration_start" => "vibration_end",
            "sensor_outrange" => "sensor_inrange",
            "inzone" => "outzone"
        ];

        $selfContained = [
            "over_speed_reported",
            "param",
            "outroute",
            "work_status_change",
            "harsh_driving",
            "auto_geofence_out",
            "crash_alarm",
            "driver_changed",
            "g_sensor",
            "no_movement",
            "sos",
            "parking",
            "backup_battery_low",
            "call_button_pressed",
            "alarmcontrol",
            "case_opened",
            "check_engine_light",
            "door_alarm",
            "antenna_disconnect",
            "gps_damp",
            "gsm_damp",
            "hood_alarm",
            "ignition",
            "location_response",
            "lowpower",
            "detach",
            "poweroff",
            "state_field_control",
            "over_speed_reported",
            "param",
            "outroute",
            "work_status_change",
            "harsh_driving",
            "auto_geofence_out",
            "crash_alarm",
            "driver_changed",
            "g_sensor",
            "no_movement",
            "sos",
            "parking",
            "backup_battery_low",
            "call_button_pressed",
            "alarmcontrol",
            "case_opened",
            "check_engine_light",
            "door_alarm",
            "antenna_disconnect",
            "gps_damp",
            "gsm_damp",
            "hood_alarm",
            "ignition",
            "location_response",
            "lowpower",
            "detach",
            "poweroff",
            "state_field_control"
        ];
        $validDisplayEvents = array_merge($selfContained, array_keys($eventPairs));

        // Step 4: Sort and parse notifications
        usort($notifications, fn($a, $b) => strtotime($a['time']) <=> strtotime($b['time']));
        $sessions = $this->buildSessions($notifications, $eventPairs, $selfContained);

        // Step 5: Filter valid sessions
        $sessions = array_filter($sessions, fn($s) => in_array($s['event'], $validDisplayEvents, true));
        // Log::info($sessions);

        // Step 6: Group and respond
        return match ($groupBy) {
            'notifications' => $this->groupByNotification($sessions, $trackersMap, $groupsMap, $rulesMap, $trackersFilter, $groupsFilter, $notificationsFilter),
            'tracker' => $this->groupByTracker($sessions, $trackersMap, $rulesMap, $trackersFilter, $notificationsFilter),
            default => $this->groupByGroup($sessions, $trackersMap, $groupsMap, $rulesMap, $trackersFilter, $groupsFilter, $notificationsFilter)
        };
    }

    private function buildSessions(array $notifications, array $eventPairs, array $selfContained): array
    {
        $open = [];
        $sessions = [];

        foreach ($notifications as $n) {
            $trackerId = $n['tracker_id'];
            $evt = $n['event'];
            $time = $n['time'];

            if (
                !in_array($evt, $selfContained, true) &&
                !array_key_exists($evt, $eventPairs) &&
                !in_array($evt, array_values($eventPairs), true)
            ) continue;

            if (in_array($evt, $selfContained, true)) {
                $sessions[] = $this->createSession($trackerId, $evt, $time, $time, '00s', $n);
            } elseif (isset($eventPairs[$evt])) {
                $open[$trackerId][$evt] = $n;
            } elseif ($startEvent = array_search($evt, $eventPairs, true)) {
                if (isset($open[$trackerId][$startEvent])) {
                    $startNotif = $open[$trackerId][$startEvent];
                    unset($open[$trackerId][$startEvent]);

                    $duration = \Carbon\Carbon::parse($startNotif['time'])
                        ->diff(\Carbon\Carbon::parse($time))
                        ->format('%Hh %Imin');

                    $sessions[] = $this->createSession($trackerId, $startEvent, $startNotif['time'], $time, $duration, $startNotif);
                } else {
                    $sessions[] = $this->createSession($trackerId, $evt, $time, $time, '00s', $n);
                }
            }
        }

        foreach ($open as $tid => $starts) {
            foreach ($starts as $evt => $notif) {
                $sessions[] = $this->createSession($tid, $evt, $notif['time'], null, null, $notif);
            }
        }

        return $sessions;
    }

    private function createSession($tracker_id, $event, $start, $end, $duration, $notification): array
    {
        return compact('tracker_id', 'event', 'start', 'end', 'duration', 'notification');
    }

    private function groupByNotification(array $sessions, $trackersMap, $groupsMap, $rulesMap, $trackersFilter, $groupsFilter, $notificationsFilter)
    {
        $grouped = [];

        foreach ($sessions as $session) {
            if (
                ($trackersFilter && !in_array($session['tracker_id'], $trackersFilter)) ||
                ($groupsFilter && !in_array($tracker['group_id'] ?? 0, $groupsFilter)) ||
                ($notificationsFilter && !in_array($session['event'], $notificationsFilter))
            ) continue;


            $tracker = $trackersMap->get($session['tracker_id']);
            if (!$tracker) continue;

            $groupId = $tracker['group_id'] ?? 0;
            $groupName = $groupsMap[$groupId]['title'] ?? 'Sin agrupar';
            $notificationName = $this->apiService->translateEventType($session['event']);
            $isEmergency = !empty($rulesMap[$session['notification']['rule_id']]['extended_params']['emergency']);

            $trackerData = $this->formatTrackerData($tracker, $session, $notificationName, $isEmergency);

            $notificationIndex = collect($grouped)->search(fn($n) => $n['name'] === $notificationName);
            if ($notificationIndex === false) {
                $grouped[] = [
                    'name' => $notificationName,
                    'groups' => [[
                        'name' => $groupName,
                        'trackers' => [$trackerData]
                    ]]
                ];
            } else {
                $group = &$grouped[$notificationIndex];
                $groupInList = collect($group['groups'])->search(fn($g) => $g['name'] === $groupName);

                if ($groupInList === false) {
                    $group['groups'][] = [
                        'name' => $groupName,
                        'trackers' => [$trackerData]
                    ];
                } else {
                    $group['groups'][$groupInList]['trackers'][] = $trackerData;
                }
            }
        }

        foreach ($grouped as &$group) {
            foreach ($group['groups'] as &$g) {
                usort($g['trackers'], fn($a, $b) => strtotime($b['_raw_start']) <=> strtotime($a['_raw_start']));
                foreach ($g['trackers'] as &$t) unset($t['_raw_start']);
            }
        }

        return response()->json(['notifications' => $grouped]);
    }

    private function groupByTracker(array $sessions, $trackersMap, $rulesMap, $trackersFilter, $notificationsFilter)
    {
        $grouped = [];

        foreach ($sessions as $session) {
            if (
                ($trackersFilter && !in_array($session['tracker_id'], $trackersFilter)) ||
                ($notificationsFilter && !in_array($session['event'], $notificationsFilter))
            ) continue;

            $tracker = $trackersMap->get($session['tracker_id']);
            if (!$tracker) continue;

            $notificationName = $this->apiService->translateEventType($session['event']);
            $isEmergency = !empty($rulesMap[$session['notification']['rule_id']]['extended_params']['emergency']);
            $trackerData = $this->formatTrackerData($tracker, $session, $notificationName, $isEmergency);

            $trackerId = $tracker['id'];

            // Initialize tracker data
            if (!isset($grouped[$trackerId])) {
                $grouped[$trackerId] = [
                    'id' => $trackerId,
                    'name' => $tracker['label'],
                    'alerts' => []
                ];
            }

            // Group alerts by name
            $alertName = $trackerData['name'];
            $grouped[$trackerId]['alerts'][$alertName]['name'] ??= $alertName;
            $grouped[$trackerId]['alerts'][$alertName]['events'][] = $trackerData;
        }

        // Sort alerts and clean up
        foreach ($grouped as &$tracker) {
            foreach ($tracker['alerts'] as &$alertGroup) {
                usort($alertGroup['events'], fn($a, $b) => strtotime($b['_raw_start']) <=> strtotime($a['_raw_start']));
                foreach ($alertGroup['events'] as &$event) unset($event['_raw_start']);
            }
            // Convert associative alerts map to array
            $tracker['alerts'] = array_values($tracker['alerts']);
        }

        return response()->json(['trackers' => array_values($grouped)]);
    }

    private function groupByGroup(array $sessions, $trackersMap, $groupsMap, $rulesMap, $trackersFilter, $groupsFilter, $notificationsFilter)
    {

        foreach ($sessions as $session) {

            $tracker = $trackersMap->get($session['tracker_id']);
            if (!$tracker) continue;

            if (
                ($trackersFilter && !in_array($session['tracker_id'], $trackersFilter)) ||
                ($groupsFilter && !in_array($tracker['group_id'] ?? 0, $groupsFilter)) ||
                ($notificationsFilter && !in_array($session['notification']['rule_id'], $notificationsFilter))
            ) continue;

            $groupId = $tracker['group_id'] ?? 0;
            $groupName = $groupsMap[$groupId]['title'] ?? 'Sin Agrupar';
            $notificationName = $this->apiService->translateEventType($session['event']);
            $isEmergency = !empty($rulesMap[$session['notification']['rule_id']]['extended_params']['emergency']);

            $trackerData = $this->formatTrackerData($tracker, $session, $notificationName, $isEmergency);

            $grouped[$groupId]['id'] ??= $groupId;
            $grouped[$groupId]['name'] ??= $groupName;

            $notificationIndex = collect($grouped[$groupId]['notifications'] ?? [])->search(fn($n) => $n['name'] === $notificationName);

            if ($notificationIndex === false) {
                $grouped[$groupId]['notifications'][] = [
                    'name' => $notificationName,
                    'trackers' => [$trackerData]
                ];
            } else {
                $grouped[$groupId]['notifications'][$notificationIndex]['trackers'][] = $trackerData;
            }
        }

        foreach ($grouped as &$group) {
            foreach ($group['notifications'] as &$notification) {
                usort($notification['trackers'], fn($a, $b) => strtotime($b['_raw_start']) <=> strtotime($a['_raw_start']));
                foreach ($notification['trackers'] as &$t) unset($t['_raw_start']);
            }
        }

        $final = array_values(array_filter($grouped, fn($g) => !empty($g['notifications'])));
        return response()->json(['groups' => $final]);
    }

    private function formatTrackerData($tracker, $session, $notificationName, $isEmergency)
    {
        $vehicle_id = collect($session['notification']['assets'] ?? [])->firstWhere('type', 'vehicle')['id'] ?? null;

        return [
            'notification_id' => $session['notification']['id'],
            'name' => $tracker['label'],
            'vehicle_id' => $vehicle_id,
            'emergency' => $isEmergency,
            'start_date' => \Carbon\Carbon::parse($session['start'])->format('d/m/Y h:i:s A'),
            '_raw_start' => $session['start'],
            'end_date' => $session['end'] ? \Carbon\Carbon::parse($session['end'])->format('d/m/Y h:i:s A') : '-',
            'latitude' => $session['notification']['location']['lat'],
            'longitude' => $session['notification']['location']['lng'],
            'address' => $session['notification']['address'],
            'time' => $session['duration'] ?? '-',
        ];
    }


    public function getRelatedVehicle(Request $request, int $id)
    {
        $vehicle = $this->apiService->getVehicle($id);
        if (!$vehicle['success']) return response()->json(['Failed' => 'Bad Request'], 400);

        $tags = collect($this->apiService->getTags()['list']);
        $vehicle['value']['tags'] = collect($vehicle['value']['tags'] ?? [])
            ->map(fn($tagId) => $tags->firstWhere('id', $tagId))
            ->filter()
            ->values();

        $driver = collect($this->apiService->getEmployees()['list'])->firstWhere('tracker_id', $vehicle['value']['tracker_id']);
        $vehicle['value']['driver'] = $driver;

        return response()->json($vehicle);
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
        $events = collect($this->apiService->getEventTypes()['list'])->map(fn($event) => [
            'value' => $event['id'],
            'label' => $event['name']
        ]);

        return response()->json($events);
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

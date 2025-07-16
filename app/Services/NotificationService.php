<?php

namespace App\Services;

use App\Services\ProGpsApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class NotificationService
{
    public ProGpsApiService $apiService;

    public function __construct($hash)
    {
        $this->apiService = new ProGpsApiService($hash);
    }

    public function getNotifications($params)
    {
        // Step 0: Parameters
        $groupBy = $params['groupBy'] ?? 'groups'; // groups / notifications / trackers
        $trackersFilter = isset($params['trackers']) ? (is_array($params['trackers']) ? $params['trackers'] : explode(',', $params['trackers'])) : [];
        $groupsFilter = isset($params['groups']) ? (is_array($params['groups']) ? $params['groups'] : explode(',', $params['groups'])) : [];
        $notificationsFilter = isset($params['notifications']) ? (is_array($params['notifications']) ? $params['notifications'] : explode(',', $params['notifications'])) : [];

        $from = $params['from'] ?? now()->subDays(119)->startOfDay()->format('Y-m-d H:i:s');
        $to = $params['to'] ?? now()->endOfDay()->format('Y-m-d H:i:s');

        // Step 1: Fetch API data
        $responses = $this->apiService->fetchBatchRequests([
            ['key' => 'trackers'],
            ['key' => 'groups'],
            ['key' => 'event_types'],
        ]);

        $trackers = $responses['trackers']['list'];
        $groups = $responses['groups']['list'];
        if ($groupBy === 'groups' || $groupBy === 'notifications') {
            $trackers = collect($trackers)->filter(function ($tracker) use ($groupsFilter) {
                return in_array($tracker['group_id'], $groupsFilter);
            })->values()->toArray();
        }
        $rules = $responses['event_types']['list'];

        $helper = [
            "battery_off" => ["battery_off"],
            "offline" => ["gps_lost", "gps_recover"],
            "state_field_control" => ["state_field_control"],
            "inoutzone" => ["outzone", "inzone"],
            "track_change" => ["track_start", "track_end"],
            "over_speed_reported" => ["over_speed_reported"],
            // "speedup" => ["speedup"], // not gonna be used for now, to see details about speedup generate a report
            "route" => ["outroute"],
            "excessive_driving" => ["excessive_driving_end", "excessive_driving_start"],
            "excessive_parking" => ["excessive_parking_finished", "excessive_parking"],
            "task_status_change" => ["task_status_change"],
            "work_status_change" => ["work_status_change"],
            "idling_soft" => ["idle_end", "idle_start"],
            "fuel_level_leap" => ["drain", "fueling"],
            "harsh_driving" => ["harsh_driving"],
            // "driver_assistance" => ["driver_assistance"], // unknown events types
            "auto_geofence" => ["auto_geofence_out"],
            "autocontrol" => ["autocontrol"],
            "crash_alarm" => ["crash_alarm"],
            "cruise_control" => ["cruise_control_off", "cruise_control_on"],
            "distance_control" => ["distance_restored", "distance_breached"],
            "driver_enter_absence" => ["driver_enter", "driver_absence"],
            "driver_change" => ["driver_changed"],
            "driver_distraction" => ["driver_distraction_finished", "driver_distraction_started"],
            "g_sensor" => ["g_sensor"],
            "fatigue_driving" => ["fatigue_driving_finished", "fatigue_driving"],
            "driver_identification" => ["driver_not_identified", "driver_identified"],
            "no_movement" => ["no_movement"],
            "sos" => ["sos"],
            "proximity_violation" => ["proximity_violation_end", "proximity_violation_start"],
            "parking" => ["parking"],
            "backup_battery_low" => ["backup_battery_low"],
            "bracelet" => ["bracelet_close", "bracelet_open"],
            "call_button_pressed" => ["call_button_pressed"],
            "alarmcontrol" => ["alarmcontrol"],
            "case_intrusion" => ["case_opened"],
            "check_engine_light" => ["check_engine_light"],
            "obd_plug_unplug" => ["obd_unplug", "obd_plug_in"],
            "door_alarm" => ["door_alarm"],
            "external_device_connection" => ["external_device_disconnected", "external_device_connected"]
        ];

        // Resolve notifications from user input
        $convertNotificationsToMatchedEventTypes = collect($notificationsFilter)
            ->flatMap(function ($key) use ($helper) {
                $parts = explode('$', $key);
                $mainKey = $parts[0];
                return $helper[$mainKey] ?? [];
            })->unique()->values()->toArray();

        // Step 2: Get filtered notifications
        $notifications = $this->apiService->getHistoryOfTrackers(
            $trackersFilter,
            $from,
            $to,
            $convertNotificationsToMatchedEventTypes
        );

        if (!isset($notifications['list'])) return response()->json(['failed' => 'bad request']);
        $notifications = $notifications['list'];

        $notificationsIdsFilter = collect($notificationsFilter)
            ->flatMap(function ($key) {
                $parts = explode('$', $key);
                return [$parts[1]];
            })
            ->unique()
            ->values()
            ->toArray();

        // Step 2: Index maps
        $groupsMap = collect($groups)->keyBy('id');
        $trackersMap = collect($trackers)->keyBy('id');
        $rulesMap = collect($rules)->keyBy('id');

        // Step 3: Event categorization
        $eventPairs = [
            "inzone" => "outzone",
            "track_start" => "track_end",
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
            "gps_lost" => "gps_recover"
        ];

        $selfContained = [
            "state_field_control",
            "over_speed_reported",
            // "speedup",
            "outroute",
            "task_status_change",
            "work_status_change",
            "idling",
            "harsh_driving",
            // "driver_assistance",
            "auto_geofence_out",
            "autocontrol",
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
            "door_alarm"
        ];

        $validDisplayEvents = array_merge($selfContained, array_keys($eventPairs));

        // Step 4: Sort and parse notifications
        usort($notifications, fn($a, $b) => strtotime($a['time']) <=> strtotime($b['time']));
        $sessions = $this->buildSessions($notifications, $eventPairs, $selfContained);

        // Step 5: Filter valid sessions
        $sessions = array_filter($sessions, fn($s) => in_array($s['event'], $validDisplayEvents, true));

        // Step 6: Group and respond
        return match ($groupBy) {
            'notifications' => $this->groupByNotification($sessions, $trackersMap, $groupsMap, $rulesMap, $groupsFilter, $notificationsIdsFilter),
            'trackers' => $this->groupByTracker($sessions, $trackersMap, $rulesMap, $notificationsIdsFilter),
            default => $this->groupByGroup($sessions, $trackersMap, $groupsMap, $rulesMap, $groupsFilter, $notificationsIdsFilter)
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

    private function groupByNotification(array $sessions, $trackersMap, $groupsMap, $rulesMap, $groupsFilter, $notificationsIdsFilter)
    {
        $grouped = [];

        foreach ($sessions as $session) {
            $tracker = $trackersMap->get($session['tracker_id']);
            if (!$tracker) continue;

            if (
                !collect($notificationsIdsFilter)->contains(function ($idFilter) use ($session) {
                    return str_contains((string)$session['notification']['rule_id'], (string)$idFilter);
                })
            ) continue;

            if (!in_array($tracker['group_id'], $groupsFilter)) continue;

            $groupId = $tracker['group_id'];
            $groupName = $groupsMap[$groupId]['title'] ?? 'Sin agrupar';
            $notificationName = $rulesMap[$session['notification']['rule_id']]['name'];
            $isEmergency = !empty($rulesMap[$session['notification']['rule_id']]['extended_params']['emergency']);

            $trackerData = $this->formatTrackerData($tracker, $session, $notificationName, $isEmergency);

            $notificationIndex = collect($grouped)->search(fn($n) => $n['name'] === $notificationName);
            if ($notificationIndex === false) {
                $grouped[] = [
                    'id' => $groupId,
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

    private function groupByTracker(array $sessions, $trackersMap, $rulesMap, $notificationsIdsFilter)
    {
        $grouped = [];

        foreach ($sessions as $session) {
            $tracker = $trackersMap->get($session['tracker_id']);

            if (
                !$tracker ||
                !collect($notificationsIdsFilter)->contains(function ($idFilter) use ($session) {
                    return str_contains((string)$session['notification']['rule_id'], (string)$idFilter);
                })
            ) continue;

            $notificationName = $rulesMap[$session['notification']['rule_id']]['name'];
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
            $alertName = $notificationName;
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

    private function groupByGroup(array $sessions, $trackersMap, $groupsMap, $rulesMap, $groupsFilter, $notificationsIdsFilter)
    {
        $grouped = [];
        foreach ($sessions as $session) {
            $tracker = $trackersMap->get($session['tracker_id']);
            if (!$tracker) continue;


            if (
                !collect($notificationsIdsFilter)->contains(function ($idFilter) use ($session) {
                    return str_contains((string)$session['notification']['rule_id'], (string)$idFilter);
                })
            ) continue;

            if (!in_array($tracker['group_id'], $groupsFilter)) continue;

            $groupId = $tracker['group_id'];
            $groupName = $groupsMap[$groupId]['title'] ?? 'Sin Agrupar';
            $notificationName = $rulesMap[$session['notification']['rule_id']]['name'];
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

    public function test(Request $request)
    {
        set_time_limit(5000); // 5 minutes

        $idsString = implode(' ', []);
        $venvPython = escapeshellarg(base_path('python-processor/venv/Scripts/python.exe'));
        $script = escapeshellarg(base_path('python-processor/main.py'));
        $cmd = "$venvPython $script $idsString";

        $output = shell_exec($cmd);

        $results = json_decode($output, true);
        return response()->json($results);
    }
}

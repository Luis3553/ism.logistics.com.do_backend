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
        $groupBy = $request->query('groupBy', 'groups'); // 'groups' or 'notifications'
        $search = $request->query('s', '');
        $from = $request->query('from', now()->startOfDay()->format('Y-m-d H:i:s'));
        $to = $request->query('to', now()->endOfDay()->format('Y-m-d H:i:s'));

        $endpoints = [
            'trackers' => 'tracker/list',
            'groups' => 'tracker/group/list',
            'rules' => 'tracker/rule/list'
        ];

        $responses = $this->apiService->fetchBatchRequests($endpoints);

        $trackers = $responses['trackers']['list'];
        $groups = $responses['groups']['list'];
        $rules = $responses['rules']['list'];
        $notifications = $this->apiService->getHistoryOfTrackers(collect($trackers)->pluck('id'), $from, $to);

        if (isset($notifications['list'])) $notifications = $notifications['list'];
        else return response()->json(['failed' => 'bad request']);

        // Map helpers
        $groupsMap = collect($groups)->keyBy('id');
        $trackersMap = collect($trackers)->keyBy('id');
        $rulesMap = collect($rules)->keyBy('id');

        // Event logic
        $eventPairs = [
            'idle_start' => 'idle_end',
            'auto_geofence_in' => 'auto_geofence_out',
            'bracelet_close' => 'bracelet_open',
            'case_closed' => 'case_opened',
            'cruise_control_off' => 'cruise_control_on',
            'detach' => 'attach',
            'gps_lost' => 'gps_recover',
            'inroute' => 'outroute',
            'inzone' => 'outzone',
            'lock_closed' => 'lock_opened',
            'obd_plug_in' => 'obd_unplug',
            'offline' => 'online',
            'poweroff' => 'poweron',
            'track_end' => 'track_start',
            'sensor_inrange' => 'sensor_outrange',
            'strap_bolt_cut' => 'strap_bolt_ins',
            'vibration_start' => 'vibration_end',
            'fatigue_driving' => 'fatigue_driving_finished',
            'distance_breached' => 'distance_restored',
            'excessive_parking' => 'excessive_parking_finished',
            'excessive_driving_start' => 'excessive_driving_end',
            'driver_absence' => 'driver_enter',
            'driver_distraction_started' => 'driver_distraction_finished',
            'external_device_connected' => 'external_device_disconnected',
            'proximity_violation_start' => 'proximity_violation_end',
        ];

        $selfContained = [
            'alarmcontrol',
            'battery_off',
            'crash_alarm',
            'door_alarm',
            'force_location_request',
            'forward_collision_warning',
            'g_sensor',
            'gsm_damp',
            'gps_damp',
            'harsh_driving',
            'headway_warning',
            'hood_alarm',
            'ignition',
            'info',
            'input_change',
            'output_change',
            'lane_departure',
            'light_sensor_bright',
            'light_sensor_dark',
            'lowpower',
            'odometer_set',
            'over_speed_reported',
            'parking',
            'peds_collision_warning',
            'peds_in_danger_zone',
            'sos',
            'speedup',
            'tracker_rename',
            'work_status_change',
            'call_button_pressed',
            'driver_changed',
            'driver_identified',
            'driver_not_identified',
            'fueling',
            'drain',
            'checkin_creation',
            'tacho',
            'antenna_disconnect',
            'check_engine_light',
            'location_response',
            'backup_battery_low',
            'no_movement',
            'state_field_control'
        ];

        // Step 1: Sort notifications by time
        usort($notifications, fn($a, $b) => strtotime($a['time']) <=> strtotime($b['time']));

        $open = [];
        $sessions = [];

        $validDisplayEvents = array_merge($selfContained, array_keys($eventPairs));

        foreach ($notifications as $n) {
            $trackerId = $n['tracker_id'];
            $evt = $n['event'];
            $time = $n['time'];

            // Skip events that are not in selfContained or eventPairs (either key or value)
            if (
                !in_array($evt, $selfContained, true) &&
                !array_key_exists($evt, $eventPairs) &&
                !in_array($evt, array_values($eventPairs), true)
            ) {
                continue;
            }

            if (in_array($evt, $selfContained, true)) {
                $sessions[] = [
                    'tracker_id' => $trackerId,
                    'event' => $evt,
                    'start' => $time,
                    'end' => $time,
                    'duration' => '00s',
                    'notification' => $n,
                ];
                continue;
            }

            if (isset($eventPairs[$evt])) {
                $open[$trackerId][$evt] = $n;
                continue;
            }

            $startEvent = array_search($evt, $eventPairs, true);
            if ($startEvent !== false && isset($open[$trackerId][$startEvent])) {
                $startNotif = $open[$trackerId][$startEvent];
                unset($open[$trackerId][$startEvent]);

                $sessions[] = [
                    'tracker_id' => $trackerId,
                    'event' => $startEvent,
                    'start' => $startNotif['time'],
                    'end' => $time,
                    'duration' => \Carbon\Carbon::parse($startNotif['time'])
                        ->diff(\Carbon\Carbon::parse($time))
                        ->format('%Hh %Imin'),
                    'notification' => $startNotif,
                ];
            } else {
                $sessions[] = [
                    'tracker_id' => $trackerId,
                    'event' => $evt,
                    'start' => $time,
                    'end' => $time,
                    'duration' => '00s',
                    'notification' => $n,
                ];
            }
        }

        // Handle open sessions without an end
        foreach ($open as $tid => $starts) {
            foreach ($starts as $evt => $notif) {
                $sessions[] = [
                    'tracker_id' => $tid,
                    'event' => $evt,
                    'start' => $notif['time'],
                    'end' => null,
                    'duration' => null,
                    'notification' => $notif,
                ];
            }
        }

        // Filter sessions to include only displayable events
        $sessions = array_filter($sessions, function ($session) use ($validDisplayEvents) {
            return in_array($session['event'], $validDisplayEvents, true);
        });

        // Step 2: Group sessions
        if ($groupBy === 'notifications') {
            $grouped = [];

            foreach ($sessions as $session) {
                $tracker = $trackersMap->get($session['tracker_id']);
                if (!$tracker) continue;

                $groupId = $tracker['group_id'] ?? 0;
                $groupName = $groupsMap[$groupId]['title'] ?? 'Sin agrupar';
                $notificationName = $this->apiService->translateEventType($session['event']);
                $isEmergency = !empty($rulesMap[$session['notification']['rule_id']]['extended_params']['emergency']);

                $trackerData = [
                    'notification_id' => $session['notification']['id'],
                    'name' => $tracker['label'],
                    'vehicle_id' => $session['notification']['assets'][0]['id'] ?? null,
                    'emergency' => $isEmergency,
                    'start_date' => \Carbon\Carbon::parse($session['start'])->format('d/m/Y h:i:s a'),
                    'end_date' => $session['end'] ? \Carbon\Carbon::parse($session['end'])->format('d/m/Y h:i:s a') : '-',
                    'address' => $session['notification']['address'],
                    'time' => $session['duration'] ?? '-',
                ];

                $notificationIndex = collect($grouped)->search(fn($n) => $n['name'] === $notificationName);

                if ($notificationIndex === false) {
                    $grouped[] = [
                        'name' => $notificationName,
                        'groups' => [
                            [
                                'name' => $groupName,
                                'trackers' => [$trackerData]
                            ]
                        ]
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

            return response()->json(['notifications' => $grouped]);
        } else {
            // Default: group by group ID
            $grouped = [];

            foreach ($sessions as $session) {
                $tracker = $trackersMap->get($session['tracker_id']);
                if (!$tracker) continue;

                $groupId = $tracker['group_id'] ?? 0;
                $groupName = $groupsMap[$groupId]['title'] ?? 'Sin Agrupar';
                $notificationName = $this->apiService->translateEventType($session['event']);
                $isEmergency = !empty($rulesMap[$session['notification']['rule_id']]['extended_params']['emergency']);

                $trackerData = [
                    'notification_id' => $session['notification']['id'],
                    'name' => $tracker['label'],
                    'vehicle_id' => $session['notification']['assets'][0]['id'] ?? null,
                    'emergency' => $isEmergency,
                    'address' => $session['notification']['address'],
                    'start_date' => \Carbon\Carbon::parse($session['start'])->format('d/m/Y h:i:s a'),
                    'end_date' => $session['end'] ? \Carbon\Carbon::parse($session['end'])->format('d/m/Y h:i:s a') : '-',
                    'time' => $session['duration'] ?? '-',
                ];

                if (!isset($grouped[$groupId])) {
                    $grouped[$groupId] = [
                        'id' => $groupId,
                        'name' => $groupName,
                        'notifications' => []
                    ];
                }

                $notificationIndex = collect($grouped[$groupId]['notifications'])->search(fn($n) => $n['name'] === $notificationName);

                if ($notificationIndex === false) {
                    $grouped[$groupId]['notifications'][] = [
                        'name' => $notificationName,
                        'trackers' => [$trackerData]
                    ];
                } else {
                    $grouped[$groupId]['notifications'][$notificationIndex]['trackers'][] = $trackerData;
                }
            }

            $final = array_values(array_filter($grouped, fn($g) => !empty($g['notifications'])));
            return response()->json(['groups' => $final]);
        }
    }

    public function getRelatedVehicle(Request $request, int $id)
    {
        $vehicle = $this->apiService->getVehicle($id);
        return response()->json($vehicle);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\ScheduleRouteTaskResource;
use App\Services\ProGpsApiService;
use App\Models\ScheduleRouteTask;
use App\Validators\ScheduleRouteTask\ScheduleRouteTaskValidator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScheduleRouteTaskController extends Controller
{
    public function __construct(private ProGpsApiService $apiService) {}

    public function getScheduleTaskList(Request $request)
    {
        $userId = $request->attributes->get('user')->user_id;

        $alreadyExistConfigs = ScheduleRouteTask::where('user_id', $userId)
            ->pluck('task_id')
            ->toArray();

        $tasks = collect($this->apiService->getScheduleTasks(['types' => ['task', 'route']])['list'])
            ->filter(function ($task) use ($alreadyExistConfigs) {
                return !in_array($task['id'], $alreadyExistConfigs);
            })
            ->sortBy('created_at') // Correct method for collections
            ->map(function ($task) {
                return [
                    'value' => $task['id'],
                    'label' => $task['label'],
                ];
            })
            ->values()
            ->toArray();

        return response()->json(['list' => $tasks, 'success' => true], 200);
    }

    public function getConfigsForScheduleTasks(Request $request)
    {
        $trackersIds = json_decode($request->query('trackers', []));
        $userId = $request->attributes->get('user')->user_id;

        $tasks = ScheduleRouteTask::where('user_id', $userId)
            ->whereIn('tracker_id', $trackersIds)
            ->get();

        $responses = $this->apiService->fetchBatchRequests([
            ['key' => 'trackers'],
            ['key' => 'schedule_list', 'params' => ['types' => ['task', 'route'], 'trackers' => $trackersIds]],
            ['key' => 'employees'],
        ]);

        $sharedData = [
            'trackersMap' => collect($responses['trackers']['list'])->keyBy('id')->toArray(),
            'tasksMap' => collect($responses['schedule_list']['list'])->keyBy('id')->toArray(),
            'employeesMap' => collect($responses['employees']['list'])->keyBy('id')->toArray(),
        ];

        $resources = collect($tasks)->map(fn($task) => new ScheduleRouteTaskResource($task, $sharedData));

        return response()->json([
            'list' => $resources,
            'success' => true,
        ]);
    }

    public function createScheduleTask(Request $request)
    {
        $validator = ScheduleRouteTaskValidator::validate($request->all());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->attributes->get('user');

        $task = ScheduleRouteTask::create([
            'user_id' => $user->user_id,
            'task_id' => $request->input('task_id'),
            'tracker_id' => $request->input('tracker_id'),
            'frequency' => $request->input('frequency'),
            'frequency_value' => $request->input('frequency_value'),
            'days_of_week' => $request->input('days_of_week'),
            'weekday_ordinal' => $request->input('weekday_ordinal', null),
            'start_date' => Carbon::parse($request->input('start_date'))->format('Y-m-d'),
        ]);

        if (!$task) return response()->json(['message' => 'Failed to create task'], 500);

        return response()->json([
            'message' => 'Task created successfully',
            'data' => new ScheduleRouteTaskResource($task),
        ], 201);
    }

    public function deleteScheduleTask(Request $request, $id)
    {
        $userId = $request->attributes->get('user')->user_id;

        $task = ScheduleRouteTask::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$task) return response()->json(['message' => 'Task not found or you do not have permission to delete it'], 404);

        $task->delete();

        return response()->json(['message' => 'Task deleted successfully'], 200);
    }

    public function updateScheduleTask(Request $request, $id)
    {
        $validator = ScheduleRouteTaskValidator::validate($request->all());
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $userId = $request->attributes->get('user')->user_id;

        $task = ScheduleRouteTask::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        $tracker = $this->apiService->getTracker($request->input('tracker_id'));

        if (!$task) return response()->json(['message' => 'Task not found or you do not have permission to update it'], 404);

        $task->update([
            'tracker_id' => $request->input('tracker_id'),
            'frequency' => $request->input('frequency'),
            'frequency_value' => $request->input('frequency_value'),
            'days_of_week' => $request->input('days_of_week'),
            'is_active' => $request->input('is_active', true),
            'start_date' => Carbon::parse($request->input('start_date'))->format('Y-m-d'),
        ]);

        return response()->json(['message' => 'Task updated successfully', 'data' => array_merge(
            $task->toArray(),
            ['tracker' => $tracker['value']['label'] ?? 'N/A']
        )], 200);
    }
}

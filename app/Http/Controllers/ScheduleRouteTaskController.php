<?php

namespace App\Http\Controllers;

use App\Http\Resources\ScheduleRouteTaskResource;
use App\Services\ProGpsApiService;
use App\Models\ScheduleRouteTask;
use App\Services\RouteTaskService;
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
            ->sortBy('created_at')
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
        $userId = $request->attributes->get('user')->user_id;
        $tasks = ScheduleRouteTask::where('user_id', $userId)->get();

        $sharedData = $this->getSharedDataForTasks();

        $resources = collect($tasks)
            ->filter(fn($task) => isset($sharedData['tasksMap'][$task->task_id]))
            ->map(fn($task) => new ScheduleRouteTaskResource($task, $sharedData))
            ->values();

        return response()->json([
            'list' => $resources,
            'success' => true,
        ]);
    }

    public function createScheduleTask(Request $request)
    {
        $validator = ScheduleRouteTaskValidator::validateForCreate($request->all());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->attributes->get('user');

        $taskData = array_merge(['user_id' => $user->user_id], $request->all());
        $task = ScheduleRouteTask::create($taskData);

        if (!$task) return response()->json(['message' => 'Failed to create task'], 500);

        $routeTaskService = new RouteTaskService($user->hash);
        $routeTaskService->handle(collect([$task]));

        $sharedData = $this->getSharedDataForTasks();

        return response()->json([
            'message' => 'Task created successfully',
            'data' => new ScheduleRouteTaskResource($task, $sharedData),
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
        $validator = ScheduleRouteTaskValidator::validateForUpdate($request->all());
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $userId = $request->attributes->get('user')->user_id;

        $task = ScheduleRouteTask::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$task) return response()->json(['message' => 'Task not found or you do not have permission to update it'], 404);

        $data = $request->all();
        $data['ocurrence_count'] = 0;

        $sharedData = $this->getSharedDataForTasks();

        $task->update($data);

        return response()->json(['message' => 'Task updated successfully', 'data' => new ScheduleRouteTaskResource($task,  $sharedData)], 200);
    }

    public function getSharedDataForTasks()
    {
        $responses = $this->apiService->fetchBatchRequests([
            ['key' => 'trackers'],
            ['key' => 'schedule_list', 'params' => ['types' => ['task', 'route']]],
            ['key' => 'employees'],
        ]);

        return [
            'trackersMap' => collect($responses['trackers']['list'])->keyBy('id')->toArray(),
            'tasksMap' => collect($responses['schedule_list']['list'])->keyBy('id')->toArray(),
            'employeesMap' => collect($responses['employees']['list'])
                ->filter(fn($employee) => !is_null($employee['tracker_id']))
                ->keyBy('tracker_id')
                ->toArray(),
        ];
    }
}

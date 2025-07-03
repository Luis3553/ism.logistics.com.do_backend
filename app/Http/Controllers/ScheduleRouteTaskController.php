<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Service\ProGpsApiService;
use App\Models\ScheduleRouteTask;
use App\Validators\ScheduleRouteTaskValidator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScheduleRouteTaskController extends Controller
{
    public function __construct(private ProGpsApiService $apiService) {}

    public function getScheduleTaskList(Request $request)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $alreadyExistConfigs = ScheduleRouteTask::where('user_id', $userId)
            ->pluck('task_id')
            ->toArray();

        $tasks = collect($this->apiService->getScheduleTasks(['types' => ['task', 'route']])['list'])->filter(function ($task) use ($alreadyExistConfigs) {
            return !in_array($task['id'], $alreadyExistConfigs);
        })->map(function ($task) {
            return [
                'value' => $task['id'],
                'label' => $task['label'],
            ];
        })->values()->toArray();

        return response()->json(['list' => $tasks, 'success' => true], 200);
    }

    public function getConfigsForScheduleTasks(Request $request)
    {
        $trackersIds = json_decode($request->query('trackers', []));

        $trackersMap = collect($this->apiService->getTrackers()['list'])->keyBy('id');
        $tasksMap = collect($this->apiService->getScheduleTasks(['types' => ['task', 'route']])['list'])->keyBy('id');
        $employeesMap = collect($this->apiService->getEmployees()['list'])->keyBy('id');

        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $tasks = ScheduleRouteTask::where('user_id', $userId)
            ->whereIn('tracker_id', $trackersIds)
            ->get();

        $tasks = $tasks->map(function ($task) use ($trackersMap, $employeesMap, $tasksMap) {
            $employee = $employeesMap[$task->tracker_id] ?? null;
            $employeeName = $employee ? "{$employee['first_name']} {$employee['middle_name']} {$employee['last_name']}" : 'N/A';

            return [
                'id' => $task->id,
                'label' => $tasksMap[$task->task_id]['label'],
                'task_id' => $task->task_id,
                'tracker_id' => $task->tracker_id,
                'tracker' => $trackersMap[$task->tracker_id]['label'],
                'employee' => $employeeName,
                'frequency' => $task->frequency,
                'frequency_value' => $task->frequency_value,
                'days_of_week' => $task->days_of_week,
                'is_valid' => $task->is_valid,
                'is_active' => $task->is_active,
                'start_date' => $task->start_date,
            ];
        });

        return response()->json(['list' => $tasks, 'success' => true], 200);
    }

    public function createScheduleTask(Request $request)
    {
        $validator = ScheduleRouteTaskValidator::validate($request->all());
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $task = ScheduleRouteTask::create([
            'user_id' => $userId,
            'task_id' => $request->input('task_id'),
            'tracker_id' => $request->input('tracker_id'),
            'user_hash' => $this->apiService->apiKey,
            'frequency' => $request->input('frequency'),
            'frequency_value' => $request->input('frequency_value'),
            'days_of_week' => $request->input('days_of_week'),
            'start_date' => Carbon::parse($request->input('start_date'))->format('Y-m-d'),
        ]);

        if (!$task) return response()->json(['message' => 'Failed to create task'], 500);

        return response()->json(['message' => 'Task created successfully', 'data' => $task], 201);
    }

    public function deleteScheduleTask(Request $request, $id)
    {
        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

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

        $userId = $this->apiService->getUserInfo()['user_info']['id'] ?? null;
        if (!$userId) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

        $task = ScheduleRouteTask::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$task) return response()->json(['message' => 'Task not found or you do not have permission to update it'], 404);

        $task->update([
            'tracker_id' => $request->input('tracker_id'),
            'frequency' => $request->input('frequency'),
            'frequency_value' => $request->input('frequency_value'),
            'days_of_week' => $request->input('days_of_week'),
            'is_active' => $request->input('is_active', true),
            'start_date' => Carbon::parse($request->input('start_date'))->format('Y-m-d'),
        ]);

        return response()->json(['message' => 'Task updated successfully', 'data' => $task], 200);
    }
}

<?php

namespace App\Http\Resources;

use App\Services\ProGpsApiService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class ScheduleRouteTaskResource extends JsonResource
{
    protected array $trackersMap;
    protected array $tasksMap;
    protected array $employeesMap;
    protected string $baseUrl;

    public function __construct($resource, array $sharedData = [])
    {
        parent::__construct($resource);
        $this->baseUrl = "https://app.progps.com.do/api-v2";
        $this->trackersMap = $sharedData['trackersMap'] ?? [];
        $this->tasksMap = $sharedData['tasksMap'] ?? [];
        $this->employeesMap = $sharedData['employeesMap'] ?? [];
    }

    public function toArray($request)
    {
        $employee = $this->employeesMap[$this->tracker_id] ?? null;
        $employeeName = $employee
            ? trim("{$employee['first_name']} {$employee['middle_name']} {$employee['last_name']}")
            : 'N/A';
        $employeeImageUrl = $employee && isset($employee['avatar_file_name']) ?
            $this->baseUrl . '/static/employee/avatars/' . $employee['avatar_file_name']
            : null;

        match ($this->tasksMap[$this->task_id]['type']) {
            'route' => $checkpoints = collect($this->tasksMap[$this->task_id]['checkpoints'] ?? [])->map(fn($checkpoint) => $checkpoint['label'])->toArray(),
            'task' => $checkpoints = [$this->tasksMap[$this->task_id]['label']],
        };

        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'tracker_id' => $this->tracker_id,
            'label' => $this->tasksMap[$this->task_id]['label'] ?? 'N/A',
            'tracker' => $this->trackersMap[$this->tracker_id]['label'] ?? 'N/A',
            'employee' => $employeeName,
            'frequency' => $this->frequency,
            'frequency_value' => $this->frequency_value,
            'days_of_week' => $this->days_of_week,
            'weekday_ordinal' => $this->weekday_ordinal,
            'checkpoints' => $checkpoints,
            'employee_image_url' => $employeeImageUrl,
            'is_valid' => $this->is_valid,
            'is_active' => $this->is_active,
            'start_date' => $this->start_date,
        ];
    }
}

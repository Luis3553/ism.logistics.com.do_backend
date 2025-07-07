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

    public function __construct($resource, array $sharedData = [])
    {
        parent::__construct($resource);

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
            'is_valid' => $this->is_valid,
            'is_active' => $this->is_active,
            'start_date' => $this->start_date,
        ];
    }
}

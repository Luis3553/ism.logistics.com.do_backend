<?php

namespace App\Console\Commands;

use App\Services\ProGpsApiService;
use App\Models\ScheduleRouteTask;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class generateTaskForTheDay extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'task:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate task or route.';

    /**
     * Execute the console command.
     */

    public function handle()
    {
        try {
            $this->info('Generating tasks for today...');

            $today = Carbon::today();
            $tasksConfigs = ScheduleRouteTask::where('is_valid', true)
                ->where('is_active', true)
                ->get();

            foreach ($tasksConfigs as $taskConfig) {
                $apiService = new ProGpsApiService($taskConfig->user_hash);
                $taskData = $apiService->getScheduleTaskData($taskConfig->task_id);
                $taskType = $taskData['value']['type'];

                if ($this->shouldGenerateTask($taskConfig, $today)) {
                    match ($taskType) {
                        'route' => $this->generateRouteTask($taskConfig, $taskData, $apiService),
                        'task' => $this->generateSimpleTask($taskConfig, $taskData, $apiService),
                    };
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error generating tasks: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function shouldGenerateTask($taskConfig, Carbon $today): bool
    {
        $startDate = Carbon::parse($taskConfig['start_date']);
        $frequencyType = $taskConfig['frequency'];
        $frequency = $taskConfig['frequency_value'];
        $validWeekdays = $taskConfig['days_of_week'];
        $weekdayOrdinal = $taskConfig['weekday_ordinal'] ?? null;

        if (!in_array($today->dayOfWeek, $validWeekdays)) return false;

        if ($frequencyType === 'every_x_weeks') {
            $startOfCycle = $startDate->copy();
            while (!in_array($startOfCycle->dayOfWeek, $validWeekdays)) {
                $startOfCycle->addDay();
            }

            if ($today->lt($startOfCycle)) {
                return false;
            }

            $daysDiff = $startOfCycle->diffInDays($today);
            $weeksSinceStart = floor($daysDiff / 7);

            return $weeksSinceStart % $frequency === 0;
        }

        if ($frequencyType === 'every_x_months') {
            $monthsDiff = $startDate->diffInMonths($today->copy()->startOfMonth());
            if ($monthsDiff % $frequency !== 0) {
                return false;
            }

            foreach ($validWeekdays as $weekday) {
                $expectedDay = $today->copy()->nthOfMonth($weekdayOrdinal, $weekday);
                if ($expectedDay && $today->isSameDay($expectedDay)) {
                    return true;
                }
            }

            return $today->isSameDay($expectedDay);
        }

        return false;
    }

    private function generateRouteTask($taskConfig, $taskData, $apiService): void
    {
        $checkpoints = array_map(function ($checkpoint) use ($taskConfig) {
            $from = Carbon::today()->setTimeFromTimeString($checkpoint['from_time']);
            $to = (clone $from)->addMinutes($checkpoint['duration']);

            return [
                'tracker_id' => $taskConfig['tracker_id'],
                'location' => $checkpoint['location'],
                'label' => $checkpoint['label'],
                'description' => $checkpoint['description'],
                'from' => $from->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                'to' => $to->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                'max_delay' => $checkpoint['max_delay'],
                'min_stay_duration' => $checkpoint['min_stay_duration'],
                'min_arrival_duration' => $checkpoint['min_arrival_duration'],
                'type' => $checkpoint['type'],
                'form_template_id' => $checkpoint['form_template_id'],
                ...(isset($checkpoint['tags']) ? ['tags' => $checkpoint['tags']] : []),
            ];
        }, $taskData['checkpoints']);

        $apiService->createRoute([
            'route' => [
                'tracker_id' => $taskConfig['tracker_id'],
                'label' => $taskData['value']['label'],
                'description' => $taskData['value']['description'],
            ],
            'checkpoints' => $checkpoints,
            'create_form' => true,
        ]);
    }

    private function generateSimpleTask($taskConfig, $taskData, $apiService): void
    {
        $from = Carbon::today()->setTimeFromTimeString($taskData['value']['from_time']);
        $to = (clone $from)->addMinutes($taskData['value']['duration']);

        $apiService->createTask([
            'task' => [
                'tracker_id' => $taskConfig['tracker_id'],
                'location' => $taskData['value']['location'],
                'label' => $taskData['value']['label'],
                'description' => $taskData['value']['description'],
                'max_delay' => $taskData['value']['max_delay'],
                'min_stay_duration' => $taskData['value']['min_stay_duration'],
                'min_arrival_duration' => $taskData['value']['min_arrival_duration'],
                'type' => $taskData['value']['type'],
                'from' => $from->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                'to' => $to->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                'form_template_id' => $taskData['value']['form_template_id'],
                ...(isset($taskData['value']['tags']) ? ['tags' => $taskData['value']['tags']] : []),
            ],
            'create_form' => true,
        ]);
    }
}

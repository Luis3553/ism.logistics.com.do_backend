<?php

namespace App\Console\Commands;

use App\Http\Controllers\Service\ProGpsApiService;
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
            // Carbon::setTestNow(Carbon::parse('2025-08-12'));
            $this->info('Generating tasks for today...');

            $today = Carbon::today();
            $todayWeekday = $today->dayOfWeek;

            $tasksConfigs = ScheduleRouteTask::where('is_valid', true)->get();

            foreach ($tasksConfigs as $taskConfig) {
                $apiService = new ProGpsApiService($taskConfig->user_hash);
                $taskData = $apiService->getScheduleTaskData($taskConfig->task_id);
                $taskType = $taskData['value']['type'];

                $startDate = Carbon::parse($taskConfig['start_date']);
                $weeksDiff = $startDate->diffInWeeks($today);
                $monthsDiff = $startDate->diffInMonths($today);

                $matchesDay = in_array($todayWeekday, $taskConfig['days_of_week']);

                $shouldGenerate = false;

                if ($taskConfig['frequency'] === 'every_x_weeks') {
                    $shouldGenerate = ($weeksDiff % $taskConfig['frequency_value'] === 0) && $matchesDay;
                } elseif ($taskConfig['frequency'] === 'every_x_months') {
                    $isCorrectMonth = $monthsDiff % $taskConfig['frequency_value'] === 0;

                    if ($isCorrectMonth && $taskConfig['weekday_ordinal']) {
                        $targetWeekday = $taskConfig['days_of_week'][0];
                        $expectedDate = Carbon::today()->nthOfMonth($taskConfig['weekday_ordinal'], $targetWeekday);
                        $shouldGenerate = $today->isSameDay($expectedDate);
                    } else {
                        $shouldGenerate = $isCorrectMonth && $matchesDay;
                    }
                }

                if ($shouldGenerate) {
                    if ($taskType === 'route') {

                        $response = $apiService->createRoute([
                            'route' => [
                                'tracker_id' => $taskConfig['tracker_id'],
                                'label' => $taskData['value']['label'],
                                'description' => $taskData['value']['description'],
                            ],
                            'checkpoints' => array_map(function ($checkpoint) use ($taskConfig) {
                                $from = Carbon::today()
                                    ->setTimeFromTimeString($checkpoint['from_time']);

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
                            }, $taskData['checkpoints']),
                            'create_form' => true,
                        ]);
                    } elseif ($taskType === 'task') {
                        $from = Carbon::today()
                            ->setTimeFromTimeString($taskData['value']['from_time']);
                        $to = (clone $from)->addMinutes($taskData['value']['duration']);

                        $response = $apiService->createTask([
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
                            'create_form' => true
                        ]);
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error generating tasks: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

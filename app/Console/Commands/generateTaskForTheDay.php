<?php

namespace App\Console\Commands;

use App\Models\ScheduleRouteTask;
use App\Services\RouteTaskService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateTaskForTheDay extends Command
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
            $this->info('Starting task generation for the day.');
            $tasksConfigs = ScheduleRouteTask::with('user')
                ->where('is_valid', true)
                ->where('is_active', true)
                ->get()
                ->filter(fn($task) => $task->user && $task->user->hash)
                ->groupBy(fn($task) => $task->user->hash);

            foreach ($tasksConfigs as $hash => $configs) {
                $routeTaskService = new RouteTaskService($hash);
                $routeTaskService->handle($configs);
            }
            $this->info('Task generation completed successfully.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            return Command::FAILURE;
        }
    }
}

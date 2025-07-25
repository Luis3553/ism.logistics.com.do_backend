<?php

namespace App\Services;

use App\Models\Url;
use App\Services\ProGpsApiService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class RouteTaskService
{
    protected ProGpsApiService $apiService;
    protected UrlShortenerService $urlShortenerService;
    protected array $placesMap;
    protected int $user_id;

    public function __construct($hash)
    {
        $this->apiService = new ProGpsApiService($hash);
        $this->urlShortenerService = new UrlShortenerService();
        $this->placesMap = collect($this->apiService->getPlaces()['list'])
            ->keyBy(fn($val) => $val['location']['lat'] . ',' . $val['location']['lng'] . ',' . $val['location']['radius'])
            ->toArray();
        $this->user_id = $this->apiService->getUserInfo()['user_info']['id'];
    }

    public function handle($configs): void
    {
        try {
            $today = Carbon::today();
            foreach ($configs as $taskConfig) {
                $taskData = $this->apiService->getScheduleTaskData($taskConfig->task_id);
                $taskType = $taskData['value']['type'];

                if ($this->shouldGenerateTask($taskConfig, $today)) {
                    match ($taskType) {
                        'route' => $this->generateRouteTask($taskConfig, $taskData),
                        'task' => $this->generateSimpleTask($taskConfig, $taskData),
                    };
                }
            }
        } catch (Throwable $e) {
            Log::error('Error in RouteTaskService: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function shouldGenerateTask($taskConfig, Carbon $today): bool
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

    public function generateRouteTask($taskConfig, $taskData): void
    {
        $checkpoints = array_map(function ($checkpoint) use ($taskConfig) {
            $imageUrl = $this->getCheckpointImageUrl($checkpoint);
            $from = Carbon::today()->setTimeFromTimeString($checkpoint['from_time']);
            $to = (clone $from)->addMinutes($checkpoint['duration']);

            $descpForImage = $imageUrl ? 'Ver Imagen del lugar: ' . $imageUrl . ' ' : '';

            return [
                'tracker_id' => $taskConfig['tracker_id'],
                'location' => $checkpoint['location'],
                'label' => $checkpoint['label'],
                'description' => $descpForImage . $checkpoint['description'],
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

        $this->apiService->createRoute([
            'route' => [
                'tracker_id' => $taskConfig['tracker_id'],
                'label' => $taskData['value']['label'],
                'description' => $taskData['value']['description'],
            ],
            'checkpoints' => $checkpoints,
            'create_form' => true,
        ]);
    }

    public function generateSimpleTask($taskConfig, $taskData): void
    {
        $imageUrl = $this->getCheckpointImageUrl($taskData['value']);
        $from = Carbon::today()->setTimeFromTimeString($taskData['value']['from_time']);
        $to = (clone $from)->addMinutes($taskData['value']['duration']);

        $descpForImage = $imageUrl ? 'Ver Imagen del lugar: ' . $imageUrl . ' ' : '';

        $this->apiService->createTask([
            'task' => [
                'tracker_id' => $taskConfig['tracker_id'],
                'location' => $taskData['value']['location'],
                'label' => $taskData['value']['label'],
                'description' => $descpForImage . $taskData['value']['description'],
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

    public function getCheckpointImageUrl($checkpoint)
    {
        $key = $checkpoint['location']['lat'] . ',' . $checkpoint['location']['lng'] . ',' . $checkpoint['location']['radius'];

        if (isset($this->placesMap[$key]) && isset($this->placesMap[$key]['avatar_file_name'])) {
            $urlWithoutShortener = $this->apiService->baseUrl . '/static/place/avatars/' . $this->placesMap[$key]['avatar_file_name'];

            $shortened = Url::where('original_url', $urlWithoutShortener)
                ->orWhere('comment', $key)
                ->first();

            $baseUrl = config('app.url');

            if ($shortened) {
                if ($shortened->original_url !== $urlWithoutShortener) {
                    $shortened->original_url = $urlWithoutShortener;
                    $shortened->save();
                }

                return $baseUrl . '/s/' . $shortened->hash;
            }

            return $baseUrl . "/s/" . $this->urlShortenerService->shorten($urlWithoutShortener, $this->user_id, $key);
        }

        return null;
    }
}

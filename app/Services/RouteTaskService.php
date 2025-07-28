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

                    $this->checkCycleCompletion($taskConfig, $today);
                }
            }
        } catch (Throwable $e) {
            Log::error('Error in RouteTaskService: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }


    protected function checkCycleCompletion($taskConfig, Carbon $today)
    {
        if (is_null($taskConfig->occurrence_limit)) {
            return; // No limit = ignore
        }

        $frequencyType = $taskConfig['frequency'];

        if ($frequencyType === 'every_x_weeks') {
            if ($this->isWeeklyCycleComplete($taskConfig, $today)) {
                $taskConfig->increment('occurrence_count');
                if ($taskConfig->occurrence_count >= $taskConfig->occurrence_limit) {
                    $taskConfig->update(['is_active' => false]);
                }
            }
        }

        if ($frequencyType === 'every_x_months') {
            if ($this->isMonthlyCycleComplete($taskConfig, $today)) {
                $taskConfig->increment('occurrence_count');
                if ($taskConfig->occurrence_count >= $taskConfig->occurrence_limit) {
                    $taskConfig->update(['is_active' => false]);
                }
            }
        }
    }


    public function shouldGenerateTask($taskConfig, Carbon $today): bool
    {
        $startDate = Carbon::parse($taskConfig['start_date']);
        $frequencyType = $taskConfig['frequency'];
        $frequency = $taskConfig['frequency_value'];
        $validWeekdays = $taskConfig['days_of_week'];
        $weekdayOrdinal = $taskConfig['weekday_ordinal'] ?? null;

        if (!in_array($today->isoWeekday(), $validWeekdays)) return false;

        if ($frequencyType === 'every_x_weeks') {
            $firstValidDay = $startDate->copy();
            while (!in_array($firstValidDay->isoWeekday(), $validWeekdays)) {
                $firstValidDay->addDay();
            }
            $startOfCycle = $firstValidDay->copy()->startOfWeek(Carbon::MONDAY);

            if ($today->lt($startOfCycle)) {
                return false;
            }

            $daysDiff = $startOfCycle->diffInDays($today);
            $weeksSinceStart = floor($daysDiff / 7);

            return $weeksSinceStart % $frequency === 0;
        }

        if ($frequencyType === 'every_x_months') {
            $firstValidDay = null;
            foreach ($validWeekdays as $weekday) {
                $carbonWeekday = ($weekday === 7) ? 0 : $weekday;
                $candidateDay = $startDate->copy()->nthOfMonth($weekdayOrdinal, $carbonWeekday);

                if ($candidateDay->gte($startDate)) {
                    if (is_null($firstValidDay) || $candidateDay->lt($firstValidDay)) {
                        $firstValidDay = $candidateDay;
                    }
                }
            }

            if (is_null($firstValidDay)) {
                $nextMonth = $startDate->copy()->addMonthNoOverflow()->startOfMonth();
                $firstValidDay = null;
                foreach ($validWeekdays as $weekday) {
                    $carbonWeekday = ($weekday === 7) ? 0 : $weekday;
                    $candidateDay = $nextMonth->copy()->nthOfMonth($weekdayOrdinal, $carbonWeekday);

                    if (is_null($firstValidDay) || $candidateDay->lt($firstValidDay)) {
                        $firstValidDay = $candidateDay;
                    }
                }
            }

            if (!$firstValidDay) {
                return false;
            }

            $carbonWeekdayToday = ($today->isoWeekday() === 7) ? 0 : $today->isoWeekday();
            $expectedDay = $today->copy()->nthOfMonth($weekdayOrdinal, $carbonWeekdayToday);

            if ($expectedDay && $today->isSameDay($expectedDay) && $expectedDay->gte($startDate)) {
                $monthsSinceFirstCycle = $firstValidDay->copy()->startOfMonth()->diffInMonths($today->copy()->startOfMonth());

                return $monthsSinceFirstCycle % $frequency === 0;
            }

            return false;
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

    function isWeeklyCycleComplete($taskConfig, Carbon $today): bool
    {
        $startDate = Carbon::parse($taskConfig['start_date']);
        $frequency = $taskConfig['frequency_value'];
        $validWeekdays = $taskConfig['days_of_week'];
        $firstValidDay = $startDate->copy();
        while (!in_array($firstValidDay->isoWeekday(), $validWeekdays)) {
            $firstValidDay->addDay();
        }

        $weeksSinceStart = floor($firstValidDay->diffInWeeks($today));
        $cycleIndex = floor($weeksSinceStart / $frequency);

        $cycleStart = $firstValidDay->copy()->startOfWeek(Carbon::MONDAY)->addWeeks($cycleIndex * $frequency);

        $expectedDates = [];
        foreach ($validWeekdays as $day) {
            $expectedDates[] = $cycleStart->copy()->addDays($day - 1)->toDateString();
        }

        return $today->toDateString() === max($expectedDates);
    }

    function isMonthlyCycleComplete($taskConfig, Carbon $today): bool
    {
        $startDate = Carbon::parse($taskConfig['start_date']);
        $frequency = $taskConfig['frequency_value'];
        $validWeekdays = $taskConfig['days_of_week'];
        $weekdayOrdinal = $taskConfig['weekday_ordinal'];

        $monthsSinceStart = $startDate->copy()->startOfMonth()->diffInMonths($today->copy()->startOfMonth());
        $cycleIndex = floor($monthsSinceStart / $frequency);

        $cycleMonth = $startDate->copy()->startOfMonth()->addMonths($cycleIndex * $frequency);

        $expectedDates = [];
        foreach ($validWeekdays as $weekday) {
            $carbonWeekday = ($weekday === 7) ? 0 : $weekday;
            $date = $cycleMonth->copy()->nthOfMonth($weekdayOrdinal, $carbonWeekday);
            if ($date) {
                $expectedDates[] = $date->toDateString();
            }
        }

        return $today->toDateString() === max($expectedDates);
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

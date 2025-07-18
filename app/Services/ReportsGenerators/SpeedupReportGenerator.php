<?php

namespace App\Services\ReportsGenerators;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class SpeedupReportGenerator
{
    public function generate($report)
    {
        $reportId = $report->id;
        $hash = $report->user->hash;
        $trackerIds = $report->report_payload['trackers'];
        $fromDate = $report->report_payload['from'];
        $toDate = $report->report_payload['to'];
        $allowedSpeed = $report->report_payload['allowed_speed'];
        $max_duration = $report->report_payload['min_duration'];

        $nodeScript = base_path('node-processor/main.js');
        $cmd = [
            'node',
            $nodeScript,
            '--hash',
            $hash,
            '--report_id',
            $reportId,
            '--ids',
            implode(',', $trackerIds),
            '--from',
            $fromDate,
            '--to',
            $toDate,
            '--allowed_speed',
            $allowedSpeed,
            '--min_duration',
            $max_duration
        ];

        $process = new Process($cmd, null, ['APP_URL' => config('app.url')]);
        $process->setTimeout(600); // Set a timeout of 10 minutes
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error($process->getErrorOutput(), [
                'report_id' => $reportId,
            ]);
            $report->percent = -1;
            $report->save();
        }
    }
}

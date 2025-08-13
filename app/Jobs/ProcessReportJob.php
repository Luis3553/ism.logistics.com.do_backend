<?php

namespace App\Jobs;

use App\Factories\ReportGeneratorFactory;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $report;
    public $hash;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $reportTypeId = $this->report->report_type_id;
            $generator = ReportGeneratorFactory::make($reportTypeId);
            $result = $generator->generate($this->report);

            $jsonDir = storage_path('app/reports');
            if (!file_exists($jsonDir)) mkdir($jsonDir, 0755, true);
            $jsonPath = $jsonDir . "/report_{$this->report->id}.json";

            file_put_contents($jsonPath, json_encode($result, JSON_UNESCAPED_UNICODE));
            $this->report->update([
                'percent' => 100,
                'file_path' => $jsonPath,
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing report: ' . $e->getMessage(), [
                'report_id' => $this->report->id,
                'exception' => $e,
            ]);
            $this->report->update([
                'percent' => -1,
                'file_path' => null,
            ]);
        }
    }
}

<?php

namespace App\Services;

use App\Factories\ReportValidatorFactory;
use App\Services\ProGpsApiService;
use App\Jobs\ProcessReportJob;
use App\Models\Report;

class ReportService
{
    public function __construct(protected ProGpsApiService $apiService) {}

    public function generateReport(array $payload, int $reportTypeId, int $userId): Report
    {
        $validator = ReportValidatorFactory::make($reportTypeId);
        $validator->validate($payload);

        $report = Report::create([
            'user_id' => $userId,
            'title' => $payload['title'],
            'report_type_id' => $reportTypeId,
            'report_payload' => $payload,
            'percent' => 0,
            'file_path' => null,
        ]);

        ProcessReportJob::dispatch($report, $this->apiService->apiKey);

        return $report;
    }
}

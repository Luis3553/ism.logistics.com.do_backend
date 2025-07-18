<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class OfflineDevicesReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'trackers.required' => 'At least one tracker is required.',
            'title.required' => 'Title is required.',
        ]);

        $validator->validate();
    }
}

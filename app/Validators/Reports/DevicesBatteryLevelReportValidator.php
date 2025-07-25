<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class DevicesBatteryLevelReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'trackers.array' => 'Trackers must be an array.',
            'trackers.required' => 'At least one tracker is required.',
            'title.required' => 'Title is required.',
        ]);

        $validator->validate();
    }
}

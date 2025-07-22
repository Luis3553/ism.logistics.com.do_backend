<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class DevicesBatteryLevelReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'date' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'date.required' => 'The date is required.',
            'date.date_format' => 'The date must be in the format Y-m-d\TH:i:s\Z.',
            'trackers.array' => 'Trackers must be an array.',
            'trackers.required' => 'At least one tracker is required.',
            'title.required' => 'Title is required.',
        ]);

        $validator->validate();
    }
}

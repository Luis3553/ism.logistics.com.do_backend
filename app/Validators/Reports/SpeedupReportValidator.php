<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class SpeedupReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'from' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'to' => 'required|date_format:Y-m-d\TH:i:s\Z|after_or_equal:from',
            'min_duration' => 'required|numeric|min:0',
            'allowed_speed' => 'required|numeric|min:1',
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'from.required' => 'From date is required.',
            'to.required' => 'To date is required.',
            'min_duration.required' => 'Minimum duration is required.',
            'min_duration.min' => 'Minimum duration cannot be negative.',
            'allowed_speed.required' => 'Allowed speed is required.',
            'allowed_speed.min' => 'Allowed speed must be greater than 0.',
            'trackers.required' => 'At least one tracker is required.',
            'title.required' => 'Title is required.',
        ]);

        $validator->validate();
    }
}

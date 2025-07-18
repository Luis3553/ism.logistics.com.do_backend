<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class TasksAndVisitsReportsValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'from' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'to' => 'required|date_format:Y-m-d\TH:i:s\Z|after_or_equal:from',
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'from.required' => 'From date is required.',
            'from.date_format' => 'From date must be in the format ISO8601 UTC.',
            'to.required' => 'To date is required.',
            'to.date_format' => 'To date must be in the format ISO8601 UTC.',
            'trackers.required' => 'At least one tracker is required.',
            'title.required' => 'Title is required.',
        ]);

        $validator->validate();
    }
}

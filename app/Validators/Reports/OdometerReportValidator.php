<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class OdometerReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'date' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'date.required' => 'Date is required.',
            'date.date_format' => 'Date must be in ISO8601 UTC format.',
            'trackers.required' => 'At least one tracker is required.',
            'title.required' => 'Title is required.',
        ]);

        $validator->validate();
    }
}

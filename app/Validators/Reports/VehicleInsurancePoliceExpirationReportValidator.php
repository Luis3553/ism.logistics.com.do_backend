<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class VehicleInsurancePoliceExpirationReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'from' => 'nullable|date_format:Y-m-d\TH:i:s\Z',
            'to' => 'nullable|date_format:Y-m-d\TH:i:s\Z|after_or_equal:from',
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'from.date_format' => 'From date must be in ISO8601 UTC format.',
            'to.date_format' => 'To date must be in ISO8601 UTC format.',
            'to.after_or_equal' => 'To date must be after or equal to from date.',
            'trackers.required' => 'At least one tracker is required.',
            'title.required' => 'Title is required.',
        ]);
        $validator->validate();
    }
}

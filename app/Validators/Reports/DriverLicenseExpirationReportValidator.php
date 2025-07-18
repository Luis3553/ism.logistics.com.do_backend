<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class DriverLicenseExpirationReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'from' => 'nullable|date_format:Y-m-d\TH:i:s\Z',
            'to' => 'nullable|date_format:Y-m-d\TH:i:s\Z|after_or_equal:from',
            'employees' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'from.date_format' => 'From date must be in the format ISO8601 UTC.',
            'to.date_format' => 'To date must be in the format ISO8601 UTC.',
            'to.after_or_equal' => 'To date must be after or equal to from date.',
            'employees.required' => 'You must select at least one employee.',
            'title.required' => 'Title is required.',
        ]);

        $validator->validate();
    }
}

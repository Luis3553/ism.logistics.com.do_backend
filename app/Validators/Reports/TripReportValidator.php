<?php

namespace App\Validators\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

class TripReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'from' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'to' => [
                'required',
                'date_format:Y-m-d\TH:i:s\Z',
                'after_or_equal:from',
                function ($attribute, $value, $fail) use ($payload) {
                    if (isset($payload['from'])) {
                        $from = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $payload['from']);
                        $to = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $value);
                        if ($from && $to && $from->diffInDays($to) > 31) {
                            $fail('The date range between from and to must not exceed 31 days.');
                        }
                    }
                },
            ],
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
            'detailed' => 'sometimes|boolean',
        ], [
            'from.required' => 'From date is required.',
            'from.date_format' => 'From date must be in the format ISO8601 UTC.',
            'to.required' => 'To date is required.',
            'to.date_format' => 'To date must be in the format ISO8601 UTC.',
            'trackers.required' => 'At least one tracker is required.',
            'title.required' => 'Title is required.',
            'detailed.boolean' => 'Include stops must be a boolean value.',
        ]);

        $validator->validate();
    }
}

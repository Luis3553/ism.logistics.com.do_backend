<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class NotificationsReportValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'from' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'to' => 'required|date_format:Y-m-d\TH:i:s\Z|after_or_equal:from',
            'title' => 'required|string',
            'trackers' => 'required',
            'notifications' => 'required',
            'groups' => 'required',
        ], [
            'from.required' => 'From date is required.',
            'from.date_format' => 'From must be a valid date.',
            'to.required' => 'To date is required.',
            'to.date_format' => 'To must be a valid date.',
            'to.after_or_equal' => 'To must be after or equal to from.',
            'trackers.required' => 'Trackers are required.',
            'notifications.required' => 'Notifications filter is required.',
            'groups.required' => 'Groups filter is required.',
        ]);

        $validator->validate();
    }
}

<?php

namespace App\Validators\Reports;

use Illuminate\Support\Facades\Validator;

class VehicleServiceTaskValidator implements ReportValidatorInterface
{
    public function validate(array $payload): void
    {
        $validator = Validator::make($payload, [
            'trackers' => 'required|array|min:1',
            'title' => 'required|string',
        ], [
            'trackers.required' => 'At least one tracker is required.',
            'trackers.array' => 'Trackers must be an array.',
            'trackers.min' => 'At least one tracker must be selected.',
            'title.required' => 'Title is required.',
        ]);

        $validator->validate();
    }
}

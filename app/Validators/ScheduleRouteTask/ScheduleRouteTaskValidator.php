<?php

namespace App\Validators\ScheduleRouteTask;

use Illuminate\Contracts\Validation\Validator;

class ScheduleRouteTaskValidator
{
    /**
     * Validate the request data for creating or updating a schedule route task.
     *
     * @param array $data
     * @return Validator
     */
    public static function validate(array $data): Validator
    {
        return validator($data, [
            'task_id' => 'required|integer',
            'tracker_id' => 'required|integer',
            'frequency' => 'required|in:every_x_weeks,every_x_months',
            'frequency_value' => 'required|integer|min:1',
            'days_of_week' => 'required|array',
            'days_of_week.*' => 'integer|between:1,7',
            'weekday_ordinal' => [
                'nullable',
                'integer',
                'between:1,4',
                function ($attribute, $value, $fail) use ($data) {
                    if (($data['frequency'] ?? null) === 'every_x_months' && is_null($value)) {
                        $fail('The weekday ordinal is required when frequency is every_x_months.');
                    }
                },
            ],
            'start_date' => 'required|date',
            'is_active' => 'boolean'
        ], [
            'task_id.required' => 'The task ID is required',
            'task_id.integer' => 'The task ID must be and integer',
            'tracker_id.required' => 'The tracker ID is required.',
            'tracker_id.integer' => 'The tracker ID must be an integer.',
            'frequency.required' => 'The frequency is required.',
            'frequency.in' => 'The frequency must be every_x_weeks or every_x_months.',
            'frequency_value.required' => 'The frequency value is required.',
            'frequency_value.integer' => 'The frequency value must be an integer.',
            'frequency_value.min' => 'The frequency value must be at least 1.',
            'days_of_week.required' => 'The days of week are required.',
            'days_of_week.array' => 'The days of week must be an array.',
            'days_of_week.*.integer' => 'Each day of week must be an integer.',
            'days_of_week.*.between' => 'Each day of week must be between 1 and 7.',
            'start_date.required' => 'The start date is required.',
            'start_date.date_format' => 'The start date must be in the format ISO8601',
            'weekday_ordinal.integer' => 'The weekday ordinal must be an integer.',
            'weekday_ordinal.between' => 'The weekday ordinal must be between 1 and 4.',
            'is_active.boolean' => 'The is_active field must be true or false.',
        ]);
    }
}

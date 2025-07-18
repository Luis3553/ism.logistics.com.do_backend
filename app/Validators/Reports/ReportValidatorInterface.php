<?php

namespace App\Validators\Reports;

interface ReportValidatorInterface
{
    /**
     * Validate the input data for a report.
     *
     * @param array $input The input data to validate.
     * @throws \Illuminate\Validation\ValidationException if validation fails.
     */
    public function validate(array $payload): void;
}

<?php

namespace App\Services\ReportsGenerators;

use Illuminate\Support\Facades\Log;
use App\Services\ProGpsApiService;
use Carbon\Carbon;

class DriverLicenseReportGenerator
{

    public function generate($report)
    {
        try {
            $payload = $report->report_payload;
            $hash = $report->user->hash;
            $employeesIDs = $payload['employees'];
            $from = $payload['from'];
            $to = $payload['to'];
            $creationDate = Carbon::createFromFormat('Y-m-d H:i:s', $report->created_at)->startOfDay();

            $apiService = new ProGpsApiService($hash);
            $employees = collect($apiService->getEmployees()['list']);
            $employees = $employees->filter(function ($employee) use ($employeesIDs) {
                return in_array($employee['id'], $employeesIDs) && (isset($employee['driver_license_valid_till']) || isset($employee['driver_license_issue_date']));
            })->values();

            $departments = collect($apiService->getDepartments()['list'])->keyBy('id');


            $records = $employees->map(function ($employee) use ($departments, $creationDate) {
                $dateValidTill = Carbon::createFromFormat('Y-m-d', $employee['driver_license_valid_till'])->startOfDay();
                $diff = $dateValidTill->diffInDays($creationDate, false);

                return  [
                    "id" => empty($employee['personnel_number']) ? "-" : $employee['personnel_number'],
                    "full_name" => $employee['first_name'] . " " . $employee["middle_name"] . " " . $employee['last_name'],
                    "department" => $departments[$employee['department_id']]['label'] ?? "-",
                    "phone_number" => empty($employee['phone_number']) ? '-' : $employee['phone_number'],
                    "driver_license_number" => empty($employee['driver_license_number']) ? '-' : $employee['driver_license_number'],
                    "cat_type" => empty($employee['driver_license_cats']) ? '-' : $employee['driver_license_cats'],
                    "license_valid_till" => $dateValidTill->format('Y/m/d'),
                    "days_left" => $diff < 0 ? abs($diff) : '-',
                    "days_exceeded" => $diff > 0 ? $diff : '-',
                ];
            });

            $dateTitle = "";

            if ($from !== null && $to !== null) {
                $records = $records->filter(function ($record) use ($from, $to) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['license_valid_till']);
                    return $validTill->between(Carbon::parse($from), Carbon::parse($to));
                });
                $dateTitle = 'Desde ' . date('Y/m/d', strtotime($from)) . ' hasta ' . date('Y/m/d', strtotime($to));
            }
            if ($from == null && isset($to)) {
                $records = $records->filter(function ($record) use ($to) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['license_valid_till']);
                    return $validTill->lessThanOrEqualTo(Carbon::parse($to));
                });
                $dateTitle = 'Hasta ' . date('Y/m/d', strtotime($to));
            }
            if ($to == null && isset($from)) {
                $records = $records->filter(function ($record) use ($from) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['license_valid_till']);
                    return $validTill->greaterThanOrEqualTo(Carbon::parse($from));
                });
                $dateTitle = 'Desde ' . date('Y/m/d', strtotime($from));
            }

            $dateTitle = empty($dateTitle) ? 'Todos los registros' : $dateTitle;

            $reportData = [
                'title' => 'Informe de Vencimiento de Licencias de Conductores',
                'date' => $dateTitle,
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#EFEFEF',
                    'rows' => [
                        [
                            'title' => 'Total de Licencias',
                            'value' => count($records)
                        ],
                        [
                            'title' => 'Total de Licencias Vencidas',
                            'value' => collect($records)->filter(function ($record) {
                                return $record['days_left'] === '-' && $record['days_exceeded'] !== '-';
                            })->count()
                        ],
                        [
                            'title' => 'Total de Licencias Vigentes',
                            'value' => collect($records)->filter(function ($record) {
                                return $record['days_exceeded'] === '-';
                            })->count()
                        ],
                    ],
                ],
                'data' => [
                    [
                        'groupLabel' => 'Listado de Conductores',
                        'bgColor' => '#C5D9F1',
                        'content' => [
                            'bgColor' => '#f2f2f2',
                            'columns' => [
                                ['name' => 'ID Empleado', 'key' => 'id'],
                                ['name' => 'Nombre Completo', 'key' => 'full_name'],
                                ['name' => 'Departamento', 'key' => 'department', 'style' => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => 'Teléfono', 'key' => 'phone_number', 'style' => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => 'Número de licencia', 'key' => 'driver_license_number', 'style' => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => 'Tipo de categoría', 'key' => 'cat_type', 'style' => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => 'Fecha de vencimiento', 'key' => 'license_valid_till', 'style' => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => 'Días restantes', 'key' => 'days_left', 'style' => ['alignment' => ['horizontal' => 'center']]],
                                ['name' => 'Días excedidos', 'key' => 'days_exceeded', 'style' => ['alignment' => ['horizontal' => 'center']]],
                            ],
                            'rows' => $records->map(function ($record) {
                                return [
                                    'id' => ["value" => $record['id']],
                                    'full_name' => ["value" => $record['full_name']],
                                    'department' => ["value" => $record['department'], 'style' => ['alignment' => ['horizontal' => 'center']]],
                                    'phone_number' => ["value" => $record['phone_number'], 'style' => ['alignment' => ['horizontal' => 'center']]],
                                    'driver_license_number' => ["value" => $record['driver_license_number'], 'style' => ['alignment' => ['horizontal' => 'center']]],
                                    'cat_type' => ["value" => $record['cat_type'], 'style' => ['alignment' => ['horizontal' => 'center']]],
                                    'license_valid_till' => ["value" => $record['license_valid_till'], 'style' => ['alignment' => ['horizontal' => 'center']]],
                                    'days_left' => ["value" => $record['days_left'], 'style' => ['alignment' => ['horizontal' => 'center']]],
                                    'days_exceeded' => ["value" => $record['days_exceeded'], 'style' => ['alignment' => ['horizontal' => 'center']]],
                                ];
                            })->values()->toArray(),
                        ],
                    ]
                ],
                'columns_dimensions_for_excel_file' => [
                    'A' => 24,
                    'B' => 30,
                    'C' => 20,
                    'D' => 18,
                    'E' => 20,
                    'F' => 18,
                    'G' => 20,
                    'H' => 13,
                    'I' => 14,
                ],
            ];

            $jsonDir = storage_path('app/reports');
            $jsonPath = $jsonDir . "/report_{$report->id}.json";
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $report->file_path = $jsonPath;
            $report->percent = 100;
        } catch (\Throwable $e) {
            Log::error('Error generating driver license expiration report: ' . $e->getMessage());
            $report->percent = -1;
        } finally {
            $report->save();
        }
    }
}

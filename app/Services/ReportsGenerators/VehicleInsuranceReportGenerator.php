<?php

namespace App\Services\ReportsGenerators;

use Illuminate\Support\Facades\Log;
use App\Services\ProGpsApiService;
use Carbon\Carbon;

class VehicleInsuranceReportGenerator
{

    public function generate($report)
    {
        try {
            $payload = $report->report_payload;
            $hash = $report->user->hash;
            $trackersIds = $payload['trackers'];
            $from = $payload['from'];
            $to = $payload['to'];
            $creationDate = Carbon::createFromFormat('Y-m-d H:i:s', $report->created_at)->startOfDay();

            $apiService = new ProGpsApiService($hash);
            $vehicles = collect($apiService->getVehicles()['list']);
            $vehicles = $vehicles->filter(function ($vehicle) use ($trackersIds) {
                return in_array($vehicle['tracker_id'], $trackersIds);
            })->values();

            $odometers = $apiService->getOdometerOfListOfTrackers($trackersIds)['value'];

            $records = $vehicles->map(function ($vehicle) use ($odometers, $creationDate) {
                $record = [];

                if (isset($vehicle['liability_insurance_valid_till'])) {
                    $dateValidTill = Carbon::createFromFormat('Y-m-d', $vehicle['liability_insurance_valid_till'])->startOfDay();
                    $diff = $dateValidTill->diffInDays($creationDate, false);

                    $record[] = [
                        'id' => $vehicle['tracker_id'],
                        'tracker_label' => $vehicle['tracker_label'],
                        'reg_number' => $vehicle['reg_number'],
                        'odometer' => $odometers[$vehicle['tracker_id']],
                        'insurance_policy_number' => $vehicle['liability_insurance_policy_number'],
                        'insurance_valid_till' => $dateValidTill->format('Y/m/d'),
                        'days_left' =>  $diff < 0 ? abs($diff) : '-',
                        'days_exceeded' => $diff > 0 ? $diff : '-',
                    ];
                }

                if (isset($vehicle['free_insurance_valid_till'])) {
                    $dateValidTill = Carbon::createFromFormat('Y-m-d', $vehicle['free_insurance_valid_till'])->startOfDay();
                    $diff = $dateValidTill->diffInDays($creationDate, false);

                    $record[] = [
                        'id' => $vehicle['tracker_id'],
                        'tracker_label' => $vehicle['tracker_label'],
                        'reg_number' => $vehicle['reg_number'],
                        'odometer' => $odometers[$vehicle['tracker_id']],
                        'insurance_policy_number' => $vehicle['free_insurance_policy_number'],
                        'insurance_valid_till' => $dateValidTill->format('Y/m/d'),
                        'days_left' => $diff < 0 ? abs($diff) : '-',
                        'days_exceeded' => $diff > 0 ? $diff : '-',
                    ];
                }

                return $record;
            })->flatten(1);

            $dateTitle = "";

            if ($from !== null && $to !== null) {
                $records = $records->filter(function ($record) use ($from, $to) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['insurance_valid_till']);
                    return $validTill->between(Carbon::parse($from), Carbon::parse($to));
                });
                $dateTitle = 'Desde ' . date('Y/m/d', strtotime($from)) . ' hasta ' . date('Y/m/d', strtotime($to));
            }
            if ($from == null && isset($to)) {
                $records = $records->filter(function ($record) use ($to) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['insurance_valid_till']);
                    return $validTill->lessThanOrEqualTo(Carbon::parse($to));
                });
                $dateTitle = 'Hasta ' . date('Y/m/d', strtotime($to));
            }
            if ($to == null && isset($from)) {
                $records = $records->filter(function ($record) use ($from) {
                    $validTill = Carbon::createFromFormat('Y/m/d', $record['insurance_valid_till']);
                    return $validTill->greaterThanOrEqualTo(Carbon::parse($from));
                });
                $dateTitle = 'Desde ' . date('Y/m/d', strtotime($from));
            }

            $dateTitle = empty($dateTitle) ? 'Todos los registros' : $dateTitle;

            $reportData = [
                'title' => 'Informe de Vencimiento de Pólizas de Seguro',
                'date' => $dateTitle,
                'summary' => [
                    'title' => 'Resumen General',
                    'color' => '#EFEFEF',
                    'rows' => [
                        [
                            'title' => 'Total de Vehículos',
                            'value' => count(collect($records)->pluck('id')->unique())
                        ],
                        [
                            'title' => 'Total de Pólizas de Seguro',
                            'value' => count($records)
                        ],
                        [
                            'title' => 'Total de Pólizas Vencidas',
                            'value' => collect($records)->filter(function ($record) {
                                return $record['days_left'] === '-' && $record['days_exceeded'] !== '-';
                            })->count()
                        ],
                        [
                            'title' => 'Total de Pólizas Vigentes',
                            'value' => collect($records)->filter(function ($record) {
                                return $record['days_exceeded'] === '-';
                            })->count()
                        ],
                    ],
                ],
                'data' => [
                    [
                        'groupLabel' => 'Listado de Vehículos',
                        'bgColor' => '#C5D9F1',
                        'content' => [
                            'bgColor' => '#f2f2f2',
                            'columns' => [
                                ['name' => 'Nombre del objeto', 'key' => 'tracker_label'],
                                ['name' => 'Placa (Matrícula)', 'key' => 'reg_number'],
                                ['name' => 'Odómetro', 'key' => 'odometer'],
                                ['name' => 'Número de Póliza', 'key' => 'insurance_policy_number'],
                                ['name' => 'Fecha de vencimiento', 'key' => 'insurance_valid_till'],
                                ['name' => 'Días restantes', 'key' => 'days_left'],
                                ['name' => 'Días excedidos', 'key' => 'days_exceeded'],
                            ],
                            'rows' => $records->map(function ($record) {
                                return [
                                    'tracker_label' => $record['tracker_label'],
                                    'reg_number' => $record['reg_number'],
                                    'odometer' => number_format($record['odometer'], 2, '.', ','),
                                    'insurance_policy_number' => $record['insurance_policy_number'],
                                    'insurance_valid_till' => $record['insurance_valid_till'],
                                    'days_left' => $record['days_left'],
                                    'days_exceeded' => $record['days_exceeded'],
                                ];
                            })->values()->toArray(),
                        ],
                    ]
                ],
                'columns_dimensions_for_excel_file' => [
                    'A' => 43,
                    'B' => 16,
                    'C' => 14,
                    'D' => 20,
                    'E' => 20,
                    'F' => 13,
                    'G' => 15,
                ],
            ];

            $jsonDir = storage_path('app/reports');
            $jsonPath = $jsonDir . "/report_{$report->id}.json";
            file_put_contents($jsonPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $report->file_path = $jsonPath;
            $report->percent = 100;
        } catch (\Throwable $e) {
            Log::error('Error generating driver insurance police expiration report: ' . $e->getMessage());
            $report->percent = -1;
        } finally {
            $report->save();
        }
    }
}

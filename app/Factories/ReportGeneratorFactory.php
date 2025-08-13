<?php

namespace App\Factories;

use App\Services\ReportsGenerators\DevicesBatteryLevelReportGenerator;
use App\Services\ReportsGenerators\VehicleInsuranceReportGenerator;
use App\Services\ReportsGenerators\OfflineDevicesReportGenerator;
use App\Services\ReportsGenerators\DriverLicenseReportGenerator;
use App\Services\ReportsGenerators\ServiceTasksReportGenerator;
use App\Services\ReportsGenerators\OdometerReportGenerator;
use App\Services\ReportsGenerators\SpeedupReportGenerator;
use App\Services\ReportsGenerators\EventsReportGenerator;
use App\Services\ReportsGenerators\TasksAndVisitsReportGenerator;
use App\Services\ReportsGenerators\TripReportGenerator;

class ReportGeneratorFactory
{
    /**
     * Create a report generator instance based on the report type.
     *
     * @param int $reportTypeId
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public static function make(int $reportTypeId)
    {
        return match ($reportTypeId) {
            1 => new OdometerReportGenerator(),
            2 => new SpeedupReportGenerator(),
            3 => new EventsReportGenerator(),
            4 => new VehicleInsuranceReportGenerator(),
            5 => new DriverLicenseReportGenerator(),
            6 => new ServiceTasksReportGenerator(),
            7 => new OfflineDevicesReportGenerator(),
            8 => new TasksAndVisitsReportGenerator(),
            9 => new DevicesBatteryLevelReportGenerator(),
            10 => new TripReportGenerator(),
            default => throw new \InvalidArgumentException("Invalid report type ID: {$reportTypeId}"),
        };
    }
}

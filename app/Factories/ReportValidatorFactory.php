<?php

namespace App\Factories;

use App\Validators\Reports\DevicesBatteryLevelReportValidator;
use App\Validators\Reports\VehicleInsurancePoliceExpirationReportValidator;
use App\Validators\Reports\DriverLicenseExpirationReportValidator;
use App\Validators\Reports\OfflineDevicesReportValidator;
use App\Validators\Reports\NotificationsReportValidator;
use App\Validators\Reports\VehicleServiceTaskValidator;
use App\Validators\Reports\ReportValidatorInterface;
use App\Validators\Reports\OdometerReportValidator;
use App\Validators\Reports\SpeedupReportValidator;
use App\Validators\Reports\TasksAndVisitsReportsValidator;

class ReportValidatorFactory
{
    /**
     * Create a new ReportValidator instance.
     *
     * @return ReportValidator
     */
    public static function make(int $type): ReportValidatorInterface
    {
        return match ($type) {
            1 => new OdometerReportValidator(),
            2 => new SpeedupReportValidator(),
            3 => new NotificationsReportValidator(),
            4 => new VehicleInsurancePoliceExpirationReportValidator(),
            5 => new DriverLicenseExpirationReportValidator(),
            6 => new VehicleServiceTaskValidator(),
            7 => new OfflineDevicesReportValidator(),
            8 => new TasksAndVisitsReportsValidator(),
            9 => new DevicesBatteryLevelReportValidator(),
            default => throw new \InvalidArgumentException("Unknown report type: $type"),
        };
    }
}

<?php

use App\Http\Controllers\DetailsController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\GeofenceConfigController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('home')->group(function () {
    Route::prefix('count')->group(function () {
        Route::get('vehicles', [HomeController::class, 'getVehiclesCount']);
        Route::get('destinies', [HomeController::class, 'getGeofencesCount']);
        Route::get('travels', [HomeController::class, 'getTravelsCount']);
        Route::get('average', [HomeController::class, 'pe1']);
        Route::get('travels_per_day', [HomeController::class, 'pe2']);
        Route::get('stay_time', [HomeController::class, 'pe3']);
    });
});

Route::prefix('drivers')->group(function () {
    Route::get('vehicles_per_type', [DriverController::class, 'vehiclesPerType']);
    Route::get('garages', [DriverController::class, 'garages']);
    Route::get('vehicles_per_type_and_brand', [DriverController::class, 'vehiclesPerTypeAndBrand']);
    Route::get('vehicles_per_type_and_color', [DriverController::class, 'vehiclesPerTypeAndColor']);
    Route::get('vehicles_per_type_and_model', [DriverController::class, 'vehiclesPerTypeAndModel']);
});

Route::prefix('details')->group(function () {
    Route::get('report_1', [DetailsController::class, 'report_1']);
    Route::get('report_2', [DetailsController::class, 'report_2']);
    Route::get('report_3', [DetailsController::class, 'report_3']);
});

Route::prefix('user')->group(function () {
    Route::prefix('geofence_configurations')->group(function () {
        Route::get('/', [GeofenceConfigController::class, 'getUserConfigurations']);
        Route::post('bulk_create', [GeofenceConfigController::class, 'bulkCreate']);
        Route::post('bulk_update', [GeofenceConfigController::class, 'bulkUpdate']);
        Route::post('bulk_delete', [GeofenceConfigController::class, 'bulkDelete']);
    });
    Route::get('geofences', [GeofenceConfigController::class, 'getGeofences']);
});

Route::prefix('reports')->group(function () {
    Route::get('panel/trackers', [ReportController::class, 'getGroupedTrackers']);

    Route::get('odometer', function () {
        return view('welcome');
    });

    Route::get('report/list', [ReportController::class, 'getListOfUsersGeneratedReports']);
    Route::get('report/{id}', [ReportController::class, 'getStatusOfReport']);
    Route::get('report/generate', [ReportController::class, 'generateReport']);
    Route::get('report/{id}/retrieve', [ReportController::class, 'retrieveReport']);
    Route::get('report/{id}/delete', [ReportController::class, 'deleteReport']);
});

Route::prefix('notifications')->group(function () {
    Route::get('/', [NotificationsController::class, 'getNotifications']);
    Route::get('tracker/{id}/vehicle', [NotificationsController::class, 'getRelatedVehicle']);

    // List of the items for the filters
    Route::get('trackers', [NotificationsController::class, 'getTrackers']);
    Route::get('rules', [NotificationsController::class, 'getRules']);
    Route::get('groups', [NotificationsController::class, 'getGroups']);
    Route::get('test', [NotificationsController::class, 'testFunction']);
});

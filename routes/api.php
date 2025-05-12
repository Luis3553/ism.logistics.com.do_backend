<?php

use App\Http\Controllers\DetailsController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\GeofenceConfigController;
use App\Http\Controllers\HomeController;
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

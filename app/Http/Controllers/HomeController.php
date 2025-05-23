<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Service\ProGpsApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}

    public function getVehiclesCount(Request $request)
    {
        return response()->json(['vehicles_count' => $this->apiService->getVehicles()['count']]);
    }

    public function getGeofencesCount(Request $request)
    {
        return response()->json(['destinies_count' => $this->apiService->getGeofences()['count']]);
    }

    public function getTravelsCount(Request $request)
    {
        $date = $request->query('date'); // Format: YYYY-MM-DD

        return response()->json(['travels_count' => 0]);
        // return response()->json(['travels_count' => $this->apiService->getHistoryOfTrackers("$date 00:00:00", "$date 23:59:59")]);
    }

    public function getAverageOfTravelsCount(Request $request)
    {
        return response()->json(['average_count' => 0]);
    }
    public function getTravelsPerDayCount(Request $request)
    {
        return response()->json(['travels_per_day_count' => 0]);
    }
    public function getStayTimeCount(Request $request)
    {
        return response()->json(['stay_time_count' => 0]);
    }
};

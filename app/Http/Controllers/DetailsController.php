<?php

namespace App\Http\Controllers;

use App\Services\ProGpsApiService;
use Illuminate\Http\Request;

class DetailsController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}
    public function report_1(Request $request)
    {
        $date = $request->query('date', now()->toDateString());
        if ($date === now()->toDateString()) {
            return response()->json(['report_1' => $this->apiService->getSumOfTrackersOdometerActualValue()]);
        } else {
            return response()->json(['report_1' => $this->apiService->getSumOfOdometersValueInPastDate($date)]);
        }
    }
    public function report_2(Request $request)
    {
        $date = $request->query('date', now()->toDateString());
        if ($date === now()->toDateString()) {
            return count($this->apiService->getTripReportsOfTrackersParallel("2025-04-16"));
        } else {
        }
    }
    public function report_3(Request $request)
    {
        $date = $request->query('date', now()->toDateString());
        if ($date === now()->toDateString()) {
            return $this->apiService->getSumOfTrackersOdometerActualValue();
        } else {
        }
    }
}

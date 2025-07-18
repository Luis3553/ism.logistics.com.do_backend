<?php

namespace App\Http\Controllers;

use App\Services\ProGpsApiService;
use Illuminate\Http\Request;

class FormController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}

    public function getTemplateList(Request $request)
    {
        $templates = $this->apiService->getFormTemplateList();
        return response()->json($templates);
    }
}

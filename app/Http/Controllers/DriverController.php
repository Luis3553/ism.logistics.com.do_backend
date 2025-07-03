<?php

namespace App\Http\Controllers;

use App\Services\ProGpsApiService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DriverController extends Controller
{
    private $typeTranslation;

    public function __construct(protected ProGpsApiService $apiService)
    {
        $this->typeTranslation = [
            'truck' => "Camión",
            'special' => "Especial",
            'bus' => "Autobús",
            'car' => "Vehículo",
        ];
    }
    public function vehiclesPerType(Request $request)
    {
        $list = $this->apiService->getVehicles()['list'];

        $structure = [
            [
                'name' => "Camión",
                'y' => 0,
            ],
            [
                'name' => "Especial",
                'y' => 0,
            ],
            [
                'name' => "Autobús",
                'y' => 0,
            ],
            [
                'name' => "Vehículo",
                'y' => 0,
            ],
        ];

        $vehicles = array_reduce($list, function ($acc, $vehicle) {
            switch ($vehicle['type']) {
                case 'truck':
                    $acc[0]['y'] += 1;
                    break;
                case 'special':
                    $acc[1]['y'] += 1;
                    break;
                case 'bus':
                    $acc[2]['y'] += 1;
                    break;
                case 'car':
                    $acc[3]['y'] += 1;
                    break;
            }
            return $acc;
        }, $structure);

        return response()->json($vehicles);
    }
    public function garages(Request $request)
    {
        $endpoints = [
            'drivers' => 'employee/list',
            'garages' => 'garage/list',
            'vehicles' => 'vehicle/list',
            'departments' => 'department/list',
        ];

        $responses = $this->apiService->fetchBatchRequests($endpoints);

        $departments = collect($responses['departments']['list'])->keyBy('id');
        $garages = collect($responses['garages']['list'])->keyBy('id');
        $vehicles = collect($responses['vehicles']['list']);
        $drivers = collect($responses['drivers']['list'])->keyBy('id');

        $grouped = [];

        foreach ($vehicles as $vehicle) {
            $garage = $garages->get($vehicle['garage_id']);
            $garageLabel = $garage['organization_name'] ?? 'N/A';

            $driver = $drivers->firstWhere('id', $vehicle['tracker_id']);
            $driverLabel = $driver ? ($driver['first_name'] . ' ' . $driver['last_name']) : 'N/A';

            $department = $departments->get($driver['department_id'] ?? null);
            $departmentLabel = $department['label'] ?? 'N/A';

            $label = $vehicle['label'] ?? 'N/A';
            $model = $vehicle['model'] ?? 'N/A';
            $regNumber = $vehicle['reg_number'] ?? 'UNKNOWN';
            $brand = explode(' ', $label)[0] ?? 'UNKNOWN';

            $vehicleLabel = "$label - $regNumber";

            // Find or create department
            $departmentIndex = array_search($departmentLabel, array_column($grouped, 'department'));
            if ($departmentIndex === false) {
                $grouped[] = [
                    'department' => $departmentLabel,
                    'garages' => [],
                ];
                $departmentIndex = array_key_last($grouped);
            }

            // Find or create garage
            $garagesRef = &$grouped[$departmentIndex]['garages'];
            $garageIndex = array_search($garageLabel, array_column($garagesRef, 'garage'));
            if ($garageIndex === false) {
                $garagesRef[] = [
                    'garage' => $garageLabel,
                    'drivers' => [],
                ];
                $garageIndex = array_key_last($garagesRef);
            }

            // Find or create driver
            $driversRef = &$garagesRef[$garageIndex]['drivers'];
            $driverIndex = array_search($driverLabel, array_column($driversRef, 'driver'));
            if ($driverIndex === false) {
                $driversRef[] = [
                    'driver' => $driverLabel,
                    'vehicles' => [],
                ];
                $driverIndex = array_key_last($driversRef);
            }

            // Find or create brand
            $vehiclesRef = &$driversRef[$driverIndex]['vehicles'];
            $brandIndex = array_search($brand, array_column($vehiclesRef, 'brand'));
            if ($brandIndex === false) {
                $vehiclesRef[] = [
                    'brand' => $brand,
                    'models' => [],
                ];
                $brandIndex = array_key_last($vehiclesRef);
            }

            // Find or create model
            $modelsRef = &$vehiclesRef[$brandIndex]['models'];
            $modelIndex = array_search($model, array_column($modelsRef, 'model'));
            if ($modelIndex === false) {
                $modelsRef[] = [
                    'model' => $model,
                    'labels' => [],
                ];
                $modelIndex = array_key_last($modelsRef);
            }

            // Add vehicle label if not already there
            if (!in_array($vehicleLabel, $modelsRef[$modelIndex]['labels'])) {
                $modelsRef[$modelIndex]['labels'][] = $vehicleLabel;
            }
        }

        return response()->json($grouped);
    }



    public function vehiclesPerTypeAndModel(Request $request)
    {
        $vehicles = $this->apiService->getVehicles()['list'];
        $allTypes = array_values($this->typeTranslation);
        $modelGroups = [];
        $yearGroups = [];

        foreach ($vehicles as $v) {
            $model = trim($v['model']) === '' ? 'N/A' : $v['model'];
            $year = $v['manufacture_year'] ?? 'N/A';
            $type = $this->typeTranslation[$v['type']] ?? $v['type'];

            // Count for model group
            if (!isset($modelGroups[$model])) {
                $modelGroups[$model] = [];
            }
            if (!isset($modelGroups[$model][$type])) {
                $modelGroups[$model][$type] = 0;
            }
            $modelGroups[$model][$type]++;

            // Count for year group
            if (!isset($yearGroups[$year])) {
                $yearGroups[$year] = [];
            }
            if (!isset($yearGroups[$year][$type])) {
                $yearGroups[$year][$type] = 0;
            }
            $yearGroups[$year][$type]++;
        }

        // Helper to sort and build series
        $buildGroupData = function ($group) use ($allTypes) {
            // Total counts for sorting
            $totals = [];
            foreach ($group as $key => $types) {
                $totals[$key] = array_sum($types);
            }

            // Sort by descending totals
            uksort($group, function ($a, $b) use ($totals) {
                if ($a === 'N/A') return 1;
                if ($b === 'N/A') return -1;
                return $totals[$b] <=> $totals[$a];
            });


            $categories = array_keys($group);

            // Fill missing type entries
            foreach ($categories as $key) {
                foreach ($allTypes as $type) {
                    if (!isset($group[$key][$type])) {
                        $group[$key][$type] = 0;
                    }
                }
            }

            // Define colors per type
            $colors = [
                'Camión' => '#2caffe',
                'Especial' => '#544fc5',
                'Autobús' => '#00e272',
                'Vehículo' => '#fe6a35',
            ];

            // Build series
            $series = [];
            foreach ($allTypes as $type) {
                $series[] = [
                    'name' => $type,
                    'type' => 'bar',
                    'color' => $colors[$type] ?? '#000000',
                    'data' => array_map(
                        fn($key) => $group[$key][$type] ?? 0,
                        $categories
                    ),
                    'stack' => 'vehicles',
                ];
            }

            // Reverse for stacking order
            return [
                'categories' => $categories,
                'series' => array_reverse($series),
            ];
        };

        return response()->json([
            'modelGroup' => $buildGroupData($modelGroups),
            'yearGroup' => $buildGroupData($yearGroups),
        ]);
    }


    public function vehiclesPerTypeAndBrand(Request $request)
    {
        $vehicles = $this->apiService->getVehicles()['list'];

        // Initialize all types
        $allTypes = array_values($this->typeTranslation);

        $vehicleBrands = [];

        foreach ($vehicles as $v) {
            $type = $this->typeTranslation[$v['type']] ?? $v['type'];
            $brand = explode(' ', $v['label'])[0] ?? 'N/A';  // Assuming the brand is the first word of the label

            // Initialize brand group if not exists
            if (!isset($vehicleBrands[$brand])) {
                $vehicleBrands[$brand] = [];
            }

            // Initialize type count for the brand if not exists
            if (!isset($vehicleBrands[$brand][$type])) {
                $vehicleBrands[$brand][$type] = 0;
            }

            // Increment the count for the brand and type
            $vehicleBrands[$brand][$type]++;
        }

        // Compute total count for each brand
        $brandTotals = [];
        foreach ($vehicleBrands as $brand => $types) {
            $brandTotals[$brand] = array_sum($types);
        }

        // Sort the brands by total count in descending order
        uksort($vehicleBrands, function ($a, $b) use ($brandTotals) {
            return $brandTotals[$b] <=> $brandTotals[$a];
        });

        // Get the sorted list of brands
        $allBrands = array_keys($vehicleBrands);

        $colorsForTypes = [
            'Camión' => '#2caffe',
            'Especial' => '#544fc5',
            'Autobús' => '#00e272',
            'Vehículo' => '#fe6a35',
        ];

        // Rebuild the series with sorted brand order
        $series = [];
        foreach ($allTypes as $type) {
            $series[] = [
                'name' => $type,
                'type' => 'column',
                'color' => $colorsForTypes[$type] ?? '#000000',
                'data' => array_map(
                    function ($brand) use ($vehicleBrands, $type) {
                        return $vehicleBrands[$brand][$type] ?? 0;
                    },
                    $allBrands
                ),
                'stack' => 'vehicles',
            ];
        }

        return response()->json([
            'categories' => $allBrands,  // Sorted brands based on total count
            'series' => array_reverse($series),         // Series data for each type
        ]);
    }



    public function vehiclesPerTypeAndColor(Request $request)
    {
        $vehicles = $this->apiService->getVehicles()['list'];

        // Initialize all types
        $allTypes = array_values($this->typeTranslation);

        $vehicleColors = [];

        foreach ($vehicles as $v) {
            $type = $this->typeTranslation[$v['type']] ?? $v['type'];
            $color = trim($v['color']) === "" ? 'N/A' : $v['color'];

            if (!isset($vehicleColors[$color])) {
                $vehicleColors[$color] = [];
            }

            if (!isset($vehicleColors[$color][$type])) {
                $vehicleColors[$color][$type] = 0;
            }

            $vehicleColors[$color][$type]++;
        }

        // Compute total per color
        $colorTotals = [];
        foreach ($vehicleColors as $color => $types) {
            $colorTotals[$color] = array_sum($types);
        }

        // Sort colors by total descending
        uksort($vehicleColors, function ($a, $b) use ($colorTotals) {
            return $colorTotals[$b] <=> $colorTotals[$a];
        });

        $allColors = array_keys($vehicleColors);

        $colorsForTypes = [
            'Camión' => '#2caffe',
            'Especial' => '#544fc5',
            'Autobús' => '#00e272',
            'Vehículo' => '#fe6a35',
        ];

        // Rebuild series with sorted color order
        $series = [];
        foreach ($allTypes as $type) {
            $series[] = [
                'name' => $type,
                'type' => 'column',
                'color' => $colorsForTypes[$type] ?? '#000000',
                'data' => array_map(
                    function ($color) use ($vehicleColors, $type) {
                        return $vehicleColors[$color][$type] ?? 0;
                    },
                    $allColors
                ),
                'stack' => 'vehicles',
            ];
        }

        return response()->json([
            'categories' => $allColors, // Colors sorted by total descending
            'series' => array_reverse($series),
        ]);
    }
}

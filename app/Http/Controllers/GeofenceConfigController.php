<?php

namespace App\Http\Controllers;

use App\Services\ProGpsApiService;
use App\Models\GeofenceConfiguration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GeofenceConfigController extends Controller
{
    public function __construct(protected ProGpsApiService $apiService) {}

    public function getGeofences(Request $request)
    {
        $search = $request->query('search', '');
        $limit = $request->query('limit', 50);

        $listOfZones = $this->apiService->getGeofences(['filter' => $search, 'limit' => $limit])['list'];
        $listOfZones = array_map(function ($geofence) {
            return ['value' => $geofence['id'], 'label' => $geofence['label']];
        }, $listOfZones);
        return response()->json($listOfZones);
    }

    public function getUserConfigurations()
    {
        $listOfGeofences = $this->apiService->getGeofences()['list'];
        $listOfGeofences = array_column($listOfGeofences, 'label', 'id');

        $geofencesConfigurations = GeofenceConfiguration::select('id')
            ->with(['items:id,geofence_configuration_id,type,geofence_id'])
            ->get();

        $configurations = [];

        foreach ($geofencesConfigurations as $configuration) {
            $object = [
                'id' => $configuration->id,
                'origin' => [],
                'destiny' => [],
            ];
            foreach ($configuration->items as $item) {
                $object[$item->type][] = [
                    'value' => $item->geofence_id,
                    'label' => $listOfGeofences[$item->geofence_id] ?? 'Unknown'
                ];
            }
            $configurations[] = $object;
        }

        return response()->json($configurations);
    }

    public function bulkCreate(Request $request)
    {
        $configs = $request->input('configs', []);

        foreach ($configs as $config) {
            $origins = $config['origin'] ?? [];
            $destinies = $config['destiny'] ?? [];

            $user = User::where('hash', 'like', $this->apiService->apiKey)->first();
            $geofenceConfig = GeofenceConfiguration::create([
                'user_id' => $user->id,
            ]);

            foreach ($origins as $origin) {
                $geofenceConfig->items()->create([
                    'geofence_id' => $origin['value'],
                    'type' => 'origin',
                ]);
            }

            foreach ($destinies as $destiny) {
                $geofenceConfig->items()->create([
                    'geofence_id' => $destiny['value'],
                    'type' => 'destiny',
                ]);
            }
        }

        return response()->json(['success' => true]);
    }



    public function bulkUpdate(Request $request)
    {
        $configs = $request->input('configs', []);

        foreach ($configs as $config) {
            $configId = $config['id'] ?? null;
            $origins = $config['origin'] ?? [];
            $destinies = $config['destiny'] ?? [];

            if (!$configId) {
                continue;
            }

            $geofenceConfig = GeofenceConfiguration::find($configId);
            if (!$geofenceConfig) {
                continue;
            }

            $geofenceConfig->items()->delete();

            foreach ($origins as $origin) {
                $geofenceConfig->items()->create([
                    'geofence_id' => $origin['value'],
                    'type' => 'origin',
                ]);
            }

            foreach ($destinies as $destiny) {
                $geofenceConfig->items()->create([
                    'geofence_id' => $destiny['value'],
                    'type' => 'destiny',
                ]);
            }
        }

        return response()->json(['success' => true]);
    }



    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids', []);
        GeofenceConfiguration::whereIn('id', $ids)->delete();

        return response()->json(['success' => true]);
    }
}

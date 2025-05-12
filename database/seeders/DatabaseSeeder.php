<?php

namespace Database\Seeders;

use App\Models\GeofenceConfiguration;
use App\Models\GeofenceConfigurationItem;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $user = User::create([
            'hash' => "cf229226a28d0bc8a646d34b7fa86377"
        ]);

        GeofenceConfiguration::create([
            'user_id' => $user->id
        ])->items()->createMany([
            ['geofence_id' => 325685, 'type' => 'origin'],
            ['geofence_id' => 325686, 'type' => 'origin'],
            ['geofence_id' => 330364, 'type' => 'origin'],
            ['geofence_id' => 330364, 'type' => 'destiny'],
            ['geofence_id' => 404530, 'type' => 'destiny'],
        ]);

        GeofenceConfiguration::create([
            'user_id' => $user->id
        ])->items()->createMany([
            ['geofence_id' => 325687, 'type' => 'origin'],
            ['geofence_id' => 404522, 'type' => 'origin'],
            ['geofence_id' => 325688, 'type' => 'destiny'],
            ['geofence_id' => 404523, 'type' => 'destiny'],
        ]);

        // etc...

    }
}

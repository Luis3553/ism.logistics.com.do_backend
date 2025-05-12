<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeofenceConfigurationItem extends Model
{
    use HasFactory;

    protected $fillable = ['geofence_configuration_id', 'geofence_id', 'type'];
    protected $hidden = ['created_at', 'updated_at'];

    public function geofenceConfiguration()
    {
        return $this->belongsTo(GeofenceConfiguration::class);
    }
}

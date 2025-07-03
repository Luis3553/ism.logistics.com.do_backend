<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleRouteTask extends Model
{
    use HasFactory;

    protected $table = 'schedule_route_tasks';

    protected $fillable = [
        'user_id',
        'task_id',
        'tracker_id',
        'user_hash',
        'frequency',
        'frequency_value',
        'days_of_week',
        'is_valid',
        'is_active',
        'start_date'
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'is_valid' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'user_hash',
        'created_at',
        'updated_at',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleRouteTask extends Model
{
    use HasFactory;

    protected $table = 'schedule_route_tasks';

    protected $attributes = [
        'is_valid' => true,
        'is_active' => true,
    ];

    protected $fillable = [
        'user_id',
        'task_id',
        'tracker_id',
        'frequency',
        'frequency_value',
        'weekday_ordinal',
        'days_of_week',
        'is_valid',
        'is_active',
        'start_date',
        'ocurrence_limit',
        'ocurrence_count',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'is_valid' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function startDate(): Attribute
    {
        return Attribute::make(
            set: fn($value) => \Carbon\Carbon::parse($value)->format('Y-m-d')
        );
    }

    protected static function booted()
    {
        static::updating(function ($task) {
            if ($task->isDirty('is_active') && $task->is_active === true) {
                if (
                    !is_null($task->occurrence_limit) &&
                    $task->occurrence_count >= $task->occurrence_limit
                ) {
                    $task->occurrence_count = 0;
                }
            }
        });
    }
}

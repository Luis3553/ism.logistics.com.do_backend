<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'report_type_id',
        'report_payload',
        'percent',
        'file_path',
    ];

    protected $casts = [
        'report_payload' => 'array',
    ];

    // Hide file_path from JSON serialization
    protected $hidden = [
        'file_path',
    ];
}

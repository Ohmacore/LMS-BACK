<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceProgress extends Model
{
    protected $table = 'resource_progress';

    protected $fillable = [
        'student_id',
        'resource_id',
        'viewed_at',
        'completed_at',
        'last_position_seconds',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_position_seconds' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}

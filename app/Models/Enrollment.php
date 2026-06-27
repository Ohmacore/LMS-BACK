<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enrollment extends Model
{
    protected $fillable = [
        'student_id',
        'module_id',
        'subscription_type',
        'chapter_id',
        'resource_types',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'resource_types' => 'array',
    ];

    /**
     * Get the student that owns the enrollment
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the module for this enrollment
     */
    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * Get the chapter (folder) for this enrollment (when subscription_type = 'chapter')
     */
    public function chapter()
    {
        return $this->belongsTo(Folder::class, 'chapter_id');
    }
}

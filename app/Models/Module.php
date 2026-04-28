<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $fillable = [
        'teacher_id',
        'name',
        'subject',
        'year',
        'level',
        'description',
        'pricing_settings',
    ];

    protected $casts = [
        'pricing_settings' => 'array',
    ];

    protected $appends = ['total_price'];

    /**
     * Calculate total price as sum of chapter prices
     */
    public function getTotalPriceAttribute()
    {
        return $this->folders()
            ->whereNull('parent_folder_id')
            ->sum('price');
    }

    /**
     * Get the teacher that owns the module
     */
    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * Get the folders in this module
     */
    public function folders()
    {
        return $this->hasMany(Folder::class);
    }

    /**
     * Get enrollments for this module
     */
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }
}

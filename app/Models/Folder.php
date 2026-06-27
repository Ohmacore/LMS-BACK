<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Folder extends Model
{
    protected $fillable = [
        'module_id',
        'parent_folder_id',
        'name',
        'type',
        'chapter_number',
        'order',
        'price',
    ];

    /**
     * Get the module that owns the folder
     */
    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * Get the parent folder
     */
    public function parent()
    {
        return $this->belongsTo(Folder::class, 'parent_folder_id');
    }

    /**
     * Get child folders
     */
    public function children()
    {
        return $this->hasMany(Folder::class, 'parent_folder_id');
    }

    /**
     * Get enrollments for this chapter
     */
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class, 'chapter_id')->where('status', 'active');
    }

    /**
     * Get resources in this folder
     */
    public function resources()
    {
        return $this->hasMany(Resource::class);
    }

    public function liveSessions()
    {
        return $this->hasMany(LiveSession::class, 'chapter_id');
    }
}

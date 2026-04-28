<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    protected $fillable = [
        'folder_id',
        'name',
        'type',
        'format',
        'file_path',
        'mime_type',
        'file_size',
        'is_public',
        'size',
        'duration',
        'order',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    /**
     * Get the folder that owns the resource
     */
    public function folder()
    {
        return $this->belongsTo(Folder::class);
    }
}

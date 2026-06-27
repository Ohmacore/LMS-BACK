<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveSession extends Model
{
    protected $fillable = [
        'module_id',
        'chapter_id',
        'title',
        'description',
        'scheduled_at',
        'provider',
        'provider_room',
        'join_url',
        'start_url',
        'zoom_meeting_id',
        'zoom_join_url',
        'zoom_start_url',
        'status',
        'recording_url',
        'recording_resource_id',
        'started_at',
        'ended_at',
        'cancelled_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function chapter()
    {
        return $this->belongsTo(Folder::class, 'chapter_id');
    }

    public function recordingResource()
    {
        return $this->belongsTo(Resource::class, 'recording_resource_id');
    }
}

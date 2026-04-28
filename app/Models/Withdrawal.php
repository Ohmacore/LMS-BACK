<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $fillable = [
        'teacher_id',
        'amount',
        'status',
        'payment_method',
        'payment_details',
        'admin_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Get the teacher that owns the withdrawal
     */
    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}

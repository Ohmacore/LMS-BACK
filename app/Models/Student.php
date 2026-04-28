<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_balance',
        'referral_code',
        'referred_by',
    ];

    protected $casts = [
        'wallet_balance' => 'decimal:2',
    ];

    /**
     * Get the user that owns the student profile
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the student who referred this student
     */
    public function referrer()
    {
        return $this->belongsTo(Student::class, 'referred_by');
    }

    /**
     * Get the students referred by this student
     */
    public function referrals()
    {
        return $this->hasMany(Student::class, 'referred_by');
    }
}

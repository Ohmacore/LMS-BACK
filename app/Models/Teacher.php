<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $fillable = [
        'user_id',
        'pseudo',
        'domain_of_interest',
        'domain',
        'year',
        'bio',
        'rating',
        'total_students',
        'bank_account',
        'status',
        'notes',
    ];

    protected $casts = [
        'rating' => 'decimal:2',
    ];

    /**
     * Get the user that owns the teacher profile
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the modules created by this teacher
     */
    public function modules()
    {
        return $this->hasMany(Module::class);
    }

    /**
     * Get the withdrawal requests for this teacher
     */
    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }
}

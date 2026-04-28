<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'student_id',
        'type',
        'amount',
        'status',
        'receipt_url',
        'description',
        'module_id',
        'enrollment_id',
        'validated_by',
        'validated_at',
        'notes',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    /**
     * Get the student that owns the transaction
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the module associated with this transaction
     */
    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * Get the enrollment associated with this transaction
     */
    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * Get the user (admin) who validated this transaction
     */
    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}

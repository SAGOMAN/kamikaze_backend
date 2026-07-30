<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'nickname',
        'email',
        'phone',
        'birth_date',
        'enrolled_at',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'enrolled_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function membershipPayments(): HasMany
    {
        return $this->hasMany(MembershipPayment::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}

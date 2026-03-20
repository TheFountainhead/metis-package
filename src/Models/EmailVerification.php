<?php

namespace TheFountainhead\Metis\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmailVerification extends Model
{
    protected $table = 'email_verifications';

    protected $fillable = [
        'email',
        'code',
        'expires_at',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < 3;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now())
            ->whereNull('verified_at');
    }
}

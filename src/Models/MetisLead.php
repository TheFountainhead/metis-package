<?php

namespace TheFountainhead\Metis\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TheFountainhead\Metis\Database\Factories\MetisLeadFactory;

class MetisLead extends Model
{
    use HasFactory;

    protected $table = 'metis_leads';

    protected $fillable = [
        'email',
        'name',
        'cvr',
        'company_name',
        'industry',
        'domain',
        'first_search_type',
        'first_search_term',
        'lookup_count',
        'last_active_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'lookup_count' => 'integer',
    ];

    public function lookups(): HasMany
    {
        return $this->hasMany(MetisLookup::class, 'email', 'email');
    }

    protected static function newFactory(): MetisLeadFactory
    {
        return MetisLeadFactory::new();
    }
}

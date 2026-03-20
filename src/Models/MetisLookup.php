<?php

namespace TheFountainhead\Metis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetisLookup extends Model
{
    protected $table = 'metis_lookups';

    protected $fillable = [
        'session_id',
        'email',
        'search_type',
        'search_term',
        'ip_address',
        'is_cross_reference',
    ];

    protected $casts = [
        'is_cross_reference' => 'boolean',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MetisLead::class, 'email', 'email');
    }
}

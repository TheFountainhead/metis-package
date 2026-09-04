<?php

namespace TheFountainhead\Metis\Models;

use Illuminate\Database\Eloquent\Model;

class MetisAnalysisRequest extends Model
{
    public const PURPOSES = [
        'kreditvurdering' => 'Kreditvurdering af et eksisterende engagement',
        'belaaning' => 'Belåning af en ejendom',
        'raadgivning' => 'Rådgivning i forbindelse med overdragelse eller belåning',
        'andet' => 'Andet',
    ];

    protected $fillable = [
        'email', 'name', 'company_name', 'question', 'area', 'purpose', 'phone', 'status', 'notes', 'handled_at', 'ip',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? $this->purpose;
    }
}

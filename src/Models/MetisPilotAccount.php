<?php

namespace TheFountainhead\Metis\Models;

use Illuminate\Database\Eloquent\Model;

class MetisPilotAccount extends Model
{
    protected $fillable = ['email', 'name', 'password', 'registry_token', 'remember_token', 'last_login_at'];

    protected $hidden = ['password', 'registry_token', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'registry_token' => 'encrypted',
        'last_login_at' => 'datetime',
    ];
}

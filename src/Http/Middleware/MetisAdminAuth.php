<?php

namespace TheFountainhead\Metis\Http\Middleware;

use Closure;

class MetisAdminAuth
{
    public function handle($request, Closure $next)
    {
        if (! session('metis_admin_authenticated')) {
            return redirect()->route('metis.admin.login');
        }

        return $next($request);
    }
}

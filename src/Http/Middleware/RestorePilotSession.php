<?php

namespace TheFountainhead\Metis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;
use TheFountainhead\Metis\Livewire\PilotLogin;
use TheFountainhead\Metis\Models\MetisPilotAccount;

/**
 * Genskaber en pilotsession fra husk-mig-cookien, når værtens session er
 * udløbet (120 min. som standard), så piloten ikke skal logge ind igen.
 * Cookien er "{konto-id}|{tilfældig nøgle}"; nøglen sammenlignes mod den
 * hashede kopi på kontoen. Sessionen får kun det, login også ville give.
 */
class RestorePilotSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (empty(session('metis_user_token')) && ($raw = $request->cookie(PilotLogin::REMEMBER_COOKIE))) {
            [$id, $secret] = array_pad(explode('|', (string) $raw, 2), 2, null);

            $account = ctype_digit((string) $id) ? MetisPilotAccount::find((int) $id) : null;

            if ($account && $secret && $account->remember_token && Hash::check($secret, $account->remember_token)) {
                PilotLogin::attach($account);
            } else {
                // En cookie der ikke passer (roteret nøgle, logget ud, anden
                // enhed) skal væk, ellers koster den en hash-sammenligning pr. request.
                cookie()->queue(cookie()->forget(PilotLogin::REMEMBER_COOKIE));
            }
        }

        return $next($request);
    }
}

<?php

namespace TheFountainhead\Metis\Http\Controllers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use TheFountainhead\Metis\Exceptions\CriiptoUnreachableException;

class AdminAuthController extends Controller
{
    public function login()
    {
        return view('metis::livewire.admin.login');
    }

    public function redirect()
    {
        try {
            return Socialite::driver('criipto')->redirect();
        } catch (CriiptoUnreachableException $e) {
            return $this->mitidUnreachable('redirect', $e);
        }
    }

    public function callback()
    {
        try {
            $user = Socialite::driver('criipto')->user();
        } catch (ConnectException|ServerException|CriiptoUnreachableException $e) {
            return $this->mitidUnreachable('callback', $e);
        }

        $cpr = $user->getRaw()['cprNumberIdentifier'] ?? $user->getId();
        // Strip non-digits from CPR
        $cpr = preg_replace('/\D/', '', $cpr);

        $allowedCprs = array_map('trim', explode(',', config('metis.admin.allowed_cprs', '')));

        if (! in_array($cpr, $allowedCprs)) {
            return redirect()->route('metis.admin.login')
                ->with('error', 'Du har ikke adgang til admin-panelet.');
        }

        session(['metis_admin_authenticated' => true, 'metis_admin_cpr' => $cpr]);

        return redirect()->route('metis.admin.dashboard');
    }

    /**
     * Criipto unreachable (DNS/network, 5xx, circuit breaker). Logged at error
     * so an outage shows in Flare, with the message only: the raw exception's
     * frames carry the OAuth code and client_secret.
     */
    private function mitidUnreachable(string $phase, \Throwable $e)
    {
        Log::error("MitID identity provider unreachable during {$phase}", ['message' => $e->getMessage()]);

        return redirect()->route('metis.admin.login')
            ->with('error', 'MitID kan ikke nås lige nu. Prøv igen om et øjeblik.');
    }

    public function logout()
    {
        session()->forget(['metis_admin_authenticated', 'metis_admin_cpr']);

        return redirect()->route('metis.admin.login');
    }
}

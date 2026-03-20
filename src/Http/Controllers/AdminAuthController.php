<?php

namespace TheFountainhead\Metis\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Socialite\Facades\Socialite;

class AdminAuthController extends Controller
{
    public function login()
    {
        return view('metis::livewire.admin.login');
    }

    public function redirect()
    {
        return Socialite::driver('criipto')->redirect();
    }

    public function callback()
    {
        $user = Socialite::driver('criipto')->user();
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

    public function logout()
    {
        session()->forget(['metis_admin_authenticated', 'metis_admin_cpr']);

        return redirect()->route('metis.admin.login');
    }
}

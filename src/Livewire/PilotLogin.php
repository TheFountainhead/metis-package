<?php

namespace TheFountainhead\Metis\Livewire;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;
use TheFountainhead\Metis\Models\MetisLead;
use TheFountainhead\Metis\Models\MetisPilotAccount;

/**
 * Log ind for pilotbrugere: mail + kodeord.
 *
 * Kontoen bærer registry-api-tokenet, så piloten aldrig ser et token og ikke
 * skal bekræfte sin mail med en kode hver gang. Ved login hæftes tokenet på
 * sessionen præcis som EmailGate gør for `metis.gating.pilot_users`, og en
 * husk-mig-cookie (30 dage) genskaber sessionen via RestorePilotSession.
 */
class PilotLogin extends Component
{
    public const REMEMBER_COOKIE = 'metis_pilot_remember';

    public const REMEMBER_MINUTES = 60 * 24 * 30;

    public string $email = '';

    public string $password = '';

    public ?string $error = null;

    public function mount(): void
    {
        if (! empty(session('metis_user_token'))) {
            $this->redirect($this->destination());
        }
    }

    public function login(): void
    {
        $this->error = null;
        $this->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $email = strtolower(trim($this->email));
        $key = 'metis-pilot-login:'.$email.'|'.request()->ip();
        $ipKey = 'metis-pilot-login-ip:'.request()->ip();

        // Pr. mail+ip mod gætteri på én konto; pr. ip mod spraying på tværs af mails.
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            $this->error = __('For mange forsøg. Prøv igen om et kvarter.');

            return;
        }

        $account = MetisPilotAccount::whereRaw('lower(email) = ?', [$email])->first();

        // Samme svar uanset om mailen findes: ingen liste over pilotkonti.
        if (! $account || ! Hash::check($this->password, $account->password)) {
            RateLimiter::hit($key, 900);
            RateLimiter::hit($ipKey, 900);
            $this->error = __('Mail eller kodeord passer ikke.');

            return;
        }

        RateLimiter::clear($key);
        self::attach($account);

        $remember = Str::random(60);
        $account->forceFill(['remember_token' => Hash::make($remember), 'last_login_at' => now()])->save();
        // secure = null følger session.secure, så husk-mig også virker på http lokalt.
        cookie()->queue(self::REMEMBER_COOKIE, $account->id.'|'.$remember, self::REMEMBER_MINUTES, null, null, null, true);

        $this->redirect($this->destination());
    }

    /**
     * Hæfter kontoens token og mail på sessionen. Deles med RestorePilotSession.
     * Gør det samme som EmailGate::verifyCode gør for en pilot: lead-rækken
     * holdes ajour, så piloten ses i admin og forudfyldes i formularer.
     */
    public static function attach(MetisPilotAccount $account): void
    {
        session()->regenerate();
        session([
            'metis_user_token' => $account->registry_token,
            'metis_verified_email' => $account->email,
            'metis_pilot_account_id' => $account->id,
        ]);

        MetisLead::updateOrCreate(
            ['email' => $account->email],
            ['name' => $account->name, 'domain' => strtolower(substr($account->email, strrpos($account->email, '@') + 1)), 'last_active_at' => now()],
        );
        cookie()->queue('metis_email', $account->email, self::REMEMBER_MINUTES);
    }

    /**
     * Log ud: husk-mig-nøglen ugyldiggøres på serveren, cookien og HELE
     * pilot-identiteten fjernes fra sessionen. Ellers ville næste person ved
     * samme browser arve "verificeret"-status uden gate.
     */
    public static function logout(): void
    {
        if ($id = session('metis_pilot_account_id')) {
            MetisPilotAccount::whereKey($id)->update(['remember_token' => null]);
        }
        session()->forget(['metis_user_token', 'metis_pilot_account_id', 'metis_verified_email']);
        cookie()->queue(cookie()->forget(self::REMEMBER_COOKIE));
        cookie()->queue(cookie()->forget('metis_email'));
    }

    private function destination(): string
    {
        return route(\Illuminate\Support\Facades\Route::has('metis.engagements') ? 'metis.engagements' : 'metis.home');
    }

    public function render()
    {
        $view = view('metis::livewire.pilot-login');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}

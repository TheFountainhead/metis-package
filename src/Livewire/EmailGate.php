<?php

namespace TheFountainhead\Metis\Livewire;

use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\On;
use Livewire\Component;
use TheFountainhead\Metis\Mail\VerificationCode;
use TheFountainhead\Metis\Models\EmailVerification;
use TheFountainhead\Metis\Models\MetisLead;
use TheFountainhead\Metis\Services\CompanyEmailResolver;
use TheFountainhead\Metis\Services\DisposableEmail;
use TheFountainhead\Metis\Services\FreeEmailDetector;
use TheFountainhead\Metis\Services\LeadNotifier;

class EmailGate extends Component
{
    public string $name = '';

    public string $email = '';

    public string $code = '';

    public string $step = 'email'; // email | verify

    public bool $nameError = false;

    public bool $emailError = false;

    public ?string $emailErrorMessage = null;

    public bool $codeError = false;

    public bool $show = false;

    #[On('show-email-gate')]
    public function showGate(): void
    {
        $this->show = true;
    }

    // Firma-data resolves i baggrunden fra mail-domænet og gemmes på leaden.
    public string $cvr = '';

    public string $companyName = '';

    public function sendCode(): void
    {
        $this->reset(['nameError', 'emailError', 'emailErrorMessage', 'codeError', 'companyName']);

        if (trim($this->name) === '') {
            $this->nameError = true;

            return;
        }

        if (! filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->emailError = true;
            $this->emailErrorMessage = 'invalid';

            return;
        }

        // Check disposable email
        if ((new DisposableEmail)->isDisposable($this->email)) {
            $this->emailError = true;
            $this->emailErrorMessage = 'disposable';

            return;
        }

        // Kun arbejdsmail: afvis private mail-domæner (gmail/hotmail o.l.)
        if (config('metis.gating.require_business_email')
            && app(FreeEmailDetector::class)->isFreeEmail($this->email)) {
            $this->emailError = true;
            $this->emailErrorMessage = 'free_email';

            return;
        }

        // Rate limit: codes per email per hour
        $codePerEmail = config('metis.rate_limits.code_per_email', 3);
        $recentCount = EmailVerification::where('email', $this->email)
            ->where('created_at', '>', now()->subHour())
            ->count();
        if ($recentCount >= $codePerEmail) {
            $this->emailError = true;
            $this->emailErrorMessage = 'rate_limited';

            return;
        }

        // Rate limit: distinct emails per IP per hour
        $codePerIp = config('metis.rate_limits.code_per_ip', 5);
        $ipEmailCount = EmailVerification::where('ip_address', request()->ip())
            ->where('created_at', '>', now()->subHour())
            ->distinct('email')
            ->count('email');
        if ($ipEmailCount >= $codePerIp) {
            $this->emailError = true;
            $this->emailErrorMessage = 'rate_limited';

            return;
        }

        // Slå firmaet op fra domænet i baggrunden (gemmes på leaden ved verificering).
        // Brugeren ser intet firma-/CVR-trin — navn + arbejdsmail er nok. Et fejlende
        // firma-opslag må aldrig blokere tilmeldingen, så det er best-effort.
        try {
            $matches = app(CompanyEmailResolver::class)->resolve($this->email);
            if (count($matches) >= 1) {
                $this->companyName = $matches[0]['name'];
                $this->cvr = $matches[0]['cvr'];
            }
        } catch (\Throwable) {
            // firma forbliver ukendt; navn + mail er tilstrækkeligt
        }

        $this->sendVerificationCode();
    }

    protected function sendVerificationCode(): void
    {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerification::create([
            'email' => $this->email,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => request()->ip(),
        ]);

        Mail::to($this->email)->send(new VerificationCode($code));
        $this->step = 'verify';
    }

    public function verifyCode(): void
    {
        $this->codeError = false;

        $verification = EmailVerification::where('email', $this->email)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $verification || ! $verification->hasAttemptsLeft()) {
            $this->codeError = true;

            return;
        }

        if ($verification->code !== $this->code) {
            $verification->increment('attempts');
            $this->codeError = true;

            return;
        }

        $verification->update(['verified_at' => now()]);

        // Create or update lead
        $lead = MetisLead::updateOrCreate(
            ['email' => $this->email],
            [
                'name' => trim($this->name) ?: null,
                'cvr' => $this->cvr ?: null,
                'company_name' => $this->companyName ?: null,
                'domain' => strtolower(substr($this->email, strrpos($this->email, '@') + 1)),
                'last_active_at' => now(),
            ]
        );

        // Notify about new lead
        if ($lead->wasRecentlyCreated) {
            app(LeadNotifier::class)->notify($lead, 'verification', $this->email);
        }

        cookie()->queue('metis_email', $this->email, 60 * 24 * 30);
        session(['metis_verified_email' => $this->email]);

        if ($token = $this->pilotToken($this->email)) {
            session(['metis_user_token' => $token]);
        }

        $this->dispatch('email-verified', email: $this->email);
        $this->show = false;
    }

    protected function pilotToken(string $email): ?string
    {
        foreach (explode(',', (string) config('metis.gating.pilot_users', '')) as $pair) {
            [$pilotEmail, $token] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ($token && strcasecmp((string) $pilotEmail, $email) === 0) {
                return $token;
            }
        }

        return null;
    }

    public function resendCode(): void
    {
        $this->step = 'email';
        $this->code = '';
        $this->sendCode();
    }

    public function render()
    {
        return view('metis::livewire.email-gate');
    }
}

<?php

namespace TheFountainhead\Metis\Livewire;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use TheFountainhead\Metis\Mail\AnalysisRequestNotification;
use TheFountainhead\Metis\Models\MetisAnalysisRequest;
use TheFountainhead\Metis\Models\MetisLead;
use TheFountainhead\Metis\Services\DisposableEmail;
use TheFountainhead\Metis\Services\FreeEmailDetector;

/**
 * Bestil en analyse.
 *
 * Spørgsmål på tværs af registret ("hvor mange erhvervsejendomme i 2100 har lån
 * med rente over 10 %?") besvares ikke live. De bestilles, vi vurderer formålet
 * (tinglysningslovens § 50 c: kreditvurdering, belåning og rådgivning i
 * forbindelse hermed) og prissætter opgaven fra gang til gang. Siden kalder
 * ingen API'er; den gemmer bestillingen og sender en mail til os.
 */
class AnalysisRequest extends Component
{
    public string $question = '';

    public string $area = '';

    public string $purpose = '';

    public string $email = '';

    public string $name = '';

    public string $company = '';

    public string $phone = '';

    public bool $sent = false;

    public ?string $error = null;

    public function mount(): void
    {
        $email = (string) session('metis_verified_email', '');

        if ($email !== '') {
            $this->email = $email;
            $lead = MetisLead::where('email', $email)->first();
            $this->name = (string) ($lead?->name ?? '');
            $this->company = (string) ($lead?->company_name ?? '');
        }
    }

    public function submit(FreeEmailDetector $freeEmails): void
    {
        $this->error = null;

        $this->validate([
            'question' => ['required', 'string', 'min:20', 'max:2000'],
            'area' => ['nullable', 'string', 'max:200'],
            'purpose' => ['required', 'in:'.implode(',', array_keys(MetisAnalysisRequest::PURPOSES))],
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
        ], [
            'question.min' => __('Beskriv spørgsmålet lidt mere, mindst 20 tegn.'),
            'purpose.required' => __('Vælg hvad analysen skal bruges til.'),
        ]);

        if ($freeEmails->isFreeEmail($this->email)) {
            $this->error = __('Brug en arbejdsmail, så vi kan se hvilket selskab bestillingen kommer fra.');

            return;
        }

        if ((new DisposableEmail)->isDisposable($this->email)) {
            $this->error = __('Brug en arbejdsmail, så vi kan se hvilket selskab bestillingen kommer fra.');

            return;
        }

        $key = 'metis-analysis-request:'.strtolower($this->email);
        $ipKey = 'metis-analysis-request-ip:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            $this->error = __('Du har sendt fem bestillinger i dag. Skriv til os direkte, hvis der er flere.');

            return;
        }

        $request = MetisAnalysisRequest::create([
            'email' => strtolower(trim($this->email)),
            'name' => trim($this->name) ?: null,
            'company_name' => trim($this->company) ?: null,
            'question' => trim($this->question),
            'area' => trim($this->area) ?: null,
            'purpose' => $this->purpose,
            'phone' => trim($this->phone) ?: null,
            'ip' => request()->ip(),
        ]);

        RateLimiter::hit($key, 86400);
        RateLimiter::hit($ipKey, 86400);

        // Bestillingen er gemt; en mailfejl må ikke koste kunden den (samme
        // regel som EmailGate). Fejlen logges, og rækken kan findes i tabellen.
        if ($to = config('metis.admin.notify_email')) {
            rescue(fn () => Mail::to($to)->send(new AnalysisRequestNotification($request)), report: true);
        }

        $this->sent = true;
    }

    public function render()
    {
        $view = view('metis::livewire.analysis-request', ['purposes' => MetisAnalysisRequest::PURPOSES]);

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}

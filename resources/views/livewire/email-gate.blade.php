<div>
@if($show)
<div
    class="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    aria-labelledby="email-gate-title"
    x-data
    x-trap.noscroll="true"
    @keydown.escape.prevent
>
    <div class="bg-white rounded-2xl shadow-xl p-8 w-full max-w-sm mx-4 md:mx-0">
        @if($step === 'email')
            <h2 id="email-gate-title" class="text-lg font-bold text-ink-800 text-center mb-6">
                Indtast din email for at fortsætte
            </h2>
            <input
                wire:model="email"
                type="email"
                class="w-full bg-sand-50 border border-sand-200 rounded-lg px-4 py-3 text-sm text-ink-800 placeholder-sand-300 focus:ring-2 focus:ring-warm-500/20 focus:border-warm-500 outline-none"
                placeholder="din@email.dk"
                autofocus
                aria-label="Email-adresse"
            >
            @if($emailError)
                <p class="text-red-600 text-xs mt-2" role="alert">
                    @if($emailErrorMessage === 'disposable')
                        Brug venligst en rigtig email-adresse.
                    @elseif($emailErrorMessage === 'rate_limited')
                        For mange forsøg. Prøv igen senere.
                    @else
                        Indtast en gyldig email-adresse.
                    @endif
                </p>
            @endif
            <button wire:click="sendCode" class="w-full mt-4 bg-warm-500 text-white rounded-lg py-3 min-h-[44px] text-sm font-semibold hover:bg-warm-600 transition">
                Send kode
            </button>
            <p class="text-center text-sand-300 text-xs mt-4">Vi deler ikke din email.</p>

        @elseif($step === 'cvr')
            <h2 id="email-gate-title" class="text-lg font-bold text-ink-800 text-center mb-2">
                Hvilken virksomhed er du fra?
            </h2>
            <p class="text-ink-700/60 text-sm text-center mb-6">{{ $email }}</p>
            <input
                wire:model="cvr"
                type="text"
                inputmode="numeric"
                maxlength="8"
                class="w-full bg-sand-50 border border-sand-200 rounded-lg px-4 py-3 text-sm text-ink-800 placeholder-sand-300 focus:ring-2 focus:ring-warm-500/20 focus:border-warm-500 outline-none"
                placeholder="CVR-nummer (valgfrit)"
                autofocus
                aria-label="CVR-nummer"
            >
            @if($emailError && $emailErrorMessage === 'invalid_cvr')
                <p class="text-red-600 text-xs mt-2" role="alert">Indtast et gyldigt 8-cifret CVR-nummer.</p>
            @endif
            <button wire:click="submitCvr" class="w-full mt-4 bg-warm-500 text-white rounded-lg py-3 min-h-[44px] text-sm font-semibold hover:bg-warm-600 transition">
                Fortsæt
            </button>
            <button wire:click="skipCvr" class="w-full mt-2 text-sand-300 text-xs hover:text-ink-700 transition">
                Spring over
            </button>

        @elseif($step === 'company_confirm')
            <h2 id="email-gate-title" class="text-lg font-bold text-ink-800 text-center mb-2">
                @if(count($companyMatches) === 1)
                    Vi fandt din virksomhed
                @else
                    Vælg din virksomhed
                @endif
            </h2>
            <p class="text-ink-700/60 text-sm text-center mb-6">{{ $email }}</p>

            @if(count($companyMatches) === 1)
                <div class="bg-sand-50 border border-sand-200 rounded-lg p-4 mb-4 text-center">
                    <p class="text-ink-800 font-semibold">{{ $companyMatches[0]['name'] }}</p>
                    <p class="text-sand-300 text-xs mt-1">CVR {{ $companyMatches[0]['cvr'] }}</p>
                </div>
                <button wire:click="confirmCompany" class="w-full bg-warm-500 text-white rounded-lg py-3 min-h-[44px] text-sm font-semibold hover:bg-warm-600 transition">
                    Ja, det er korrekt
                </button>
                <button wire:click="skipCvr" class="w-full mt-2 text-sand-300 text-xs hover:text-ink-700 transition">
                    Det er ikke min virksomhed
                </button>
            @else
                <div class="space-y-2 mb-4 max-h-48 overflow-y-auto">
                    @foreach($companyMatches as $match)
                        <button
                            wire:click="selectCompany(@js($match['cvr']), @js($match['name']))"
                            class="w-full bg-sand-50 border border-sand-200 rounded-lg p-3 text-left hover:bg-sand-100 transition"
                        >
                            <p class="text-ink-800 text-sm font-semibold">{{ $match['name'] }}</p>
                            <p class="text-sand-300 text-xs">CVR {{ $match['cvr'] }}{{ $match['industry'] ? ' · ' . $match['industry'] : '' }}</p>
                        </button>
                    @endforeach
                </div>
                <button wire:click="skipCvr" class="w-full text-sand-300 text-xs hover:text-ink-700 transition">
                    Ingen af disse
                </button>
            @endif

        @elseif($step === 'verify')
            <h2 id="email-gate-title" class="text-lg font-bold text-ink-800 text-center mb-2">
                Indtast koden vi sendte til
            </h2>
            <p class="text-ink-700/60 text-sm text-center mb-6">{{ $email }}</p>
            <input
                wire:model="code"
                type="text"
                inputmode="numeric"
                maxlength="6"
                class="w-full bg-sand-50 border border-sand-200 rounded-lg px-4 py-3 text-center text-2xl tracking-[0.3em] font-mono text-ink-800 focus:ring-2 focus:ring-warm-500/20 focus:border-warm-500 outline-none"
                placeholder="000000"
                autofocus
                autocomplete="one-time-code"
                aria-label="Verifikationskode"
            >
            @if($codeError)
                <p class="text-red-600 text-xs mt-2 text-center" role="alert">Forkert kode. Prøv igen.</p>
            @endif
            <button wire:click="verifyCode" class="w-full mt-4 bg-warm-500 text-white rounded-lg py-3 min-h-[44px] text-sm font-semibold hover:bg-warm-600 transition">
                Vis resultat
            </button>
            <p class="text-center text-sand-300 text-xs mt-4">
                Fik du ikke koden?
                <button wire:click="resendCode" class="text-warm-500 underline hover:text-warm-600">Send igen</button>
            </p>
        @endif
    </div>
</div>
@endif
</div>

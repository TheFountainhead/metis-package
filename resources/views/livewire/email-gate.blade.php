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
            <h2 id="email-gate-title" class="text-lg font-bold text-ink-800 text-center mb-2">
                Indtast navn og arbejdsmail for at fortsætte
            </h2>
            <p class="text-ink-700/60 text-sm text-center mb-6">Vi sender en kode til din mail. Intet password.</p>
            <input
                wire:model="name"
                type="text"
                class="w-full bg-sand-50 border border-sand-200 rounded-lg px-4 py-3 text-sm text-ink-800 placeholder-sand-300 focus:ring-2 focus:ring-warm-500/20 focus:border-warm-500 outline-none"
                placeholder="Dit navn"
                autofocus
                aria-label="Navn"
            >
            @if($nameError)
                <p class="text-red-600 text-xs mt-2" role="alert">Indtast dit navn.</p>
            @endif
            <input
                wire:model="email"
                type="email"
                class="w-full mt-3 bg-sand-50 border border-sand-200 rounded-lg px-4 py-3 text-sm text-ink-800 placeholder-sand-300 focus:ring-2 focus:ring-warm-500/20 focus:border-warm-500 outline-none"
                placeholder="dig@virksomhed.dk"
                aria-label="Arbejdsmail"
            >
            @if($emailError)
                <p class="text-red-600 text-xs mt-2" role="alert">
                    @if($emailErrorMessage === 'disposable')
                        Brug venligst en rigtig email-adresse.
                    @elseif($emailErrorMessage === 'free_email')
                        Brug venligst din arbejdsmail, ikke en privat mail.
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
            <p class="text-center text-sand-300 text-xs mt-4">Vi deler ikke dine oplysninger.</p>

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

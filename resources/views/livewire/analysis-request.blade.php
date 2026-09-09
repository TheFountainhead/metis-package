<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Bestil en analyse') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Spørgsmål på tværs af ejendomme og pant, besvaret som en opgave') }}</span>
        </div>
        @php
            $backRoute = Route::has('metis.index') ? 'metis.index' : (Route::has('metis.home') ? 'metis.home' : null);
        @endphp
        @if($backRoute)
            <a href="{{ route($backRoute) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                ← {{ __('Tilbage') }}
            </a>
        @endif
    </div>

    @if($sent)
        <div class="max-w-2xl p-6 bg-white border rounded-xl">
            <h2 class="text-lg font-semibold mb-2">{{ __('Tak, vi har modtaget bestillingen') }}</h2>
            <p class="text-sm text-zinc-600">
                {{ __('Vi vender tilbage på :email med et tilbud og eventuelle opklarende spørgsmål, normalt inden for en arbejdsdag.', ['email' => $email]) }}
            </p>
        </div>
    @else
        <div class="max-w-2xl space-y-4">
            <p class="text-sm text-zinc-700">
                {{ __('Enkeltopslag på en ejendom, et selskab eller en person kan du lave selv i Metis. Spørgsmål på tværs af registret, fx alle erhvervsejendomme i et område med lån over en bestemt rente, laver vi som en opgave: vi vurderer formålet, afgrænser data og sender dig resultatet som en rapport eller et regneark.') }}
            </p>
            <p class="p-3 text-xs border rounded-lg border-zinc-200 bg-zinc-50 text-zinc-600">
                {{ __('Prissættes pr. opgave. Du får et tilbud, før vi går i gang. Tinglysningsdata må kun bruges til kreditvurdering, belåning og rådgivning i forbindelse hermed (tinglysningslovens § 50 c), så vi spørger til formålet.') }}
            </p>

            @if($error)
                <div class="p-4 text-sm border rounded-lg border-amber-200 bg-amber-50 text-amber-900">{{ $error }}</div>
            @endif

            <form wire:submit="submit" class="space-y-4 p-6 bg-white border rounded-xl">
                <div>
                    <label for="ar-question" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Hvad vil du have svar på?') }}</label>
                    <textarea id="ar-question" wire:model="question" rows="4" class="w-full px-3 py-2 text-sm border rounded-lg"
                              placeholder="{{ __('Fx: erhvervsejendomme i 2100 med tinglyst gæld over 10 mio. kr., hvor renten på mindst ét lån er over 10 %.') }}"></textarea>
                    @error('question') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="ar-area" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Område (postnumre, kommune eller by)') }}</label>
                        <input id="ar-area" type="text" wire:model="area" class="w-full px-3 py-2 text-sm border rounded-lg" placeholder="2100, 2150">
                        @error('area') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ar-purpose" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Analysen skal bruges til') }}</label>
                        <select id="ar-purpose" wire:model="purpose" class="w-full px-3 py-2 text-sm border rounded-lg">
                            <option value="">{{ __('Vælg') }}</option>
                            @foreach($purposes as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('purpose') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="ar-name" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Navn') }}</label>
                        <input id="ar-name" type="text" wire:model="name" class="w-full px-3 py-2 text-sm border rounded-lg">
                    </div>
                    <div>
                        <label for="ar-company" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Selskab') }}</label>
                        <input id="ar-company" type="text" wire:model="company" class="w-full px-3 py-2 text-sm border rounded-lg">
                    </div>
                    <div>
                        <label for="ar-email" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Arbejdsmail') }}</label>
                        <input id="ar-email" type="email" wire:model="email" class="w-full px-3 py-2 text-sm border rounded-lg">
                        @error('email') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ar-phone" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Telefon (valgfrit)') }}</label>
                        <input id="ar-phone" type="tel" wire:model="phone" class="w-full px-3 py-2 text-sm border rounded-lg">
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                            class="px-4 py-2 text-sm font-medium text-white bg-zinc-900 rounded-lg hover:bg-zinc-700 disabled:opacity-50 transition">
                        <span wire:loading.remove wire:target="submit">{{ __('Send bestilling') }}</span>
                        <span wire:loading wire:target="submit">{{ __('Sender') }}</span>
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>

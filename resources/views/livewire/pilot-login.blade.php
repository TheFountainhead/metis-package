<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Log ind') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('For pilotbrugere med kodeord') }}</span>
        </div>
    </div>

    <div class="max-w-md p-6 bg-white border rounded-xl">
        @if($error)
            <div class="p-3 mb-4 text-sm border rounded-lg border-amber-200 bg-amber-50 text-amber-900">{{ $error }}</div>
        @endif

        <form wire:submit="login" class="space-y-4">
            <div>
                <label for="pl-email" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Arbejdsmail') }}</label>
                <input id="pl-email" type="email" wire:model="email" autocomplete="username" class="w-full px-3 py-2 text-sm border rounded-lg">
                @error('email') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="pl-password" class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Kodeord') }}</label>
                <input id="pl-password" type="password" wire:model="password" autocomplete="current-password" class="w-full px-3 py-2 text-sm border rounded-lg">
                @error('password') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="login"
                    class="w-full px-4 py-2 text-sm font-medium text-white bg-zinc-900 rounded-lg hover:bg-zinc-700 disabled:opacity-50 transition">
                {{ __('Log ind') }}
            </button>
        </form>
        <p class="mt-4 text-xs text-zinc-500">{{ __('Du forbliver logget ind i 30 dage på denne enhed. Har du ikke et kodeord, så skriv til os.') }}</p>
    </div>
</div>

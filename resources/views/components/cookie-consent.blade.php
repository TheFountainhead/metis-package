<div
    x-data="{ show: !document.cookie.includes('metis_consent=') }"
    x-show="show"
    x-transition
    class="fixed bottom-16 inset-x-0 z-40 bg-white border border-sand-200 p-4 shadow-lg rounded-xl mx-3 sm:mx-auto sm:max-w-2xl"
    role="region"
    aria-label="Cookie-samtykke"
>
    <div class="flex flex-col gap-3">
        <p class="text-sm text-ink-700">
            Vi bruger cookies til at forbedre din oplevelse og huske dine opslag.
            <a href="#" class="text-warm-500 underline">Læs mere</a>
        </p>
        <div class="grid grid-cols-2 gap-2">
            <button
                @click="document.cookie = 'metis_consent=essential; max-age=31536000; path=/'; show = false"
                class="px-4 py-2.5 text-sm border border-sand-200 rounded-lg text-ink-800 hover:bg-sand-100 transition text-center"
            >
                Kun nødvendige
            </button>
            <button
                @click="document.cookie = 'metis_consent=all; max-age=31536000; path=/'; show = false"
                class="px-4 py-2.5 text-sm bg-ink-800 text-white rounded-lg hover:bg-ink-900 transition text-center"
            >
                Acceptér alle
            </button>
        </div>
    </div>
</div>

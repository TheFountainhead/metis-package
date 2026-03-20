<div
    x-data="{ show: !document.cookie.includes('metis_consent=') }"
    x-show="show"
    x-transition
    class="fixed bottom-0 inset-x-0 z-40 bg-white border-t border-linen p-4 shadow-lg"
    role="region"
    aria-label="Cookie-samtykke"
>
    <div class="max-w-content mx-auto flex flex-col sm:flex-row items-start sm:items-center gap-4">
        <p class="text-sm text-text-secondary flex-1">
            Vi bruger cookies til at forbedre din oplevelse og huske dine opslag.
            <a href="#" class="text-claret underline">Læs mere</a>
        </p>
        <div class="flex gap-2 shrink-0">
            <button
                @click="document.cookie = 'metis_consent=essential; max-age=31536000; path=/'; show = false"
                class="px-4 py-2 text-sm border border-linen rounded-lg text-text-primary hover:bg-wheat/50 transition"
            >
                Kun nødvendige
            </button>
            <button
                @click="document.cookie = 'metis_consent=all; max-age=31536000; path=/'; show = false"
                class="px-4 py-2 text-sm bg-teal text-white rounded-lg hover:bg-teal/90 transition"
            >
                Acceptér alle
            </button>
        </div>
    </div>
</div>

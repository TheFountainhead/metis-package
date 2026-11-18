<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ $listeId !== null && $liste ? $liste['name'] : __('Lister') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Dine egne lister over ejendomme og selskaber') }}</span>
        </div>
        @if($listeId !== null && Route::has('metis.lists'))
            <a href="{{ route('metis.lists') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">← {{ __('Alle lister') }}</a>
        @endif
    </div>

    @if(! $this->maaBruge())
        {{-- Uden adgang vises ALDRIG en tom liste: tomhed ville læses som "du har ingen lister". --}}
        <div class="max-w-xl p-6 bg-white border rounded-xl">
            <h2 class="text-lg font-semibold mb-2">{{ __('Kun for pilotbrugere') }}</h2>
            <p class="text-sm text-zinc-600 mb-4">{{ __('Bekræft din arbejdsmail for at bruge lister.') }}</p>
            <button type="button" wire:click="$dispatch('show-email-gate')" class="px-4 py-2 text-sm font-medium text-white bg-zinc-900 rounded-lg hover:bg-zinc-700 transition">{{ __('Bekræft arbejdsmail') }}</button>
        </div>
    @else
        @if($fejl)
            <div class="max-w-xl p-4 mb-4 text-sm border rounded-xl border-amber-200 bg-amber-50 text-amber-900">
                {{ $fejl }}
                @if(($listeId === null && $lister === null) || ($listeId !== null && $liste === null))
                    <button type="button" wire:click="hent" class="ml-2 underline">{{ __('Prøv igen') }}</button>
                @endif
            </div>
        @endif

        @if($listeId === null)
            <form wire:submit="opret" class="flex gap-2 max-w-xl mb-4">
                <input type="text" wire:model="nytNavn" maxlength="80" placeholder="{{ __('Navn på ny liste') }}" aria-label="{{ __('Navn på ny liste') }}"
                       class="flex-1 px-3 py-2 text-sm border border-zinc-300 rounded-lg">
                <button type="submit" class="px-4 py-2 text-sm border border-zinc-300 rounded-lg hover:bg-zinc-50">{{ __('Opret liste') }}</button>
            </form>

            @if($lister === [])
                <div class="max-w-xl p-6 text-sm border rounded-xl bg-white text-zinc-600">
                    {{ __('Du har ingen lister endnu. Opret en her, eller tryk "Liste" på en ejendom eller et selskab.') }}
                </div>
            @elseif($lister !== null)
                <ul class="max-w-xl bg-white border rounded-xl divide-y divide-zinc-100">
                    @foreach($lister as $l)
                        <li wire:key="liste-{{ $l['id'] }}" data-liste-raekke class="flex items-center justify-between px-4 py-3 text-sm">
                            <a href="{{ route('metis.lists', ['id' => $l['id']]) }}" class="font-medium underline">{{ $l['name'] }}</a>
                            <span class="text-zinc-500">{{ $l['items_count'] === 1 ? __('1 element') : __(':n elementer', ['n' => $l['items_count']]) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif($findesIkke)
            <div class="max-w-xl p-6 text-sm border rounded-xl bg-white text-zinc-600">{{ __('Listen findes ikke. Den kan være slettet.') }}</div>
        @elseif($liste !== null)
            <div class="flex flex-wrap items-center gap-2 mb-4 text-sm">
                @if($omdoeber)
                    <form wire:submit="omdoeb" class="flex gap-2">
                        <input type="text" wire:model="omdoebTil" maxlength="80" aria-label="{{ __('Nyt navn') }}" class="px-3 py-1.5 border border-zinc-300 rounded-lg">
                        <button type="submit" class="px-3 py-1.5 border border-zinc-300 rounded-lg hover:bg-zinc-50">{{ __('Gem navn') }}</button>
                    </form>
                @else
                    <button type="button" wire:click="startOmdoeb" class="px-3 py-1.5 border border-zinc-300 rounded-lg hover:bg-zinc-50">{{ __('Omdøb') }}</button>
                @endif
                <button type="button" wire:click="sletListe" wire:confirm="{{ __('Slet hele listen? Det kan ikke fortrydes.') }}"
                        class="px-3 py-1.5 border border-red-200 text-red-700 rounded-lg hover:bg-red-50">{{ __('Slet liste') }}</button>
            </div>

            @if($liste['items'] === [])
                <div class="max-w-xl p-6 text-sm border rounded-xl bg-white text-zinc-600">{{ __('Listen er tom. Tryk "Liste" på en ejendom eller et selskab for at sætte den på.') }}</div>
            @else
                <table class="w-full max-w-3xl text-sm bg-white border rounded-xl">
                    <thead><tr class="text-left text-zinc-500 border-b"><th class="px-4 py-2">{{ __('Navn') }}</th><th class="px-4 py-2">{{ __('Type') }}</th><th class="px-4 py-2"></th></tr></thead>
                    <tbody>
                        @foreach($liste['items'] as $i)
                            @php $navn = $i['display_label'] !== '' ? $i['display_label'] : $i['item_value']; @endphp
                            <tr wire:key="element-{{ $i['id'] }}" data-liste-element class="border-b border-zinc-100">
                                <td class="px-4 py-2">
                                    @if($i['url'])
                                        <a href="{{ $i['url'] }}" class="underline">{{ $navn }}</a>
                                    @else
                                        {{ $navn }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-zinc-500">{{ $i['item_type'] === 'company' ? __('Selskab') : __('Ejendom') }}</td>
                                <td class="px-4 py-2 text-right"><button type="button" wire:click="fjern({{ $i['id'] }})" class="underline text-zinc-500">{{ __('Fjern') }}</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    @endif
</div>

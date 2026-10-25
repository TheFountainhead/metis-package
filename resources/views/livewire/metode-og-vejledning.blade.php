<div class="max-w-3xl mx-auto">
    <div class="flex justify-end gap-2 mb-4">
        @if(Route::has('metis.company-segmentation'))
            <a href="{{ route('metis.company-segmentation') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 dark:border-zinc-700 transition">
                {{ __('Til segmenteringen') }}
            </a>
        @endif
    </div>

    <h1 class="text-2xl font-bold text-ink-800 mb-1">{{ __('Metode og vejledning') }}</h1>
    <p class="text-ink-700/60 text-sm mb-6">
        {{ __('Hvordan tallene i selskabssegmenteringen er dannet: hvilke data vi bruger, og efter hvilke regler selskaberne bliver talt og klassificeret. Først en kort vejledning i at bruge siden.') }}
    </p>

    @if(! $this->harAdgang())
        {{-- Samme gate som segmenteringen: siden hoerer til licensen. --}}
        <div class="max-w-xl bg-sand-50 border border-sand-200 rounded-xl p-6">
            <h2 class="text-lg font-semibold text-ink-800 mb-2">{{ __('Kun for brugere med konto') }}</h2>
            <p class="text-sm text-ink-700/70 mb-4">
                {{ __('Metode og vejledning hører til selskabssegmenteringen og er forbeholdt brugere, vi har en aftale med. Log ind med din konto eller bekræft din arbejdsmail, eller skriv til support@frankston.io for adgang.') }}
            </p>
            <div class="flex flex-wrap gap-2">
                @if(Route::has('metis.login'))
                    <a href="{{ route('metis.login') }}"
                       class="inline-block px-4 py-2 text-sm font-medium text-white bg-ink-800 rounded-lg hover:bg-ink-700 transition">
                        {{ __('Log ind') }}
                    </a>
                @endif
                <button type="button" wire:click="$dispatch('show-email-gate')"
                        class="px-4 py-2 text-sm font-medium border border-sand-200 rounded-lg text-ink-800 hover:bg-white transition">
                    {{ __('Bekræft arbejdsmail') }}
                </button>
            </div>
        </div>
    @else
    {{--
        🔑 Hver regel her er laest i registry-api's kode (se klassens docblock).
        Skriv ikke en regel ind, der ikke kan peges paa i koden, og lov ikke
        funktioner, der ikke findes. Opdateringsfrekvens er udeladt MED VILJE:
        ingen af de kommandoer, der danner status, fintech og investortyper,
        er planlagt i registry-api's scheduler (maalt 25/10-2026).
    --}}
    <div class="text-sm text-ink-800 leading-relaxed space-y-10" data-metode>

        <nav class="bg-sand-50 border border-sand-200 rounded-xl p-4" aria-label="{{ __('Indhold') }}">
            <h2 class="text-xs uppercase tracking-wide text-sand-300 mb-2">Indhold</h2>
            <ol class="list-decimal list-inside space-y-1 text-ink-700">
                <li><a href="#vejledning" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Kom godt i gang</a></li>
                <li><a href="#population" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Hvilke selskaber tæller med</a></li>
                <li><a href="#ivaerksaetter" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Iværksættervirksomheder</a></li>
                <li><a href="#geografi" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Kommune og region</a></li>
                <li><a href="#branche" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Branche og vertikaler</a></li>
                <li><a href="#fintech" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Fintech</a></li>
                <li><a href="#investortype" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Investortype blandt ejerne</a></li>
                <li><a href="#stoerrelse" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Årsværk og stiftelsesdato</a></li>
                <li><a href="#kapital" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Kapitalhistorik</a></li>
                <li><a href="#udviklingsstadie" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Kapital (udviklingsstadie)</a></li>
                <li><a href="#koncern" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">Selskaber og koncerner</a></li>
            </ol>
        </nav>

        {{-- 1. Vejledning --}}
        <section id="vejledning" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">1. Kom godt i gang</h2>
            <ol class="list-decimal pl-5 space-y-3">
                <li>
                    <strong>Log ind.</strong> Segmenteringen er for brugere, vi har en aftale med.
                    Vælg <em>Log ind</em> og brug din arbejdsmail og dit kodeord. Du forbliver logget ind i 30 dage på samme enhed.
                    Står din mail på vores brugerliste, kan du i stedet vælge <em>Bekræft arbejdsmail</em>. Så sender vi en kode til din mail.
                    Har du ikke adgang, så skriv til support@frankston.io.
                </li>
                <li>
                    <strong>Vælg fordeling.</strong> Under <em>Fordel på</em> vælger du, hvordan selskaberne skal fordeles:
                    kommune, region, branche, vertikal, selskabsform eller antal kapitalforhøjelser.
                </li>
                <li>
                    <strong>Afgræns.</strong> Brug filtrene i venstre side. Tallet opdateres, når du ændrer et filter.
                    Filtrene gælder samtidig: et selskab skal opfylde dem alle for at tælle med.
                    <em>Nulstil filtre</em> fjerner alle afgrænsninger.
                </li>
                <li>
                    <strong>Læs resultatet.</strong> Det store tal er alle selskaber med de valgte afgrænsninger.
                    Tabellen viser de største grupper med antal og andel. Tælles koncerner, vises ingen andel, fordi en koncern kan tælle i flere grupper. Selskaber uden en værdi for den valgte fordeling
                    står på en særskilt linje nederst, fx <em>Uden kommune i CVR</em>.
                </li>
                <li>
                    <strong>Se selskaberne bag et tal.</strong> Klik på navnet på en gruppe i tabellen. Listen under tabellen viser
                    selskaberne med de samme afgrænsninger, 50 pr. side, med flest årsværk først. Listen går til og med de første 10.000 selskaber.
                    Klik på et selskabsnavn for at åbne selskabet.
                </li>
                <li>
                    <strong>Hent til Excel.</strong> Knappen <em>Hent til Excel (CSV)</em> henter fordelingen som en fil, der kan åbnes direkte i Excel.
                    Filen har kode, betegnelse og antal for hver gruppe. Nederst står totalen, hvor mange der er uden gruppe,
                    om ophørte selskaber er med, og den valgte investortype, fintech-afgrænsning eller de valgte kapitalfiltre, hvis du har valgt nogen.
                </li>
                <li>
                    <strong>Hent selskabslisten.</strong> Knappen <em>Hent selskabslisten (CSV)</em> henter selskaberne bag hele det viste udvalg.
                    Har du åbnet listen bag et tal i tabellen, henter <em>Hent til Excel (CSV)</em> over listen kun selskaberne i den gruppe.
                    Begge filer bruger de samme afgrænsninger som tallene på skærmen og har én række pr. selskab med CVR-nummer, navn, selskabsform,
                    kommune, branche, stiftelsesdato, højeste årsværk og status i CVR. Rækkerne står med flest årsværk først.
                    En fil har højst 10.000 selskaber. Er udvalget større, står der <em>AFKORTET</em> nederst i filen, og filnavnet ender på <em>-afkortet.csv</em>.
                    Nederst står også gruppen, antallet af selskaber i udvalget, antallet af rækker i filen og de afgrænsninger, du har valgt.
                </li>
                <li>
                    <strong>Gem eller del et udvalg.</strong> Adressen i browseren indeholder dine valg. Gem linket for at komme tilbage til samme udvalg.
                    Den, du deler linket med, skal også have en konto.
                </li>
            </ol>
        </section>

        {{-- 2. Population og ophoerte --}}
        <section id="population" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">2. Hvilke selskaber tæller med</h2>
            <p class="mb-3">
                Alle tal bygger på Det Centrale Virksomhedsregister (CVR). Hvert selskab tæller én gang.
            </p>
            <h3 class="font-semibold mb-2">Ophørte selskaber er udeladt som standard</h3>
            <p class="mb-2">Vi bruger selskabets samlede status i CVR.</p>
            <ul class="list-disc pl-5 space-y-1 mb-3">
                <li><strong>Ophørt</strong> er: Ophørt, Slettet, Tvangsopløst og alle former for Opløst, fx opløst efter konkurs, efter erklæring, efter frivillig likvidation, efter fusion eller efter spaltning.</li>
                <li><strong>Tæller med</strong>: Normal, Aktiv, Under konkurs, Under frivillig likvidation, Under tvangsopløsning, Under rekonstruktion og Under reassumering. Et selskab under konkurs eller likvidation findes stadig juridisk.</li>
                <li><strong>Ukendt status tæller med.</strong> Det gælder, når status ikke er hentet, når CVR ikke kender CVR-nummeret, og når CVR bruger en status, vi ikke kender. Ukendt er ikke det samme som ophørt.</li>
            </ul>
            <p>
                Sæt flueben i <em>Medtag ophørte selskaber</em> for at tælle dem med. Det er fx relevant, når du vil tælle alle iværksættere
                stiftet i en periode, også dem der siden er lukket.
            </p>
        </section>

        {{-- 3. Ivaerksaetter --}}
        <section id="ivaerksaetter" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">3. Iværksættervirksomheder</h2>
            <p class="mb-3">
                Filteret <em>Kun iværksættervirksomheder</em> følger Erhvervsstyrelsens definition i <em>Nye virksomheder i Danmark</em> (februar 2021):
                en ny virksomhed, der udbetaler løn til andre end ejerne. Løn er ikke offentlig, men antal årsværk er.
                Et selskab tæller derfor med, når alle tre regler er opfyldt:
            </p>
            <ol class="list-decimal pl-5 space-y-2 mb-3">
                <li><strong>Mindst ét årsværk.</strong> Selskabet har på et tidspunkt haft mindst ét årsværk i CVR's beskæftigelsestal. Et selskab uden beskæftigelsestal i CVR tæller ikke med.</li>
                <li><strong>Selskabsform.</strong> Enkeltmandsvirksomhed, interessentskab (I/S), anpartsselskab (ApS) eller aktieselskab (A/S). Definitionen nævner også iværksætterselskaber (IVS), men den selskabsform er afskaffet ved lov og findes ikke længere i registret.</li>
                <li><strong>Branche.</strong> Selskabet er ikke i finansiering og forsikring (branchekode 64, 65 og 66), fast ejendom (68) eller offentlig forvaltning og forsvar (84). Det er Erhvervsstyrelsens egne undtagelser. Selskaber uden branchekode tæller med.</li>
            </ol>
            <p class="mb-2">
                Beskæftigelsestallene er CVR's månedlige tal for årsværk. Har et selskab ikke månedlige tal, bruger vi de kvartalsvise.
            </p>
            <p>
                Filteret sætter ikke selv en aldersgrænse. Når du sætter fluebenet, foreslår siden en stiftelsesdato 5 år tilbage i feltet
                <em>Stiftet mellem</em>. Du kan rette eller fjerne datoen.
            </p>
        </section>

        {{-- 4. Geografi --}}
        <section id="geografi" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">4. Kommune og region</h2>
            <ul class="list-disc pl-5 space-y-2">
                <li><strong>Kommune</strong> er kommunekoden på selskabets adresse i CVR.</li>
                <li><strong>Region</strong> er udledt af kommunekoden. Hver kommune hører til én af de fem regioner efter Danmarks Administrative Geografiske Inddeling (DAGI).</li>
                <li><strong>Grønland.</strong> Grønlandske kommuner (kommunekode 955 til 960) har ingen dansk region. Selskaber dér står på linjen <em>Uden dansk region</em>, når du fordeler på region.</li>
                <li><strong>Selskaber uden kommune.</strong> Nogle selskaber har ingen kommune i CVR, typisk fordi de ikke har en aktuel adresse. De tæller med i totalen og står på linjen <em>Uden kommune i CVR</em> eller <em>Uden dansk region</em>. Afgrænser du til en kommune eller en region, er de ikke med.</li>
            </ul>
        </section>

        {{-- 5. Branche og vertikaler --}}
        <section id="branche" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">5. Branche og vertikaler</h2>
            <ul class="list-disc pl-5 space-y-2 mb-4">
                <li><strong>Branche</strong> er selskabets hovedbranche i CVR efter Dansk Branchekode 2025 (DB25). I filteret kan du skrive starten af en kode: <em>62</em> giver alle koder, der begynder med 62, og <em>62.10</em> giver kun dem, der begynder med 62.10 (computerprogrammering).</li>
                <li><strong>Uden holdingselskaber</strong> udelader selskaber med branchekode 64.21 (holdingselskaber). Selskaber uden branchekode bliver.</li>
            </ul>
            <h3 class="font-semibold mb-2">Vertikaler</h3>
            <p class="mb-3">
                En vertikal er en gruppe af branchekoder. Den mest specifikke kode vinder. Fx er 46.46 (engroshandel med medicinalvarer) life science,
                selv om resten af 46 er engroshandel. Selskaber uden branchekode har ingen vertikal.
            </p>
            <div class="overflow-x-auto border border-sand-200 rounded-xl mb-3">
                <table class="w-full text-sm">
                    <thead class="bg-sand-50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Vertikal</th>
                            <th class="px-4 py-2 font-semibold">Branchekoder, der begynder med</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(\TheFountainhead\Metis\Livewire\CompanySegmentation::VERTIKALER as $noegle => $navn)
                            <tr class="border-t border-sand-200" data-vertikal="{{ $noegle }}">
                                <td class="px-4 py-2">{{ $navn }}</td>
                                <td class="px-4 py-2 tabular-nums text-ink-700/80">{{ $this::VERTIKAL_BRANCHER[$noegle] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mb-2"><strong>Hvad vertikalerne ikke kan.</strong> Branchekoden beskriver selskabets aktivitet, ikke dets marked.</p>
            <ul class="list-disc pl-5 space-y-1">
                <li>Fintech og forsvarsteknologi har typisk den samme kode, 62.10.00 Computerprogrammering. Vertikalerne kan derfor ikke skille dem ad. Til fintech er der et særskilt filter, se næste afsnit.</li>
                <li>Finans (64 til 66) er finansiel virksomhed som banker og forsikring. Det er ikke det samme som fintech.</li>
                <li>Life science omfatter farmaceutiske præparater (21), medicinske og dentale instrumenter (32.5) og engroshandel med medicinalvarer (46.46). Bioteknologisk forskning har ingen kode for sig selv i DB25 og tæller under Forskning og udvikling, ikke under life science.</li>
                <li>Forskning og udvikling (72) dækker både bioteknologi, grøn teknologi og anden teknologi. Koden kan ikke deles op.</li>
                <li>Sundhed (86) er fx lægepraksis, tandlæger og fysioterapeuter. Det er ikke life science.</li>
            </ul>
        </section>

        {{-- 6. Fintech --}}
        <section id="fintech" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">6. Fintech</h2>
            <p class="mb-3">Du kan vælge mellem to afgrænsninger.</p>

            <h3 class="font-semibold mb-2">Fintech, reguleret</h3>
            <p class="mb-2">Selskaber med en tilladelse i et officielt register, matchet på CVR-nummer:</p>
            <ul class="list-disc pl-5 space-y-2 mb-3">
                <li>
                    <strong>Den Europæiske Banktilsynsmyndigheds (EBA) register</strong> over betalings- og e-pengeinstitutter med dansk tilladelse:
                    betalingsinstitutter, betalingsinstitutter med begrænset tilladelse, e-pengeinstitutter, e-pengeinstitutter med begrænset tilladelse
                    og udbydere af kontooplysningstjenester. CVR-nummeret står i registret.
                    Ikke med: virksomheder undtaget fra betalingsloven (gavekort- og brændstofkortordninger), filialer og agenter.
                </li>
                <li>
                    <strong>Den Europæiske Værdipapir- og Markedstilsynsmyndigheds (ESMA) register</strong> over udbydere af kryptoaktivtjenester (MiCA)
                    med Danmark som hjemland, hvis tilladelsen ikke er udløbet. CVR-nummeret findes via selskabets LEI-kode i det globale LEI-register (GLEIF).
                </li>
                <li>
                    Kun selskaber, der er aktive i CVR (status Normal eller Aktiv). Registrene er ikke altid ajour, og et opløst selskab tæller ikke, selv om det stadig står i registret.
                </li>
                <li>Crowdfunding indgår ikke endnu.</li>
            </ul>

            <h3 class="font-semibold mb-2">Fintech, reguleret + udledt af formål</h3>
            <p class="mb-2">
                De regulerede plus softwarevirksomheder (branchekode 62, 63.1 og 58.2), hvis registrerede formål i CVR indeholder et af ordene nedenfor.
                Også her tæller kun selskaber, der er aktive i CVR. Den udledte del er et skøn ud fra formålsteksten. Den kan have fejltræf,
                og den finder ikke fintech-selskaber, hvis formål ikke bruger ordene.
            </p>
            <div class="overflow-x-auto border border-sand-200 rounded-xl mb-3">
                <table class="w-full text-sm">
                    <thead class="bg-sand-50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Emne</th>
                            <th class="px-4 py-2 font-semibold">Ord i formålet, der tæller</th>
                        </tr>
                    </thead>
                    <tbody class="align-top">
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Betaling</td><td class="px-4 py-2">betalingsløsning, betalingstjeneste, betalingsformidling, betalingsplatform, betalingssystem, betalingsinfrastruktur, betalingskort, betalingsapp, betalinger, mobilbetaling, onlinebetaling, payment, payments</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Fintech</td><td class="px-4 py-2">fintech</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Finansiering</td><td class="px-4 py-2">finansieringsplatform, finansieringsløsning, finansieringstjeneste, finansieringssoftware</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Finanssektoren</td><td class="px-4 py-2">finansielle sektor, finanssektor, finansbranche, finansielle transaktioner, finansielle tjenester, ydelser, løsninger, beslutninger, services eller produkter</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Lån</td><td class="px-4 py-2">udlån, forbrugslån, billån, studielån, boliglån, låneplatform, låneformidling, lånetjeneste, lending, crowdlending</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Kredit</td><td class="px-4 py-2">kreditvurdering, kreditgivning, kreditformidling, kreditydelse, kreditscore</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Forsikring</td><td class="px-4 py-2">forsikringsløsning, forsikringstjeneste, forsikringsplatform, forsikringsformidling, forsikringsteknologi, forsikringssoftware, forsikringsbranche, insurtech</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Krypto</td><td class="px-4 py-2">kryptovaluta, kryptoaktiv, kryptomønt, cryptocurrency, crypto asset, bitcoin, blockchainbaserede betalinger</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Valuta</td><td class="px-4 py-2">valutahandel, valutaveksling</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Crowdfunding</td><td class="px-4 py-2">crowdfunding</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">E-penge</td><td class="px-4 py-2">e-penge, elektroniske penge</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Bank</td><td class="px-4 py-2">bankydelser, bankløsninger, bankteknologi, banksoftware, pengeinstitut</td></tr>
                        <tr class="border-t border-sand-200"><td class="px-4 py-2">Pension</td><td class="px-4 py-2">pensionsopsparing, pensionsløsning, pensionsselskab, pensionsordning, pensionsrådgivning</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mb-2">
                Store og små bogstaver er ligegyldige. De fleste ord tæller også som del af et længere ord, fx <em>mobilbetalingsløsning</em>. Undtagelserne er:
                <em>payment</em>, <em>payments</em> og <em>lending</em> tæller kun som hele ord.
                <em>fintech</em> tæller kun i begyndelsen af et ord, fx <em>fintechløsning</em>.
                <em>betalinger</em> tæller kun i slutningen af et ord, fx <em>kortbetalinger</em>.
                <em>blockchainbaserede betalinger</em> tæller også med bindestreg (<em>blockchain-baserede</em>), men ikke i ental (<em>blockchainbaseret betaling</em>).
            </p>
            <p class="mb-1">Nogle ord tæller bevidst ikke alene:</p>
            <ul class="list-disc pl-5 space-y-1">
                <li><em>finansiering</em>, <em>investering</em> og <em>finansiel virksomhed</em>, fordi de står i mange almindelige formål, fx "drive handel, industri og finansiel virksomhed".</li>
                <li><em>lån</em>, <em>krypto</em> og <em>pension</em>, fordi de også indgår i ord som "lån af værktøj", "kryptografi" og "pensionat".</li>
                <li><em>udbetalinger</em> og <em>afbetalinger</em> tæller ikke som "betalinger", fordi de typisk handler om løn- og regnskabsprogrammer.</li>
            </ul>
        </section>

        {{-- 7. Investortype --}}
        <section id="investortype" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">7. Investortype blandt ejerne</h2>
            <p class="mb-2">
                Filteret finder selskaber, hvor mindst én af de nuværende ejere i CVR er af den valgte type.
                Et selskab kan have flere investortyper.
            </p>
            <ul class="list-disc pl-5 space-y-1 mb-4">
                <li>CVR's ejerregister viser kun ejere med mindst 5 %. Mindre ejere indgår ikke. Interessenter i et interessentskab (I/S) står i CVR som ejere uanset deres andel og tæller derfor med.</li>
                <li>Kun selskaber, hvor vi kender ejerne, kan have en investortype. Når du vælger en investortype, viser siden, hvor mange af selskaberne i udvalget ejerne kendes for.</li>
                <li>Ejerandelen mangler for nogle ejerskaber i CVR. Hvor en regel kræver en andel under 50 %, tæller en ukendt andel ikke.</li>
            </ul>

            <h3 class="font-semibold mb-2">Personer: fire definitioner af business angel</h3>
            <p class="mb-2">
                Der findes ikke én officiel definition, så du kan vælge mellem fire. En person kan eje direkte eller gennem sit eget holdingselskab.
                Et <strong>personligt holdingselskab</strong> er et selskab med branchekode 64.2, 64.3 eller 64.99, hvor personen ejer mindst 50 %
                og sidder i direktionen eller er registreret som selskabets repræsentant. Personens egne holdingselskaber tæller ikke som investeringer.
            </p>
            <p class="mb-3">
                En <strong>stifter</strong> er en person (eller personens holdingselskab), der står som stifter af selskabet i CVR.
                CVR har stiftere fra omkring 1985. Selskaber uden registrerede stiftere indgår derfor ikke i definitionerne 1 og 2.
            </p>
            <ol class="list-decimal pl-5 space-y-3 mb-4">
                <li>
                    <strong>EIFO-definitionen</strong> (EIFO: <em>How business angels shape the Danish startup ecosystem</em>, juli 2026).
                    En person, der ejer direkte eller gennem sit personlige holdingselskab, og som
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li>ikke er stifter af selskabet,</li>
                        <li>ikke sidder i selskabets direktion og ikke er registreret som dets repræsentant,</li>
                        <li>ejer under 50 %,</li>
                        <li>og enten ejer gennem sit personlige holdingselskab eller sidder i selskabets bestyrelse.</li>
                    </ul>
                    <span class="block mt-1">
                        EIFO kræver også mindst én kapitalrunde på 1 mio. kr. Det led er ikke en del af investortype-filteret.
                        Du kan kombinere filteret med <em>Rejst kapital i alt</em> under kapitalfiltrene (afsnit 10). Vær opmærksom på, at det filter lægger alle
                        kendte forhøjelser sammen og ikke ser på den enkelte runde, at beløbet er en nedre grænse, og at kun selskaber med hentet kapitalhistorik tæller med.
                    </span>
                </li>
                <li><strong>Alle ikke-stiftere.</strong> En person, der ejer direkte eller gennem sit personlige holdingselskab, og som ikke er stifter af selskabet.</li>
                <li>
                    <strong>Ejer i mindst 3 selskaber.</strong> En person, der ejer andele i mindst 3 selskaber, direkte eller gennem sit personlige holdingselskab.
                    Kun ejerskaber, hvor CVR identificerer personen entydigt, tæller med. Så bliver personer med samme navn ikke lagt sammen.
                    Ejerskaber uden den identifikation tæller ikke. Tallet er derfor en nedre grænse og kan være for lavt.
                </li>
                <li><strong>Enhver person.</strong> Alle personer med en ejerandel, også stifterne.</li>
            </ol>

            <h3 class="font-semibold mb-2">Selskaber som ejere</h3>
            <p class="mb-2">
                Et ejerselskab får én type ud fra sin branchekode, sit navn, sin selskabsform og antallet af selskaber, det i dag er registreret ejer af.
                Reglerne prøves i denne rækkefølge:
            </p>
            <ol class="list-decimal pl-5 space-y-2 mb-3">
                <li><strong>Offentlig investor:</strong> branchekode 84 (offentlig forvaltning), eller navnet indeholder Vækstfonden, Eksport- og Investeringsfond, Styrelsen eller Innovationsfonden. Alle ejerandele tæller.</li>
                <li><strong>Erhvervsdrivende fond:</strong> branchekode 64.32, eller navnet indeholder ordet fond (også fonden, fonde, fonds og som slutning på et sammensat ord, fx iværksætterfond). Navnereglen gælder ikke kommanditselskaber. Alle ejerandele tæller.</li>
                <li><strong>Venturefond:</strong> et kommanditselskab (K/S), der ejer andele i mindst 2 selskaber, eller et selskab med branchekode 64.30, 64.99 eller 66.30, der ejer andele i mindst 2 selskaber. Fonden skal eje under 50 % af det selskab, der tælles. Ejer den flertallet, er den moderselskab og ikke investor.</li>
            </ol>
            <p class="mb-3">
                Andre selskabsejere, fx holdingselskaber og almindelige moderselskaber, kan ikke vælges som investortype, fordi reglerne for dem er for usikre.
            </p>

            <h3 class="font-semibold mb-2">Udenlandsk ejer</h3>
            <p>Selskabet har mindst én nuværende ejer uden dansk CVR-nummer, hvis adresse i CVR ligger uden for Danmark.</p>
        </section>

        {{-- 8. Stoerrelse og stiftelse --}}
        <section id="stoerrelse" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">8. Årsværk og stiftelsesdato</h2>
            <ul class="list-disc pl-5 space-y-2">
                <li><strong>Årsværk</strong> er det højeste antal årsværk, selskabet har haft i CVR's beskæftigelsestal, ikke det seneste. Et selskab, der har haft 40 årsværk og nu har 5, tæller som 40. Begge grænser er med.</li>
                <li><strong>Stiftet mellem</strong> bruger stiftelsesdatoen i CVR. Begge datoer er med. Er en dato kun udfyldt halvt, siger siden det. Tallet bruger så den seneste hele dato i feltet, eller ingen dato, hvis feltet ikke har haft en.</li>
            </ul>
        </section>

        {{-- 9. Kapitalhistorik --}}
        <section id="kapital" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">9. Kapitalhistorik</h2>
            <p class="mb-3">
                På siden for det enkelte selskab viser afsnittet <em>Kapitalhistorik</em> selskabets kapitalændringer.
                Kilden er CVR's historik for selskabskapitalen og de registreringstekster, der offentliggøres ved kapitalændringer,
                fx "kr. 3.852,41, indbetalt kontant, kurs 158.083,80".
            </p>
            <ul class="list-disc pl-5 space-y-2 mb-3">
                <li><strong>Kurs</strong> er prisen for 100 kr. nominel kapital.</li>
                <li><strong>Beløb</strong> = nominelt beløb × kurs / 100. Beløbet er indbetalt kapital, ikke omsætning.</li>
                <li><strong>Implied valuation</strong> (den værdi runden sætter på selskabet) = samlet kapital efter ændringen × kurs / 100. Den antager, at hele kapitalen er prissat til rundens kurs. Reelle vilkår som præferenceaktier og warrants kan afvige.</li>
                <li><strong>Rejst i alt</strong> er summen af kapitalforhøjelser med kendt beløb. Fondsforhøjelser tæller ikke, fordi der ikke kommer nye penge ind. Stiftelseskapitalen er heller ikke med.</li>
                <li><strong>Kapitalnedsættelse:</strong> beløbet er udbetalingen til ejerne.</li>
                <li><strong>Indbetalingsform</strong> er kontant, konvertering af gæld, apportindskud (værdier) eller fondsforhøjelse, som det står i registreringsteksten.</li>
                <li>
                    <strong>Ejerændringer samme dato</strong> er ændringer i ejerandele, som er registreret med samme dato som kapitalændringen.
                    Koblingen mellem en ejer og en runde er udledt af datoen. CVR siger ikke direkte, hvem der deltog i en runde.
                    Kun ejere i ejerregistret indgår, og ejere registreres i CVR fra 5 %.
                </li>
                <li>
                    <strong>Udenlandske ejere</strong> vises med land og enhedstype, fx <em>Sverige · selskab/fond</em>, både i ejergrafen og ved rundens ejerændringer,
                    hvor der står <em>Udenlandsk ejer</em> foran. Navnet vises som tekst uden link, fordi en udenlandsk ejer ikke har et dansk CVR-nummer.
                    Koblingen mellem den udenlandske ejer og runden er udledt af datoen på samme måde som for andre ejere.
                </li>
            </ul>
            <p>
                <strong>Omsætning.</strong> For virksomheder i regnskabsklasse B offentliggøres nettoomsætningen ikke (årsregnskabsloven § 138, stk. 6).
                De fleste små og nye selskaber er i klasse B. For dem er bruttofortjenesten typisk det tal, der findes i det offentlige regnskab.
            </p>
        </section>

        {{-- 10. Kapital (udviklingsstadie). Reglerne staar i registry-api
             CompanySegmentation::medKapital() og applyFilters(). --}}
        <section id="udviklingsstadie" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">10. Kapital (udviklingsstadie)</h2>
            <p class="mb-3">
                CVR registrerer ikke, hvilket udviklingsstadie et selskab er i. Filtrene under <em>Kapital (udviklingsstadie)</em> viser derfor kun det,
                der kan ses i registret: hvor mange gange selskabet har forhøjet sin kapital, hvor meget der er rejst, og hvornår den seneste forhøjelse var.
                Du kan også fordele selskaberne på antal kapitalforhøjelser.
            </p>
            <ul class="list-disc pl-5 space-y-2 mb-3">
                <li><strong>En kapitalforhøjelse</strong> er en registreret stigning i selskabskapitalen efter stiftelsen. Stiftelsen, kapitalnedsættelser og ændringer uden ny kapital tæller ikke.</li>
                <li><strong>Fondsforhøjelser tæller ikke</strong>, fordi der ikke kommer nye penge ind. Mangler registreringsteksten, kan vi ikke se, om det var en fondsforhøjelse, og så tæller forhøjelsen med.</li>
                <li><strong>Rejst kapital</strong> er summen af beløbene for kapitalforhøjelserne. Beløbet står i registreringsteksten, og den mangler for mange ældre forhøjelser. En forhøjelse uden beløb tæller som 0 kr. Rejst kapital er derfor en nedre grænse. Kun beløb i kroner lægges sammen. Selskaber med kapital i en anden valuta tæller med i antallet af forhøjelser, men ikke i rejst kapital.</li>
                <li><strong>Mindst og højst rejst kapital virker forskelligt.</strong> <em>Mindst</em> bruger den nedre grænse, så selskaber med manglende beløb kan være med. <em>Højst</em> tæller kun selskaber, hvor alle forhøjelser har et kendt beløb i kroner, fordi et manglende beløb kan skjule, at selskabet har rejst mere. Siden viser, hvor mange der er udeladt af den grund.</li>
                <li><strong>Intern kapital er med.</strong> Gældskonvertering, apportindskud og kapital fra et moderselskab tæller som rejst kapital på samme måde som kontante indskud. Vi skiller dem ikke ad endnu. Også store, etablerede selskaber kan have store forhøjelser.</li>
                <li><strong>Seneste kapitalforhøjelse</strong> er datoen for den seneste forhøjelse, der tæller. Begge datoer er med. Selskaber uden forhøjelser har ingen dato og er ikke med, når du bruger datofilteret.</li>
                <li><strong>Kun selskaber med hentet kapitalhistorik.</strong> Kapitalhistorikken hentes for iværksætterpopulationen, ikke for alle selskaber. Bruger du et kapitalfilter, tæller kun selskaber, hvor den er hentet, så et ukendt selskab ikke ser ud som et selskab uden forhøjelser. Siden viser, hvor mange selskaber i udvalget der er udeladt af den grund.</li>
                <li><strong>Fordel på kapitalforhøjelser</strong> giver grupperne <em>Ingen efter stiftelse</em>, <em>1</em>, <em>2 til 3</em>, <em>4 til 6</em> og <em>7 eller flere</em>. Selskaber uden hentet kapitalhistorik står på linjen <em>Kapitalhistorik ikke hentet</em>.</li>
            </ul>
        </section>

        {{-- 11. Koncernniveau. 🔑 Skal sige PRAECIS det samme som registry-api's
             App\Services\Cvr\Koncern. Aendres reglen der, aendres den her. --}}
        <section id="koncern" class="scroll-mt-20">
            <h2 class="text-lg font-semibold text-ink-800 mb-3">11. Selskaber og koncerner</h2>
            <p class="mb-3">
                Med <em>Tæl koncerner</em> tæller hvert tal antal koncerner i stedet for antal selskaber.
                Afgrænsningerne gælder stadig de enkelte selskaber. Et selskab, der er med i udvalget, tæller under sin koncern.
                Har flere selskaber i udvalget samme koncern, tæller koncernen én gang.
                Et selskab uden moderselskab er sin egen koncern.
            </p>
            <h3 class="font-semibold mb-2">Hvornår et selskab har et moderselskab</h3>
            <p class="mb-2">Et dansk selskab er moderselskab, når det i dag er registreret som ejer i CVR og</p>
            <ul class="list-disc pl-5 space-y-2 mb-3">
                <li>ejer mindst 66,67 % af selskabet, eller</li>
                <li>ejer 50 % og ingen anden ejer, hverken et selskab, en person eller en udenlandsk ejer, har en kendt andel på 50 % eller mere.</li>
            </ul>
            <p class="mb-3">
                CVR oplyser ejerandele i intervaller. "50 %" betyder mellem 50 % og 66,66 % og kan være præcis halvdelen.
                Derfor tæller en ejer med 50 % kun, når ingen anden ejer har lige så meget. Ejer to parter hver 50 %, har selskabet intet moderselskab. En ejer, hvis andel ikke står i CVR, regnes ikke med her.
                Opfylder flere danske selskaber reglen (typisk fordi et gammelt ejerskab ikke er afsluttet i CVR), bruges det selskab, hvis ejerskab senest er begyndt.
                Kan det ikke afgøres, får selskabet intet moderselskab.
            </p>
            <h3 class="font-semibold mb-2">Koncernens top</h3>
            <ul class="list-disc pl-5 space-y-2 mb-3">
                <li>Vi følger moderselskaberne opad, højst 10 led. Det øverste danske selskab er koncernens top.</li>
                <li>Er det øverste moderselskab udenlandsk eller en person, stopper kæden ved det øverste danske selskab.</li>
                <li>Ejer selskaberne hinanden i ring (A ejer B, og B ejer A), regnes de alle til samme koncern, også når ringen har mere end 10 led.</li>
            </ul>
            <p class="mb-3">
                <strong>Ikke med:</strong> kontrol gennem flere mindre ejerandele, fx to datterselskaber der hver ejer 40 % af et tredje selskab.
                Ejerandelene er intervaller, og en sum af dem kan ikke vise, om der er kontrol. Aftaler om stemmeret og bestemmende indflydelse
                uden ejerskab fremgår heller ikke af CVR.
            </p>
            <p>
                En koncern med selskaber i flere grupper (fx to kommuner) tæller med i hver gruppe. Grupperne kan derfor tilsammen give mere end totalen.
            </p>
        </section>

        <p class="text-xs text-ink-700/50 border-t border-sand-200 pt-4">
            Kilder: Det Centrale Virksomhedsregister (CVR), Danmarks Administrative Geografiske Inddeling (DAGI),
            EBA's register over betalings- og e-pengeinstitutter, ESMA's MiCA-register og det globale LEI-register (GLEIF).
            Spørgsmål til metoden kan sendes til support@frankston.io.
        </p>
    </div>
    @endif
</div>

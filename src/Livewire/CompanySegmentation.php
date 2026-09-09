<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use TheFountainhead\Metis\Services\QuotaExceededException;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Selskabssegmentering — "hvor mange selskaber af denne slags, fordelt hvordan?"
 *
 * 🔑 ET ANDET PRODUKT END OPSLAGET. Søgefeltet finder ét selskab man kender
 * navnet på. Det her afgrænser en POPULATION og tæller den, fordelt på
 * kommune, branche eller selskabsform.
 *
 * 🚨 SVARET VISER ALTID SIN TOTAL. `meta.total` er hele den filtrerede
 * population; listen er kun de `limit` største grupper. Uden totalen ville en
 * afkortet top-100 kunne læses som facit — samme fejlklasse som dækningen på
 * analyse-siden.
 *
 * 🪤 KODER ER IKKE NAVNE. Kommunekode "101" og selskabsform "aps" siger intet
 * til en læser; API'et sender `label: null` for begge (branchekoder har labels).
 * Derfor oversættes de her. En tabel med rå koder ville være teknisk korrekt
 * og praktisk ubrugelig.
 */
class CompanySegmentation extends Component
{
    /** Gruppering: municipality_code | industry_code | company_type */
    #[Url(as: 'grupper', except: 'municipality_code')]
    public $groupBy = 'municipality_code';

    #[Url(as: 'ivaerksaetter', except: false)]
    public bool $ivaerksaetter = false;

    #[Url(as: 'uden_holding', except: false)]
    public bool $excludeHolding = false;

    #[Url(as: 'branche', except: '')]
    public $industryPrefix = '';

    #[Url(as: 'kommune', except: '')]
    public $municipalityCode = '';

    #[Url(as: 'stiftet_fra', except: '')]
    public $foundedFrom = '';

    #[Url(as: 'stiftet_til', except: '')]
    public $foundedTo = '';

    /**
     * 🪤 OGSAA `#[Url]`. Uden det ville et delt link vise en BREDERE population
     * end den afsenderen saa, uden at modtageren kunne se det.
     */
    #[Url(as: 'aarsvaerk_min', except: null)]
    public $fteMin = null;

    #[Url(as: 'aarsvaerk_maks', except: null)]
    public $fteMax = null;

    /** @var array<int, array{key: string, label: ?string, count: int}> */
    public array $grupper = [];

    public ?int $total = null;

    public ?string $fejl = null;

    public bool $harSoegt = false;

    /**
     * 🪤 Kun de kommuner der faktisk optræder i toppen af resultaterne er
     * nødvendige, men en delvis liste ville vise navn for nogle rækker og kode
     * for andre. Hele listen, eller ingen.
     */
    public const KOMMUNER = [
        '101' => 'København', '147' => 'Frederiksberg', '151' => 'Ballerup',
        '153' => 'Brøndby', '155' => 'Dragør', '157' => 'Gentofte',
        '159' => 'Gladsaxe', '161' => 'Glostrup', '163' => 'Herlev',
        '165' => 'Albertslund', '167' => 'Hvidovre', '169' => 'Høje-Taastrup',
        '173' => 'Lyngby-Taarbæk', '175' => 'Rødovre', '183' => 'Ishøj',
        '185' => 'Tårnby', '187' => 'Vallensbæk', '190' => 'Furesø',
        '201' => 'Allerød', '210' => 'Fredensborg', '217' => 'Helsingør',
        '219' => 'Hillerød', '223' => 'Hørsholm', '230' => 'Rudersdal',
        '240' => 'Egedal', '250' => 'Frederikssund', '253' => 'Greve',
        '259' => 'Køge', '260' => 'Halsnæs', '265' => 'Roskilde',
        '269' => 'Solrød', '270' => 'Gribskov', '306' => 'Odsherred',
        '316' => 'Holbæk', '320' => 'Faxe', '326' => 'Kalundborg',
        '329' => 'Ringsted', '330' => 'Slagelse', '336' => 'Stevns',
        '340' => 'Sorø', '350' => 'Lejre', '360' => 'Lolland',
        '370' => 'Næstved', '376' => 'Guldborgsund', '390' => 'Vordingborg',
        '400' => 'Bornholm', '410' => 'Middelfart', '420' => 'Assens',
        '430' => 'Faaborg-Midtfyn', '440' => 'Kerteminde', '450' => 'Nyborg',
        '461' => 'Odense', '479' => 'Svendborg', '480' => 'Nordfyns',
        '482' => 'Langeland', '492' => 'Ærø', '510' => 'Haderslev',
        '530' => 'Billund', '540' => 'Sønderborg', '550' => 'Tønder',
        '561' => 'Esbjerg', '563' => 'Fanø', '573' => 'Varde',
        '575' => 'Vejen', '580' => 'Aabenraa', '607' => 'Fredericia',
        '615' => 'Horsens', '621' => 'Kolding', '630' => 'Vejle',
        '657' => 'Herning', '661' => 'Holstebro', '665' => 'Lemvig',
        '671' => 'Struer', '706' => 'Syddjurs', '707' => 'Norddjurs',
        '710' => 'Favrskov', '727' => 'Odder', '730' => 'Randers',
        '740' => 'Silkeborg', '741' => 'Samsø', '746' => 'Skanderborg',
        '751' => 'Aarhus', '756' => 'Ikast-Brande', '760' => 'Ringkøbing-Skjern',
        '766' => 'Hedensted', '773' => 'Morsø', '779' => 'Skive',
        '787' => 'Thisted', '791' => 'Viborg', '810' => 'Brønderslev',
        '813' => 'Frederikshavn', '820' => 'Vesthimmerlands', '825' => 'Læsø',
        '840' => 'Rebild', '846' => 'Mariagerfjord', '849' => 'Jammerbugt',
        '851' => 'Aalborg', '860' => 'Hjørring',
    ];

    public const SELSKABSFORMER = [
        'aps' => 'Anpartsselskab (ApS)',
        'as' => 'Aktieselskab (A/S)',
        'ivs' => 'Iværksætterselskab (IVS)',
        'pmv' => 'Personligt ejet mindre virksomhed',
        'enk' => 'Enkeltmandsvirksomhed',
        'ks' => 'Kommanditselskab (K/S)',
        'is' => 'Interessentskab (I/S)',
        'amba' => 'Andelsselskab (a.m.b.a.)',
        'fond' => 'Fond',
        'other' => 'Øvrige selskabsformer',
    ];

    /** 🔑 Samme loft i visning og eksport — ellers viser CSV'en en anden population. */
    public const GRUPPE_LOFT = 100;

    public const GRUPPERINGER = [
        'municipality_code' => 'Kommune',
        'industry_code' => 'Branche (DB07)',
        'company_type' => 'Selskabsform',
    ];

    public function mount(): void
    {
        $this->normaliser();
        $this->segmentér();
    }

    /**
     * 🚨 `#[Url]`-properties er BRUGERINPUT fra query-strengen og kan vaere hvad
     * som helst — ogsaa et array (`?kommune[]=a&kommune[]=b`). Med typede
     * properties gav det en TypeError, altsaa en 500-side, foer en linje af
     * vores egen logik naaede at koere. Derfor utypede properties + ét sted
     * hvor alt tvinges til den form resten af klassen regner med.
     */
    private function normaliser(): void
    {
        foreach (['groupBy', 'industryPrefix', 'municipalityCode', 'foundedFrom', 'foundedTo'] as $felt) {
            $this->{$felt} = is_scalar($this->{$felt}) ? (string) $this->{$felt} : '';
        }

        foreach (['fteMin', 'fteMax'] as $felt) {
            $this->{$felt} = is_numeric($this->{$felt}) ? (int) $this->{$felt} : null;
        }

        if (! array_key_exists($this->groupBy, self::GRUPPERINGER)) {
            $this->groupBy = 'municipality_code';
        }
    }

    /** Livewire-hook: kaldes automatisk naar $groupBy aendres. */
    public function updatedGroupBy(): void
    {
        $this->segmentér();
    }

    public function updatedIvaerksaetter(): void
    {
        $this->segmentér();
    }

    public function updatedExcludeHolding(): void
    {
        $this->segmentér();
    }

    public function nulstil(): void
    {
        $this->reset([
            'ivaerksaetter', 'excludeHolding', 'industryPrefix',
            'municipalityCode', 'foundedFrom', 'foundedTo', 'fteMin', 'fteMax',
        ]);
        $this->segmentér();
    }

    public function segmentér(): void
    {
        $this->fejl = null;

        $this->normaliser();

        try {
            $svar = app(RegistryApi::class)->segmentCompanies(
                $this->groupBy,
                $this->filtre(),
                self::GRUPPE_LOFT,
            );
        } catch (QuotaExceededException) {
            // 🚨 `client()` kaster FOER HTTP-kaldet naar den frie kvote er brugt,
            // og `postEnvelope()` fanger kun transportfejl. Uden dette ville
            // enhver besoegende med opbrugt kvote faa en 500 paa siden, fordi
            // `mount()` kalder herind ved hver page load.
            $this->harSoegt = true;
            $this->grupper = [];
            $this->total = null;
            $this->fejl = 'Du har brugt dine gratis opslag. Skriv til support@frankston.io for adgang.';

            return;
        }

        $this->harSoegt = true;

        // 🚨 ET SVAR VI IKKE FORSTAAR ER IKKE "DER ER INGEN DATA".
        // `postEnvelope()` returnerer 422-kroppen RAAT (uden `error`-noegle),
        // saa en ugyldig dato eller branchekode ville ellers falde igennem til
        // succes-grenen og blive vist som "ingen selskaber matcher" — en
        // paastand om populationen, hvor sandheden er at inputtet blev afvist.
        // Derfor kraeves `meta.total`: kun et svar der BAERER sin total er et
        // gyldigt resultat.
        if (isset($svar['error']) || ! isset($svar['meta']['total'])) {
            $this->grupper = [];
            $this->total = null;
            $this->fejl = match (true) {
                ($svar['error'] ?? null) === 'quota_exceeded' => 'Du har brugt dine gratis opslag. Skriv til support@frankston.io for adgang.',
                isset($svar['message']) => (string) $svar['message'],
                default => 'Segmenteringen kunne ikke hentes. Prøv igen, eller skriv til support@frankston.io.',
            };

            return;
        }

        // 🪤 `data` er IKKE garanteret et array. Uden dette tjek giver et
        // malformet 200-svar en TypeError inde i render — altsaa en 500 —
        // i stedet for en fejlbesked.
        $data = $svar['data'] ?? [];
        $this->grupper = is_array($data) ? $data : [];
        $this->total = (int) $svar['meta']['total'];
    }

    /** @return array<string, mixed> */
    public function filtre(): array
    {
        return array_filter([
            'ivaerksaetter' => $this->ivaerksaetter ?: null,
            'exclude_holding' => $this->excludeHolding ?: null,
            'industry_prefix' => $this->industryPrefix ?: null,
            'municipality_code' => $this->municipalityCode ?: null,
            'founded_from' => $this->foundedFrom ?: null,
            'founded_to' => $this->foundedTo ?: null,
            'fte_min' => $this->fteMin,
            'fte_max' => $this->fteMax,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Læsbar etiket for en gruppe.
     *
     * 🔑 API'ets egen `label` vinder når den findes (branchekoder har den).
     * Kommune og selskabsform kommer som rå nøgle og oversættes her.
     */
    public function etiket(array $gruppe): string
    {
        if (! empty($gruppe['label'])) {
            return $gruppe['label'];
        }

        $key = (string) ($gruppe['key'] ?? '');

        return match ($this->groupBy) {
            'municipality_code' => self::KOMMUNER[$key] ?? "Kommune $key",
            'company_type' => self::SELSKABSFORMER[$key] ?? mb_strtoupper($key),
            default => $key,
        };
    }

    /** Andel af den filtrerede population, som denne gruppe udgør. */
    public function andel(int $count): ?float
    {
        return $this->total > 0 ? round($count / $this->total * 100, 1) : null;
    }

    /**
     * Hent CSV'en. Linket er signeret og lever 60 sekunder, saa det hentes
     * naar brugeren klikker — ikke naar siden renderes.
     */
    public function hentCsv()
    {
        // 🪤 Eksportér ALDRIG filtre der ikke er vist. Har den seneste
        // segmentering fejlet, ville CSV'en beskrive en population brugeren
        // aldrig har set paa skaermen.
        if ($this->total === null) {
            $this->fejl = 'Hent et resultat frem først.';

            return null;
        }

        $url = app(RegistryApi::class)->segmentationExportLink($this->groupBy, $this->filtre(), self::GRUPPE_LOFT);

        // 🚨 REDIRECT ALDRIG BLINDT TIL EN URL FRA ET SVAR. Kompromitteres
        // eller MITM'es registry-api'et, ville brugeren blive sendt hvor som
        // helst hen. Vi redirecter kun til vores egen vaert.
        $base = rtrim((string) config('metis.registry_api.url'), '/');

        if ($url === null || $base === '' || ! str_starts_with($url, $base.'/')) {
            $this->fejl = 'Udtrækket kunne ikke dannes. Prøv igen, eller skriv til support@frankston.io.';

            return null;
        }

        return $this->redirect($url);
    }

    public function render()
    {
        $view = view('metis::livewire.company-segmentation')
            ->title('Selskabssegmentering — Metis');

        // 🚨 UDEN DETTE ER SIDEN 500 i standalone. Livewire falder tilbage paa
        // vaerts-appens default-layout (`components.layouts.app`), som ikke
        // findes i denne pakke. Alle 11 andre sidekomponenter goer det samme.
        //
        // 🪤 `Livewire::test()` renderer UDEN layout og kan derfor ALDRIG blive
        // roed af denne fejl. Det er derfor der ogsaa er en rute-smoketest.
        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}

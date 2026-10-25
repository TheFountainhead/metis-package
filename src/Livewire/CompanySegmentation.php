<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use TheFountainhead\Metis\Services\LookupAccess;
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

    /** DAGI-regionskode (1081-1085). Utypet af samme grund som de andre #[Url]-felter. */
    #[Url(as: 'region', except: '')]
    public $region = '';

    #[Url(as: 'vertikal', except: '')]
    public $vertikal = '';

    #[Url(as: 'investortype', except: '')]
    public $investortype = '';

    /**
     * Hvor mange af udvalget (uden investortype-filteret) ejerne kendes for.
     * Kun sat naar investortype er valgt. Se registry-api ejerdaekning().
     *
     * @var array{med: int, af: int}|null
     */
    public ?array $ejerdata = null;

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

    /**
     * 🔒 #[Locked]: tabellen er serverens svar. Ellers kunne en
     * /livewire/update-payload saette `grupper` og dermed aabne en vilkaarlig
     * gruppe i `visSelskaber()` (review af #200).
     *
     * @var array<int, array{key: string, label: ?string, count: int}>
     */
    #[Locked]
    public array $grupper = [];

    #[Locked]
    public ?int $total = null;

    /**
     * Selskaber i totalen UDEN grupperingsnoegle (ingen kommune, branche osv.).
     *
     * 🪤 Uden denne linje tav tabellen om dem: fordelt paa region uden filtre
     *    summede regionerne til 407.349 af 752.949 — 46 % var usynlige, mens
     *    totalen ovenfor talte dem med (ERST-test 23/10-2026).
     */
    #[Locked]
    public ?int $udenSegmentering = null;

    /**
     * Gruppering og filtre der FAKTISK gav tallene paa skaermen. Listen bruger
     * disse, ikke de aktuelle (klient-skrivbare) filterfelter: et `.blur`-felt
     * kan synce i samme request som klikket og ellers give en liste over en
     * anden population end tabellen (review af #200).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $vistFiltre = [];

    #[Locked]
    public string $vistGroupBy = 'municipality_code';

    public ?string $fejl = null;

    public bool $harSoegt = false;

    /**
     * Den gruppe brugeren har klikket paa, og selskaberne bag den.
     *
     * 🔑 Nulstilles ved HVER ny taelling (`segmentér()`): en liste fra
     * "Aarhus med ivaerksaetterfilter" maa aldrig blive staaende under en
     * tabel uden filteret.
     */
    #[Locked]
    public ?string $valgtGruppe = null;

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $selskaber = [];

    #[Locked]
    public ?int $selskabTotal = null;

    // 🪤 Egenskaben hedder `side`, metoden `gaaTilSide()`. Foerste udkast kaldte
    // begge `selskabSide`; Livewires $wire-proxy slaar state op FOER metoder,
    // saa wire:click="selskabSide(2)" ramte tallet, og knappen var doed i
    // browseren, mens Livewire::test()->call() var groen (review af #200).
    #[Locked]
    public int $side = 1;

    #[Locked]
    public int $sider = 1;

    #[Locked]
    public bool $listeAfkortet = false;

    #[Locked]
    public ?string $selskabFejl = null;

    public const SELSKABER_PR_SIDE = 50;

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
        // Grønlandske kommuner med selskaber i CVR. Navnene er CVR's egne
        // (beliggenhedsadresse.kommune.kommuneNavn), slaaet op 23/10-2026.
        '955' => 'Kujalleq (Grønland)', '956' => 'Sermersooq (Grønland)',
        '957' => 'Qeqqata (Grønland)', '959' => 'Qeqertalik (Grønland)',
        '960' => 'Avannaata (Grønland)',
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

    /** Aldersvinduet iværksætterfilteret foreslår, i år. */
    public const IVAERKSAETTER_AAR = 5;

    /**
     * Den dato filteret selv satte i "Stiftet fra", saa den kan ryddes igen.
     *
     * 🪤 Huskes i stedet for at sammenligne med dagens forslag: slaas filteret
     *    til 23:59 og fra 00:01, er forslaget flyttet en dag, og datoen ville
     *    blive staaende som et filter brugeren tror er slaaet fra (review #203).
     */
    #[Locked]
    public ?string $foreslaaetStiftetFra = null;

    /**
     * 🚨 SKAL staa SIDST blandt de offentlige properties. Livewire 3.8's
     * klient-diff sender hele komponenten under stien "" naar noeglernes
     * RAEKKEFOELGE afviger, saa en property indsat i midten gav 500 "Public
     * property [$] not found" paa faner der stod aabne hen over et deploy
     * (maalt i prod 23/10-2026). Nye properties tilfoejes derfor i bunden.
     */
    #[Url(as: 'fintech', except: '')]
    public $fintech = '';

    /**
     * Medtag selskaber der ikke laengere findes i CVR. registry-api udelader
     * dem som standard (maalt 23/10-2026: ~344.000 af 752.949 var ophoerte og
     * pustede totaler og andele op). Utypet af samme grund som de andre
     * #[Url]-felter; `normaliser()` tvinger den til bool.
     *
     * 🚨 SKAL staa SIDST blandt de offentlige properties (se `$fintech`).
     */
    #[Url(as: 'inkl_ophoerte', except: false)]
    public $inklOphoerte = false;

    /**
     * Kapital (udviklingsstadie), ERST krav 2. CVR har ingen stadier som
     * pre-seed eller seed; filtrene viser kun det der kan observeres: antal
     * kapitalforhoejelser efter stiftelse, rejst beloeb og seneste dato.
     * Utypede af samme grund som de andre #[Url]-felter.
     *
     * 🚨 SKAL staa SIDST blandt de offentlige properties (se `$fintech`).
     */
    #[Url(as: 'kapitalforhoejelser_min', except: null)]
    public $kapitalforhoejelserMin = null;

    #[Url(as: 'kapitalforhoejelser_maks', except: null)]
    public $kapitalforhoejelserMax = null;

    #[Url(as: 'rejst_min', except: null)]
    public $rejstMin = null;

    #[Url(as: 'rejst_maks', except: null)]
    public $rejstMax = null;

    #[Url(as: 'seneste_forhoejelse_fra', except: '')]
    public $senesteForhoejelseFra = '';

    #[Url(as: 'seneste_forhoejelse_til', except: '')]
    public $senesteForhoejelseTil = '';

    /**
     * Selskaber i udvalget (uden kapitalfiltrene) der er udeladt, fordi
     * kapitalhistorikken ikke er hentet. Kun sat med kapitalfilter.
     *
     * 🪤 Uden tallet laeses "40 selskaber har rejst over 5 mio." som en
     * paastand om hele udvalget (registry-api meta.uden_kapitalhistorik).
     */
    #[Locked]
    public ?int $udenKapitalhistorik = null;

    /**
     * Selskaber "Rejst kapital, maks" udelod, fordi mindst én forhoejelse har
     * ukendt beloeb eller anden valuta end kroner (registry-api
     * meta.uden_kendt_beloeb). Kun sat med rejst-maks.
     */
    #[Locked]
    public ?int $udenKendtBeloeb = null;

    /**
     * Linjen for selskaber uden grupperingsnoegle. Kun koder der ER koder
     * vises ved siden af navnet; region og vertikal har interne noegler.
     */
    public const UDEN_NOEGLE = [
        'municipality_code' => 'Uden kommune i CVR',
        'region' => 'Uden dansk region (ingen kommune i CVR, eller Grønland)',
        'industry_code' => 'Uden branchekode i CVR',
        'vertikal' => 'Uden branchekode i CVR',
        'company_type' => 'Uden selskabsform i CVR',
        'kapitalforhoejelser' => 'Kapitalhistorik ikke hentet',
    ];

    /** Grupperinger hvis noegle er en officiel kode, der giver mening at vise. */
    public const VIS_KODE = ['municipality_code', 'industry_code', 'company_type'];

    public const GRUPPERINGER = [
        'municipality_code' => 'Kommune',
        'region' => 'Region',
        'industry_code' => 'Branche (DB25)',
        'vertikal' => 'Vertikal',
        'company_type' => 'Selskabsform',
        'kapitalforhoejelser' => 'Kapitalforhøjelser',
    ];

    /**
     * Grupperne for kapitalforhoejelser. 🪤 Noeglerne SKAL matche registry-api's
     * CompanySegmentation::KAPITAL_ETIKETTER. API'et sender ogsaa selv navnet.
     */
    public const KAPITALGRUPPER = [
        '0' => 'Ingen efter stiftelse',
        '1' => '1',
        '2til3' => '2 til 3',
        '4til6' => '4 til 6',
        '7plus' => '7 eller flere',
    ];

    /**
     * Investortyper. Business angel kan vaelges efter fire definitioner, fordi
     * der ikke findes én officiel (Frederik 23/10-2026). EIFO-definitionen er
     * fra "How business angels shape the Danish startup ecosystem", juli 2026.
     *
     * 🪤 Noeglerne SKAL matche registry-api's InvestortypeFilter.
     */
    public const INVESTORTYPER = [
        'angel_eifo' => 'Business angel, EIFO-definitionen (uden kravet om runde på 1 mio. kr.)',
        'angel_ekstern' => 'Business angel, alle ikke-stiftere',
        'angel_flere' => 'Business angel, ejer i mindst 3 selskaber',
        'angel_person' => 'Business angel, enhver person',
        'venture' => 'Venturefond',
        'public' => 'Offentlig investor',
        'foundation' => 'Erhvervsdrivende fond',
        'udenlandsk' => 'Udenlandsk ejer',
    ];

    /** Uddybning vist under dropdownen for den valgte definition. */
    public const INVESTORTYPE_FORKLARING = [
        'angel_eifo' => 'Privatperson der ejer under 50 %, direkte eller gennem eget holdingselskab, hverken har stiftet eller driver selskabet, og sidder i bestyrelsen eller leder sit holdingselskab (EIFO, juli 2026). EIFO kræver også mindst én runde på 1 mio. kr.; det led indgår ikke endnu. Kun selskaber med registrerede stiftere (CVR fra ca. 1985).',
        'angel_ekstern' => 'Privatperson der ejer direkte eller gennem eget holdingselskab og ikke er registreret som stifter. Kun selskaber med registrerede stiftere (CVR fra ca. 1985).',
        // Fra registry-api #382: kun ejerskaber hvor CVR's deltager-id er personens eget.
        'angel_flere' => 'Privatperson der ejer andele i mindst 3 selskaber, direkte eller gennem eget holdingselskab. Kun ejerskaber, hvor CVR identificerer personen entydigt, tæller med, så personer med samme navn ikke lægges sammen. Ejerskaber uden den identifikation tæller ikke, så tallet kan være for lavt.',
        'angel_person' => 'Enhver privatperson med ejerandel, også stifterne.',
        'venture' => 'Venturefond der ejer under 50 % af selskabet.',
    ];

    /**
     * Fintech-afgraensning. 🪤 Noeglerne SKAL matche registry-api's
     * Rule::in(['reguleret', 'udledt']); alt andet giver 422.
     */
    public const FINTECH = [
        'reguleret' => 'Fintech, reguleret',
        'udledt' => 'Fintech, reguleret + udledt af formål',
    ];

    /** Uddybning vist under dropdownen for den valgte afgraensning. */
    public const FINTECH_FORKLARING = [
        'reguleret' => 'Selskaber med tilladelse som betalingsinstitut, e-pengeinstitut eller kontooplysningstjeneste (EBA\'s register) eller som udbyder af kryptoaktivtjenester (ESMA\'s MiCA-register), matchet på CVR. Gavekort- og brændstofkortordninger undtaget fra betalingsloven tæller ikke med. Crowdfunding indgår ikke endnu.',
        'udledt' => 'De regulerede plus softwarevirksomheder (branche 62, 63.1 og 58.2), hvis registrerede formål i CVR nævner fx betaling, finansiering, lån, forsikring eller kryptovaluta. Den udledte del er et skøn ud fra formålsteksten og kan have fejltræf.',
    ];

    /** Koderne datalaget accepterer (DAGI). Visningsnavnet kommer med i svaret. */
    public const REGIONER = [
        '1084' => 'Region Hovedstaden',
        '1085' => 'Region Sjælland',
        '1083' => 'Region Syddanmark',
        '1082' => 'Region Midtjylland',
        '1081' => 'Region Nordjylland',
    ];

    /**
     * Vertikalerne datalaget kender, afledt af branchekoden.
     *
     * 🪤 Noeglerne SKAL matche registry-api's VerticalMapper. En noegle her som
     * datalaget ikke kender giver 422, og siden viser fejlen i stedet for tal.
     */
    public const VERTIKALER = [
        'software' => 'Software og IT',
        'life-science' => 'Life science',
        'sundhed' => 'Sundhed',
        'forskning-udvikling' => 'Forskning og udvikling',
        'hardware' => 'Hardware og elektronik',
        'telekom' => 'Telekommunikation',
        'energi' => 'Energi',
        'miljoe' => 'Miljø og forsyning',
        'industri' => 'Industri',
        'kemi' => 'Kemi',
        'foedevarer' => 'Fødevarer',
        'landbrug' => 'Landbrug og fiskeri',
        'raastoffer' => 'Råstoffer',
        'finans' => 'Finans',
        'engroshandel' => 'Engroshandel',
        'detailhandel' => 'Detailhandel',
        'transport-logistik' => 'Transport og logistik',
        'medier' => 'Medier og udgivelse',
        'marketing' => 'Marketing og reklame',
        'raadgivning' => 'Rådgivning',
        'hr' => 'HR og rekruttering',
        'rejser' => 'Rejser',
        'uddannelse' => 'Uddannelse',
        'ejendom' => 'Fast ejendom',
        'byggeri' => 'Byggeri',
        'hotel-restauration' => 'Hotel og restauration',
        'udlejning-leasing' => 'Udlejning og leasing',
        'sikkerhed' => 'Sikkerhed',
        'facility' => 'Facility services',
        'forretningsservice' => 'Forretningsservice',
        'sport-fritid' => 'Sport og fritid',
        'foreninger' => 'Foreninger',
        'oevrige' => 'Øvrige brancher',
    ];

    public function mount(): void
    {
        $this->normaliser();
        $this->segmentér();
    }

    /**
     * Kun for bekraeftede piloter (licensbrugere), samme praedikat som
     * `RegistryApi::pilotFejl()`. Er gaten slaaet fra (embedded bag vaertens
     * egen login), er adgangen vaertens ansvar.
     *
     * 🪤 Siden viser bare beskeden; det er datalaget der afviser. Gaten her
     * sparer kun et kald og giver den rigtige besked (lektionen fra #185).
     */
    public function harAdgang(): bool
    {
        return app(LookupAccess::class)->harPilotAdgang();
    }

    /**
     * Bekraefter en pilot sin arbejdsmail paa selve siden, skal resultatet
     * komme frem uden genindlaesning (samme blindgyde som DebtSearch).
     */
    #[On('email-verified')]
    public function onEmailVerified(): void
    {
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
        foreach (['groupBy', 'industryPrefix', 'municipalityCode', 'region', 'vertikal', 'investortype', 'fintech', 'foundedFrom', 'foundedTo', 'senesteForhoejelseFra', 'senesteForhoejelseTil'] as $felt) {
            $this->{$felt} = is_scalar($this->{$felt}) ? (string) $this->{$felt} : '';
        }

        foreach (['fteMin', 'fteMax'] as $felt) {
            $this->{$felt} = is_numeric($this->{$felt}) ? (int) $this->{$felt} : null;
        }

        // Et negativt antal eller beloeb giver 422 fra datalaget; det falder
        // bort i stedet for at vaelte siden.
        foreach (['kapitalforhoejelserMin', 'kapitalforhoejelserMax', 'rejstMin', 'rejstMax'] as $felt) {
            $this->{$felt} = is_numeric($this->{$felt}) && $this->{$felt} >= 0 ? (int) $this->{$felt} : null;
        }

        if (! array_key_exists($this->groupBy, self::GRUPPERINGER)) {
            $this->groupBy = 'municipality_code';
        }

        // En ukendt region eller vertikal fra URL'en ville give 422 fra
        // datalaget. Den falder bort i stedet for at vaelte siden.
        if (! array_key_exists($this->region, self::REGIONER)) {
            $this->region = '';
        }

        if (! array_key_exists($this->vertikal, self::VERTIKALER)) {
            $this->vertikal = '';
        }

        if (! array_key_exists($this->investortype, self::INVESTORTYPER)) {
            $this->investortype = '';
        }

        if (! array_key_exists($this->fintech, self::FINTECH)) {
            $this->fintech = '';
        }

        $this->inklOphoerte = is_scalar($this->inklOphoerte)
            && filter_var($this->inklOphoerte, FILTER_VALIDATE_BOOLEAN);
    }

    /** Livewire-hook: kaldes automatisk naar $groupBy aendres. */
    public function updatedGroupBy(): void
    {
        $this->segmentér();
    }

    /**
     * Iværksætterfilteret i API'et ser kun på årsværk, selskabsform og
     * branche, ikke på alder. Derfor foreslås aldersvinduet her, i det felt
     * brugeren allerede kan se og rette, i stedet for at blive gemt i API'et.
     *
     * 🔑 Et felt brugeren selv har udfyldt, overskrives ikke. Slås filteret
     * fra, ryddes kun den dato vi selv satte; en anden dato er brugerens.
     */
    public function updatedIvaerksaetter(): void
    {
        if ($this->ivaerksaetter && $this->foundedFrom === '') {
            $this->foundedFrom = $this->foreslaaetStiftetFra = self::ivaerksaetterStiftetFra();
        } elseif (! $this->ivaerksaetter) {
            if ($this->foreslaaetStiftetFra !== null && $this->foundedFrom === $this->foreslaaetStiftetFra) {
                $this->foundedFrom = '';
            }
            $this->foreslaaetStiftetFra = null;
        }

        $this->segmentér();
    }

    /**
     * 🪤 `subYearsNoOverflow`: fra 29/2 giver `subYears` 1/3 fem år før,
     * fordi skæringsåret ikke har en 29/2. Vinduet ville miste en dag.
     *
     * 🪤 Dansk dato: appen koerer i UTC, saa mellem 22 og 24 dansk tid ville
     * forslaget ligge en dag for tidligt (review #203).
     */
    private static function ivaerksaetterStiftetFra(): string
    {
        return now('Europe/Copenhagen')->subYearsNoOverflow(self::IVAERKSAETTER_AAR)->toDateString();
    }

    public function updatedExcludeHolding(): void
    {
        $this->segmentér();
    }

    /**
     * Eget hook som de andre afkrydsningsfelter: FILTERFELTER er listen over
     * `wire:model.change`-felter (vaernet i CompanySegmentationFilterfeltTest).
     */
    public function updatedInklOphoerte(): void
    {
        $this->segmentér();
    }

    /**
     * Felterne der regner, naar de faar en ny vaerdi.
     *
     * 🚨 Foer 13/10-2026 var de bundet med `wire:model.blur` + et separat
     * `wire:change="segmentér"`. Kom "change" foer "blur" (en dato tastet ind,
     * Enter i et tekstfelt, piletaster i aarsvaerk), regnede serveren paa den
     * GAMLE vaerdi, og den nye kom bagefter uden genberegning: tallet haltede
     * én aendring bagefter, mens URL'en viste den nye. Nu sendes vaerdien med
     * `wire:model.change`, og komponenten regner selv her.
     */
    private const FILTERFELTER = ['industryPrefix', 'municipalityCode', 'region', 'vertikal', 'investortype', 'fintech', 'foundedFrom', 'foundedTo', 'fteMin', 'fteMax',
        'kapitalforhoejelserMin', 'kapitalforhoejelserMax', 'rejstMin', 'rejstMax', 'senesteForhoejelseFra', 'senesteForhoejelseTil'];

    public function updated(string $felt): void
    {
        if (in_array($felt, self::FILTERFELTER, true)) {
            $this->segmentér();
        }
    }

    public function nulstil(): void
    {
        $this->reset([
            'ivaerksaetter', 'excludeHolding', 'industryPrefix',
            'municipalityCode', 'region', 'vertikal', 'investortype', 'fintech', 'foundedFrom', 'foundedTo', 'fteMin', 'fteMax',
            'inklOphoerte', 'foreslaaetStiftetFra',
            'kapitalforhoejelserMin', 'kapitalforhoejelserMax', 'rejstMin', 'rejstMax', 'senesteForhoejelseFra', 'senesteForhoejelseTil',
        ]);
        $this->segmentér();
    }

    public function segmentér(): void
    {
        // FOER adgangstjekket: mister sessionen pilot-adgang, maa en aaben
        // liste ikke blive liggende i snapshottet (review af #200).
        $this->lukSelskaber();

        if (! $this->harAdgang()) {
            return;
        }

        $this->fejl = null;

        $this->normaliser();

        // 🔑 Ingen QuotaExceededException-catch laengere: kun bekraeftede
        // piloter (kvote-fritaget) og sessioner med gaten slaaet fra (ingen
        // kvote) naar hertil, saa `kraevKvote()` kan ikke kaste. Et svar fra
        // datalaget med `pilot_required` haandteres nedenfor.
        $svar = app(RegistryApi::class)->segmentCompanies(
            $this->groupBy,
            $this->filtre(),
            self::GRUPPE_LOFT,
        );

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
            $this->udenSegmentering = null;
            $this->ejerdata = null;
            $this->udenKapitalhistorik = null;
            $this->udenKendtBeloeb = null;
            $this->fejl = match (true) {
                ($svar['error'] ?? null) === 'pilot_required' => 'Segmenteringen kræver en pilotkonto. Skriv til support@frankston.io for adgang.',
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
        $this->udenSegmentering = isset($svar['meta']['uden_segmentering'])
            ? (int) $svar['meta']['uden_segmentering']
            : null;
        $ejerdata = $svar['meta']['ejerdata'] ?? null;
        $this->ejerdata = is_array($ejerdata) && isset($ejerdata['med'], $ejerdata['af'])
            ? ['med' => (int) $ejerdata['med'], 'af' => (int) $ejerdata['af']]
            : null;
        $this->udenKapitalhistorik = isset($svar['meta']['uden_kapitalhistorik'])
            ? (int) $svar['meta']['uden_kapitalhistorik']
            : null;
        $this->udenKendtBeloeb = isset($svar['meta']['uden_kendt_beloeb'])
            ? (int) $svar['meta']['uden_kendt_beloeb']
            : null;
        $this->vistGroupBy = $this->groupBy;
        $this->vistFiltre = $this->filtre();
    }

    /** @return array<string, mixed> */
    public function filtre(): array
    {
        return array_filter([
            'ivaerksaetter' => $this->ivaerksaetter ?: null,
            'exclude_holding' => $this->excludeHolding ?: null,
            'industry_prefix' => $this->industryPrefix ?: null,
            'municipality_code' => $this->municipalityCode ?: null,
            'region' => $this->region ?: null,
            'vertikal' => $this->vertikal ?: null,
            'investortype' => $this->investortype ?: null,
            'fintech' => $this->fintech ?: null,
            'founded_from' => $this->foundedFrom ?: null,
            'founded_to' => $this->foundedTo ?: null,
            'fte_min' => $this->fteMin,
            'fte_max' => $this->fteMax,
            'inkl_ophoerte' => $this->inklOphoerte ?: null,
            'kapitalforhoejelser_min' => $this->kapitalforhoejelserMin,
            'kapitalforhoejelser_max' => $this->kapitalforhoejelserMax,
            'rejst_min' => $this->rejstMin,
            'rejst_max' => $this->rejstMax,
            'seneste_forhoejelse_fra' => $this->senesteForhoejelseFra ?: null,
            'seneste_forhoejelse_til' => $this->senesteForhoejelseTil ?: null,
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
            'region' => self::REGIONER[$key] ?? $key,
            'vertikal' => self::VERTIKALER[$key] ?? $key,
            'kapitalforhoejelser' => self::KAPITALGRUPPER[$key] ?? $key,
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
        if (! $this->harAdgang()) {
            return null;
        }

        // 🪤 Eksportér ALDRIG filtre der ikke er vist. Har den seneste
        // segmentering fejlet, ville CSV'en beskrive en population brugeren
        // aldrig har set paa skaermen.
        if ($this->total === null) {
            $this->fejl = 'Hent et resultat frem først.';

            return null;
        }

        // 🔑 `vistGroupBy`/`vistFiltre`, ikke de aktuelle felter: filen skal
        // beskrive tallene paa skaermen, og taellings- og liste-CSV'en skal
        // altid beskrive SAMME population (review af #215).
        $url = app(RegistryApi::class)->segmentationExportLink($this->vistGroupBy, $this->vistFiltre, self::GRUPPE_LOFT);

        if (! $this->erVoresUrl($url)) {
            $this->fejl = 'Udtrækket kunne ikke dannes. Prøv igen, eller skriv til support@frankston.io.';

            return null;
        }

        return $this->redirect($url);
    }

    /**
     * Hele det viste udvalg som selskabsliste (CSV), ikke kun én gruppe.
     * Hoejst 10.000 selskaber (flest aarsvaerk foerst); er udvalget stoerre,
     * staar det i filen.
     *
     * 🔑 `vistGroupBy`/`vistFiltre`, ikke de aktuelle felter: filen skal
     * beskrive den population tabellen viser, samme regel som listen.
     */
    public function hentUdvalgCsv()
    {
        if (! $this->harAdgang()) {
            return null;
        }

        if ($this->total === null) {
            $this->fejl = 'Hent et resultat frem først.';

            return null;
        }

        $url = app(RegistryApi::class)->segmentationCompaniesExportLink($this->vistGroupBy, null, $this->vistFiltre);

        if (! $this->erVoresUrl($url)) {
            $this->fejl = 'Udtrækket kunne ikke dannes. Prøv igen, eller skriv til support@frankston.io.';

            return null;
        }

        return $this->redirect($url);
    }

    /**
     * Selskaberne bag den aabne gruppe som CSV ("hvilke er de 6.924 i
     * Aarhus?"). Samme gruppe og filtre som listen paa skaermen.
     */
    public function hentSelskaberCsv()
    {
        if (! $this->harAdgang() || $this->valgtGruppe === null) {
            return null;
        }

        $url = app(RegistryApi::class)->segmentationCompaniesExportLink($this->vistGroupBy, $this->valgtGruppe, $this->vistFiltre);

        if (! $this->erVoresUrl($url)) {
            $this->selskabFejl = 'Udtrækket kunne ikke dannes. Prøv igen, eller skriv til support@frankston.io.';

            return null;
        }

        return $this->redirect($url);
    }

    /**
     * 🚨 REDIRECT ALDRIG BLINDT TIL EN URL FRA ET SVAR. Kompromitteres eller
     * MITM'es registry-api'et, ville brugeren blive sendt hvor som helst hen.
     * Vi redirecter kun til vores egen vaert.
     */
    private function erVoresUrl(?string $url): bool
    {
        $base = rtrim((string) config('metis.registry_api.url'), '/');

        return $url !== null && $base !== '' && str_starts_with($url, $base.'/');
    }

    public function visSelskaber(string $noegle): void
    {
        if (! $this->harAdgang()) {
            return;
        }

        // Kun en gruppe der STAAR i den viste tabel kan aabnes (`grupper` er
        // #[Locked], saa listen kan ikke saettes udefra).
        if ($noegle === '' || ! collect($this->grupper)->contains(fn ($g) => (string) ($g['key'] ?? '') === $noegle)) {
            return;
        }

        $this->valgtGruppe = $noegle;
        $this->side = 1;
        $this->hentSelskaber();
    }

    public function gaaTilSide(int $side): void
    {
        if ($this->valgtGruppe === null) {
            return;
        }

        $this->side = max(1, min($side, $this->sider));
        $this->hentSelskaber();
    }

    public function lukSelskaber(): void
    {
        $this->reset(['valgtGruppe', 'selskaber', 'selskabTotal', 'side', 'sider', 'listeAfkortet', 'selskabFejl']);
    }

    protected function hentSelskaber(): void
    {
        if (! $this->harAdgang() || $this->valgtGruppe === null) {
            return;
        }

        $this->selskabFejl = null;

        $svar = app(RegistryApi::class)->segmentationCompanies(
            $this->vistGroupBy,
            $this->valgtGruppe,
            $this->vistFiltre,
            $this->side,
            self::SELSKABER_PR_SIDE,
        );

        // 🚨 Samme regel som `segmentér()`: kun et svar der BAERER sin total
        // er et resultat. Ellers en fejlbesked, ikke en tom liste.
        if (isset($svar['error']) || ! isset($svar['meta']['total']) || ! is_array($svar['data'] ?? null)) {
            $this->selskaber = [];
            $this->selskabTotal = null;
            $this->selskabFejl = match (true) {
                ($svar['error'] ?? null) === 'pilot_required' => 'Listen kræver en pilotkonto.',
                ($svar['error'] ?? null) === 'daily_quota_exceeded' => 'Dagens kvote for selskabslister er brugt. Prøv igen i morgen, eller skriv til support@frankston.io.',
                isset($svar['message']) => (string) $svar['message'],
                default => 'Selskaberne kunne ikke hentes. Prøv igen, eller skriv til support@frankston.io.',
            };

            return;
        }

        $total = (int) $svar['meta']['total'];

        $this->selskaber = array_values(array_filter($svar['data'], 'is_array'));
        $this->selskabTotal = $total;
        // Falder tilbage paa totalen, saa en manglende last_page aldrig skjuler
        // alle sider efter den foerste.
        $this->sider = max(1, (int) ($svar['meta']['last_page'] ?? ceil($total / self::SELSKABER_PR_SIDE)));
        $this->listeAfkortet = (bool) ($svar['meta']['capped'] ?? false);
    }

    /** En dato fra API'et som dd.mm.åååå; et ulaeseligt felt giver tom tekst, ikke 500. */
    public function dato(mixed $vaerdi): string
    {
        if (! is_string($vaerdi) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $vaerdi)) {
            return '';
        }

        return substr($vaerdi, 8, 2).'.'.substr($vaerdi, 5, 2).'.'.substr($vaerdi, 0, 4);
    }

    /** Navnet paa den valgte gruppe, som i tabellen. */
    public function valgtEtiket(): string
    {
        $gruppe = collect($this->grupper)->first(fn ($g) => (string) ($g['key'] ?? '') === $this->valgtGruppe);

        return $gruppe ? $this->etiket($gruppe) : (string) $this->valgtGruppe;
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

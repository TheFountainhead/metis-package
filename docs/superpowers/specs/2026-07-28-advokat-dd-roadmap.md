# Advokat-DD-pakken: roadmap (program-niveau)

**Dato:** 2026-07-28 · **Status:** roadmap godkendt-afventende — hvert spor kræver egen spec/plan før byggeri · **Kontekst:** markedsføringsstrategi 28/7 — advokater er segment 2 (efter långivere); dette er produkt-gabet der skal lukkes før segmentet åbnes.

## Udgangspunkt (hvad advokater allerede kan i Metis)

Ejerskabskortlægning (relationsgrafen — reelt stærk til DD), tinglyst gæld/pant-oversigt, selskabs- og ejendomsdata, handler/vurderinger, PDF-udskrift pr. opslag. Manglerne er tre spor:

## Spor 1: Fulde tinglysningsakter (skøder, servitutter, byrder, deklarationer)

**Gate:** e-TL-systemadgang afventer test-certifikater fra Netcompany (sag #105-tråden; ⏰ ryk ~5/8, allerede kalendersat).

**Forarbejde der KAN ske nu (er delvist sket):** registry-api PR **#105 er en ÅBEN e-TL SOAP-spike** — XMLDSig-signering, pantebrev-payload, svarservice-callbacks, ProeveTinglysning. Fundamentet er altså bygget. Resterende forberedelse uden certifikater:
- Dokumentmodel: akt-typer (skøde/servitut/pant/deklaration), lagring, versionering
- 🚨 **PII-design FØR første akt hentes:** akter indeholder CPR-numre og persondata i fritekst — adgangsstyring (kun verificerede/pilot-brugere?), Flare-scrubbing (CPR-censoren fra 27/7 dækker exceptions, men akt-INDHOLD må aldrig i logs), retention-politik. Dette er en bindende forudsætning, ikke et efterarbejde.
- UI-design: dokument-fane på ejendomssiden + akt-links fra pant-oversigten

**Når certifikaterne lander:** testmiljø-integration (ProeveTinglysning) → akt-hentning pr. BFE → visning/download i Metis. Estimat: 2-3 ugers byggeri efter cert-modtagelse; forberedelsen ~1 uge og kan lægges før.

## Spor 2: Plandata/lokalplaner (kan starte NU)

**Datakilde:** Plandata.dk (åben — WFS/REST). Ingen certifikater, ingen aftaler.
- registry-api: nyt endpoint — lokalplaner pr. BFE/geometri (plan-id, navn, status [forslag/vedtaget], anvendelseskategori, link til plandokument-PDF)
- Metis: ny sektion "Plangrundlag" på adresse-/ejendomssiden; senere plan-status som signal i grafens ejendomskort
- Estimat: 1-2 uger. Mindst risikable spor — og lukker et synligt Resights-paritetspunkt.

## Spor 3: Sags-workflow (kan starte nu; kræver produktbeslutning FØRST)

**Beslutningspunkt (Frederik):** sager kræver identitet. Nuværende model er verificeret-email + pilot-tokens — rækker den til "mine sager" (session/email-bundet), eller er det anledningen til rigtige konti? Deling med kolleger kræver reelt konti/organisationer. **Anbefaling: MVP på verificeret-email (sager knyttet til email), deling udskydes** — så blokerer kontospørgsmålet ikke MVP'en.

**MVP-scope:** "Sag" = navngiven samling af opslag (ejendomme/selskaber/personer) · "Tilføj til sag"-knap på lookup-sider · sagsside med samlet oversigt · samlet DD-rapport-PDF med tidsstempler (udvidelse af eksisterende PdfController). **Senere:** deling/invitationer, audit-spor, akt-vedhæftning (kobler til spor 1).

Estimat MVP: 2-3 uger.

## Sekvens og afhængigheder

1. **Nu:** Spor 2 (plandata) startes — hurtig, synlig værdi. Spor 3's beslutningspunkt forelægges Frederik; MVP kan bygges parallelt med spor 2.
2. **~5/8:** Netcompany-ryk på certifikater; spor 1-forberedelsen (dokumentmodel + PII-design) lægges umiddelbart før forventet modtagelse.
3. **Certs modtaget:** spor 1-integration (2-3 uger).
4. **Advokat-segmentet åbnes markedsføringsmæssigt** når spor 1 + spor 3-MVP er live (spor 2 er nice-to-have for lancering, must-have for paritet).

Hvert spor kører egen brainstorm→spec→plan→SDD-runde ved start (dette dokument er roadmap, ikke spec).

## Non-goals (for hele pakken)

Udbud/byggeprojekter (Resights' bane), CAD/arkitekt-flader, nabobreve, kort-lag ud over plangrundlag.

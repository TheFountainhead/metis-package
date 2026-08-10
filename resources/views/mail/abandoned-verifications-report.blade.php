Disse bad om en kode til Metis, men gennemførte ikke verifikationen (sidste {{ $timer }} timer):

@foreach($frafaldne as $f)
{{ $f->email }} — {{ $f->forsoeg }} {{ $f->forsoeg == 1 ? 'forsøg' : 'forsøg' }}, sidst {{ \Carbon\Carbon::parse($f->sidste)->format('d. M H:i') }}
@endforeach

De har set gate-dialogen og indtastet deres arbejdsmail, men aldrig indtastet koden.
Mulige årsager: koden landede i spam, de skiftede enhed, eller de mistede interessen.

Vil du række ud, er mailadressen bekræftet gyldig så langt som at den kunne modtage.

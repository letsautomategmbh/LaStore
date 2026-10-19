@extends('layouts.app')

{{-- Im Reiter des Browsers nur der Name: ein Sinnbild kann er nicht, und
     eine Zahl im Reiter liest niemand. --}}
@section('title', __('LaStore'))

@php
    /* Einmal filtern, zweimal benutzen: die Kacheln unten und die Zahl in der
       Seitenleiste. Stünde die Bedingung an beiden Stellen, verspräche die
       Klammer eines Tages eine Kachel, die es nicht gibt. */
    $sichtbar = \Modules\LaStore\Support\InstalledModules::sichtbar($inventory);
@endphp

{{-- Die Seitenleiste der Modulverwaltung, damit der Sprung von dort hierher
     den Zusammenhang behält: wer aus "Modules" kommt, findet den Weg zurück
     an derselben Stelle. --}}
@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('lastore::partials.sidebar_menu', ['eigeneAnzahl' => count($sichtbar)])
@endsection

@section('content')

    {{-- Der Hinweis auf eine neue Fassung von LaStore selbst.

         Er erscheint von SELBST, sobald etwas bereitliegt -- das ist der ganze
         Sinn. Ein Weg, den man suchen muss, wird einmal gegangen und dann nie
         wieder; ein Modul, das nie aktualisiert wird, bekommt keine
         Sicherheitskorrektur und lehnt nach einem Schluesselwechsel jedes
         Paket ab.

         Kein eigener Netzaufruf hier: der Controller hat den Befund
         hinterlegt, die Ansicht liest ihn nur. --}}
    @if (!empty($selbstNeu))
        <div class="alert alert-info">
            <strong>{{ __('Für LaStore liegt Fassung :v bereit.', ['v' => $selbstNeu]) }}</strong>
            <form method="POST" action="{{ route('lastore.self_update') }}" class="form-inline" style="display:inline-block;margin-left:10px">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-primary btn-sm">{{ __('Jetzt aktualisieren') }}</button>
            </form>
            {{-- Was sich aendert, nicht nur DASS sich etwas aendert.

                 Aufklappbar und nicht offen: der Hinweis steht ueber allem
                 anderen auf der Seite, und eine Notiz von zehn Zeilen waere
                 dort eine Wand. Wer wissen will, was kommt, klickt einmal.

                 Die Notiz kommt aus derselben Antwort wie die Fassung und
                 liegt neben ihr in einer Option -- kein zweiter Netzaufruf
                 beim Zeichnen der Seite. --}}
            @if (!empty($selbstNotiz))
                <details style="margin-top:6px">
                    <summary style="cursor:pointer"><small>{{ __('Was sich ändert') }}</small></summary>
                    <div class="text-muted" style="margin-top:4px;max-width:68em">
                        <small>{!! nl2br(e($selbstNotiz)) !!}</small>
                    </div>
                </details>
            @endif

            <div class="text-muted" style="margin-top:6px">
                <small>{{ __('Das Paket wird geprüft, bevor es ausgepackt wird: erst die Signatur, dann die Prüfsumme. Der bisherige Stand bleibt als Sicherung liegen.') }}</small>
            </div>
        </div>
    @endif

    {{-- Der Umzug auf die neuen Modulnamen (Bexio -> LaBexio).

         Ein Knopf für alle: die Module rufen sich gegenseitig über ihre
         internen Namen auf, und darum ziehen sie zusammen um oder gar
         nicht. Mit Autopilot geschieht es nachts von selbst
         (lastore:update ruft lastore:umzug zuerst). Siehe Support\Umzug. --}}
    @if (!empty($umzug))
        <div class="alert alert-info">
            <strong>{{ __('Neue interne Namen für :n Module', ['n' => count($umzug)]) }}</strong>
            <form method="POST" action="{{ route('lastore.umzug') }}" class="form-inline" style="display:inline-block;margin-left:10px"
                  data-lastore-bestaetigen="{{ __('Jetzt alle :n Module auf ihre neuen internen Namen umziehen?', ['n' => count($umzug)]) }}">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-primary btn-sm">{{ __('Jetzt umziehen') }}</button>
            </form>
            <div class="text-muted" style="margin-top:6px;max-width:68em">
                <small>
                    {{ __('Unsere Module tragen intern neu das Präfix «La», damit sie nie mit Modulen anderer Anbieter zusammenstossen. Was Sie sehen, bleibt gleich: Namen, Menüs, Adressen und Daten. Die Module ziehen gemeinsam um, und die bisherige Fassung bleibt eine Woche als Sicherung liegen.') }}
                </small>
                <br><small>{{ implode(' · ', array_map(function ($k) { return $k['name'].' → '.$k['neu']; }, $umzug)) }}</small>
            </div>
        </div>
    @endif

    {{-- Was der Shop beim letzten Heartbeat gesagt hat.

         Der wichtigste dieser Hinweise ist "12 Nutzer, lizenziert sind 5".
         Der Server SPERRT dafuer nicht -- Sperren bestraft genau die Kunden,
         die wachsen -- er sagt es. Nur landete das Gesagte bis zum
         04.09.2026 ausschliesslich in der Ausgabe von lastore:sync, also auf
         einer Kommandozeile, die nachts laeuft. Wer keinen Serverzugang hat,
         erfuhr es nie.

         Ueber dem Katalog und nicht darunter: es ist eine Aufforderung zu
         handeln, keine Fussnote. Kein Netzaufruf hier -- der naechtliche
         Heartbeat hat es hinterlegt, die Ansicht liest nur. --}}
    @foreach ($hinweise as $hinweis)
        <div class="alert alert-{{ $hinweis['level'] === 'warning' ? 'warning' : 'info' }}">
            {{ $hinweis['text'] }}
            @if ($hinweis['level'] === 'warning')
                <div class="text-muted" style="margin-top:6px">
                    <small>{{ __('Es wird nichts gesperrt. Bitte im Kundenportal aufstocken — sonst kommt die Differenz auf die nächste Rechnung.') }}</small>
                </div>
            @endif
        </div>
    @endforeach

    {{-- Dieselbe Marke, derselbe Name, dieselbe Klammer wie im Menü daneben.
         Vorher stand hier «Store» -- ein zweiter Name für dasselbe, und der
         Leser musste sich zusammenreimen, dass der Eintrag «LaStore» und die
         Überschrift «Store» dieselbe Seite meinen.

         Die Marke trägt hier KEIN `glyphicon`: dort draussen setzt die Regel
         der Seitenleiste sie absolut, hier soll sie im Textfluss mitlaufen
         und mit der Überschrift wachsen (`1em`). --}}
    <div class="section-heading">
        {!! \Modules\LaStore\Support\Marke::svg('lastore-marke') !!}
        {{ __('LaStore') }}@if (count($sichtbar)) <small>({{ count($sichtbar) }})</small>@endif
    </div>

    @include('partials/flash_messages')

    <div class="row-container form-container margin-top">
        @include('lastore::partials.toolbar')

        @if ($error)
            <div class="alert alert-warning">
                <strong>{{ __('Der Shop war nicht erreichbar.') }}</strong> {{ $error }}
                <br><small>{{ __('Gezeigt wird der zuletzt bekannte Stand.') }}</small>
            </div>
        @endif

        @if ($adoptable)
            {{-- Der Fall, der beim Umstieg zuerst eintritt: Module laufen schon,
                 haben aber noch keine Lizenz aus dem Store.

                 Hier steht nur noch der HINWEIS. Das Formular dazu stand
                 vorher doppelt - einmal hier und einmal in der Tabelle
                 darunter -, und zwei Stellen für dieselbe Handlung sind eine
                 Stelle zu viel: der Kunde fragt sich, ob es einen Unterschied
                 gibt. Getragen wird es jetzt von der Spalte "Aktion". --}}
            <div class="alert alert-info margin-bottom">
                <strong>{{-- Kein trans_choice(): unter Laravel 5.5 liest es die JSON-Sprachdateien nicht, der Satz bliebe in jeder Sprache deutsch. --}}{{ count($adoptable) === 1 ? __('Ein Modul läuft schon, noch ohne Lizenz aus dem Store.') : __(':count Module laufen schon, noch ohne Lizenz aus dem Store.', ['count' => count($adoptable)]) }}</strong>
                <br>{{ __('Sie laufen unverändert weiter. Mit dem Schlüssel ändert sich nur, woher sie ihre Updates beziehen — unten in der Liste unter „Lizenz übernehmen".') }}
            </div>
        @endif

        {{-- Karten statt Tabelle, im Stil der FreeScout-Module: Sinnbild,
             Beschreibung, installierte Fassung, Katalogfassung, Zustand.
             Eine Tabelle zeigt Spalten; eine Karte zeigt ein Modul. --}}
        {{-- FreeScouts eigenes CSS setzt `.module-card img` auf
             `width: 128px; height: 128px` OHNE `object-fit`
             (public/css/style.css). Das geht auf, solange jedes Bild
             quadratisch ist -- die Kacheln aus den module.json sind 256x256.

             Ein Katalogeintrag, der hier NICHT installiert ist, hat aber keine
             module.json: dann kommt das Bandbild des Ladens, 640x360, und wurde
             ins Quadrat gequetscht. Genau der Zustand, in dem ein Kunde das
             Modul zum ersten Mal sieht.

             `cover` schneidet stattdessen mittig zu. Gemessen am Band: die
             Illustration liegt bei x 212..427 von 640, der mittige Ausschnitt
             ist x 140..500 -- sie bleibt also vollstaendig, und ringsum steht
             das Raster. `contain` waere die Alternative, liesse aber oben und
             unten Luft in einer Reihe sonst gefuellter Kacheln.

             Eng auf diese Liste begrenzt, damit FreeScouts eigene Modulseite
             unberuehrt bleibt. Wo `object-fit` fehlt (IE11), sieht es aus wie
             heute -- es faellt auf das alte Verhalten zurueck, statt zu
             brechen. --}}
        <style>
            #lastore-module-liste .module-card img { object-fit: cover; }
        </style>

        {{-- `clearfix` und NICHT `row`.

             Bootstraps `.row` trägt `margin-left: -15px` -- gedacht für eine
             Reihe INNERHALB einer Spalte, deren Polsterung sie damit
             zurücknimmt. Hier stand sie direkt im `row-container`, und dessen
             15 px Polsterung hob sie damit auf: die Kacheln klebten am linken
             Rand und waren 15 px zu breit. Gemessen am 15.10.2026 bei 1280 px
             Fensterbreite:

                 mit `row`        Kachel bei x=261, 679 breit
                 mit `clearfix`   Kachel bei x=276, 659 breit
                 FreeScouts eigene Modulseite        x=276, 659 breit

             Dort stehen die Kacheln ohne Zwischenstück direkt im
             `row-container`. `clearfix` statt gar nichts, weil die Kacheln
             `col-*` sind und also schweben -- ohne das reicht der Behälter
             nicht um sie herum. --}}
        <div class="clearfix" id="lastore-module-liste">
            @foreach ($sichtbar as $row)
                @include('lastore::partials.module_card', ['row' => $row])
            @endforeach
        </div>

        {{-- Sicherungen alter Fassungen. Zugeklappt und nur, wenn welche
             liegen: es ist Hausputz, keine Aufgabe. Die Regel (eine je Modul,
             höchstens 7 Tage) räumt nachts von selbst; hier ist der Weg für
             den, der den Platz sofort will. Siehe Support\Sicherungen. --}}
        @if (!empty($sicherungen))
            @php $sicherungenSumme = array_sum(array_column($sicherungen, 'bytes')); @endphp
            <details class="lastore-sicherungen" style="margin:6px 0 16px">
                <summary class="text-muted" style="cursor:pointer">
                    {{ __('Sicherungen alter Fassungen') }}: {{ count($sicherungen) }} · {{ number_format($sicherungenSumme / 1048576, 1) }} MB
                </summary>
                <p class="text-help" style="margin:8px 0">
                    {{ __('Bei jedem Update legt LaStore die vorige Fassung beiseite, damit es einen Rückweg gibt. Je Modul bleibt eine, und sie wird nach 7 Tagen von selbst gelöscht.') }}
                </p>
                <table class="table table-condensed" style="max-width:640px;margin-bottom:8px">
                    <tbody>
                        @foreach ($sicherungen as $sicherung)
                            <tr>
                                <td>{{ $sicherung['modul'] }}</td>
                                <td class="text-muted">{{ \App\User::dateFormat(\Carbon\Carbon::createFromTimestamp($sicherung['zeit']), 'M j, H:i') }}</td>
                                <td class="text-muted">{{ number_format($sicherung['bytes'] / 1048576, 1) }} MB</td>
                                <td class="text-right">
                                    <form method="POST" action="{{ route('lastore.sicherungen') }}" style="display:inline"
                                          data-lastore-bestaetigen="{{ __('Diese Sicherung löschen? Ein Zurück auf die vorige Fassung geht danach nur noch über den Laden.') }}">
                                        {{ csrf_field() }}
                                        <input type="hidden" name="ordner" value="{{ $sicherung['ordner'] }}">
                                        <button type="submit" class="btn btn-link btn-xs">{{ __('Löschen') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if (count($sicherungen) > 1)
                    <form method="POST" action="{{ route('lastore.sicherungen') }}"
                          data-lastore-bestaetigen="{{ __('Alle Sicherungen löschen? Ein Zurück auf die vorige Fassung geht danach nur noch über den Laden.') }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="alle" value="1">
                        <button type="submit" class="btn btn-default btn-sm">{{ __('Alle löschen') }}</button>
                    </form>
                @endif
            </details>
        @endif

        {{-- Hier stand die Warnung "N Module tragen noch Zugangsdaten in
             ihrer module.json". Sie sagte die Wahrheit, aber sie stand am
             falschen Ort: ändern kann das nur, wer die Module baut, nicht
             wer sie betreibt. Der Verwalter einer Installation las eine
             Warnung, gegen die er nichts tun konnte.

             Die Prüfung selbst ist NICHT weg -- InstalledModules::
             withCredentials() gibt es weiterhin und sie ist geprüft. Sie
             gehört in den Betrieb bei uns, nicht auf den Bildschirm des
             Kunden. --}}
        <p class="text-muted" style="margin-bottom:12px">
            <small>
                {{ __('Powered by') }}
                <a href="https://letsautomate.ch" target="_blank" rel="noopener noreferrer">let&rsquo;s automate gmbh</a>
            </small>
        </p>

        <p class="text-muted">
            <small>
                {{ __('Installation') }}:
                @if ($installation->isRegistered())
                    <span class="mono">{{ $installation->installation_id }}</span>
                @else
                    {{ __('noch nicht angemeldet') }}
                @endif
                @if ($installation->isOffline()) · <strong>{{ __('Offline-Betrieb') }}</strong> @endif
            </small>
        </p>
    </div>

        {{-- Der Offline-Weg als Popup, aufgerufen aus der Werkzeugleiste.

             Vorher stand er zugeklappt am Fuss der Seite, unter der
             Modulliste. Das war zweimal falsch: er sah aus wie ein Anhang,
             obwohl er eine Handlung ist, und seine Aufschrift "Server ohne
             Internetverbindung" las sich als Befund über diesen Server statt
             als Frage.

             Bootstraps eigenes Modal, kein eigenes Javascript: FreeScout
             bringt es mit, und der Knopf braucht nur data-toggle. --}}

@endsection

{{-- Das Popup gehoert an das ENDE des <body>, nicht in den Inhalt.

     Der Grund ist eine Stapelfalle: ein fest positioniertes Element gilt mit
     seinem z-index nur INNERHALB des naechsten Elternteils, das selbst einen
     Stapelzusammenhang aufmacht. FreeScouts Inhaltsbehaelter tut das, und die
     Kopfleiste (z-index 1000, drei Reihen, 203 Pixel hoch) lag damit ueber
     dem Popup -- der Titel war halb verdeckt, egal welchen z-index das Modal
     selbst trug. Auch 10050 half nichts.

     `body_bottom` ist der Haken im Layout des Kerns, direkt vor den Skripten
     und ausserhalb aller Behaelter. Dort gilt der z-index gegen alles. --}}
@section('body_bottom')
    {{--
        @parent MUSS hier stehen, und das ist keine Formsache.

        FreeScout haengt seine schwebenden Meldungen ueber
        @section('body_bottom') ein -- Erfolg, Warnung, Fehler, alle. Blade
        setzt spaeteren Inhalt an die Stelle, an der @parent steht; fehlt es,
        wird der spaetere Inhalt STILL verworfen. Genau das war hier der Fall,
        und deshalb war diese Seite stumm.

        Die Wirkung war teuer. Das Modulupdate scheiterte auf unserem Host an
        etwas anderem (rename ueber eine Dateisystemgrenze), und der Knopf
        sagte dazu nichts: "Das Modulverzeichnis liess sich nicht ersetzen"
        wurde gesetzt und hier verschluckt. Ein Knopf, der nichts tut und
        nichts sagt, sieht aus wie einer ohne Funktion -- Booking stand
        deshalb wochenlang auf 0.11.0, waehrend im Katalog 0.13.0 lag.

        Es ist die einzige Ansicht in allen unseren Modulen, die diesen
        Abschnitt belegt hat, ohne ihn weiterzugeben.
    --}}
    @parent

        {{-- Eigene Stile, knapp und nur fuer dieses Fenster:

             z-index ueber ALLES. FreeScout haelt ein festes Element mit 9999
             auf jeder Seite (der Behaelter fuer die schwebenden Meldungen).
             Es ist null Pixel hoch und stoert nicht, aber ein Popup, das
             darunter liegen KANN, ist eine Wanze, die man nur bei bestimmten
             Fenstergroessen sieht. Darum 10050 statt Bootstraps 1050.

             Abstand nach oben, damit der Titel nicht an der Kopfleiste klebt
             -- Andre sah ihn dort halb verdeckt.

             Und eine Hoehengrenze mit eigenem Rollbereich: bei kleinem Fenster
             schob der Absendeknopf sonst aus dem Bild, und ein Knopf, den man
             nicht erreicht, ist kein Knopf. --}}
        <style>
            #lastore-offline { z-index: 10050; }
            #lastore-offline + .modal-backdrop, .modal-backdrop.lastore { z-index: 10040; }
            #lastore-offline .modal-dialog { margin-top: 60px; }
            #lastore-offline .modal-body { max-height: calc(100vh - 220px); overflow-y: auto; }
            @media (max-height: 600px) {
                #lastore-offline .modal-dialog { margin-top: 20px; }
            }
        </style>

        <div class="modal fade" id="lastore-offline" tabindex="-1" role="dialog"
             aria-labelledby="lastore-offline-titel">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Schliessen') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title" id="lastore-offline-titel">{{ __('Lizenz ohne Internetverbindung einlesen') }}</h4>
                    </div>

                    <div class="modal-body">
                        <p class="text-muted">
                            {{ __('Das Kundenportal erzeugt aus der Kennung dieses Servers eine signierte Lizenzdatei. Diese hier hochladen.') }}
                        </p>

                        {{-- isRegistered() und installation_id, NICHT ->uuid: die
                             Spalte heisst installation_id, und ->uuid gibt es
                             nicht. Mein erster Entwurf fragte danach, bekam
                             darum immer null und hätte den Upload jedem Kunden
                             verborgen. --}}
                        @if ($installation->isRegistered())
                            <p>
                                {{ __('Kennung dieses Servers') }}:
                                <code class="mono">{{ $installation->installation_id }}</code>
                            </p>

                            <form method="POST" action="{{ route('lastore.licenses.offline') }}" enctype="multipart/form-data">
                                {{ csrf_field() }}
                                <input type="file" name="license_file" class="form-control input-sm" accept=".txt,.lic,text/plain" required>
                                <button type="submit" class="btn btn-primary btn-sm margin-top">{{ __('Lizenzdatei einlesen') }}</button>
                            </form>
                        @else
                            {{-- Ohne Kennung gibt es nichts zu erzeugen. Die Kennung
                                 entsteht bei der ersten Anmeldung am Shop — vorher ist
                                 der Offline-Weg gar nicht gangbar, und ein leeres
                                 Feld hier wäre eine Einladung zum Rätseln. --}}
                            <p class="text-muted">
                                {{ __('Dieser Server ist noch nicht am Shop angemeldet. Die Kennung entsteht mit der ersten Lizenz — danach steht sie hier.') }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Rückfrage vor dem Löschen einer Sicherung. Als Skript mit Nonce,
             weil FreeScouts CSP onsubmit im Attribut still verwirft -- das
             Formular ginge dann ohne Rückfrage durch, und niemand merkte es. --}}
        <script {!! \Helper::cspNonceAttr() !!}>
            document.addEventListener('submit', function (e) {
                var frage = e.target.getAttribute && e.target.getAttribute('data-lastore-bestaetigen');
                if (frage && !window.confirm(frage)) {
                    e.preventDefault();
                }
            });
        </script>
@endsection

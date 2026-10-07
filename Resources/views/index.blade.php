@extends('layouts.app')

@section('title', __('Store'))

{{-- Die Seitenleiste der Modulverwaltung, damit der Sprung von dort hierher
     den Zusammenhang behält: wer aus "Modules" kommt, findet den Weg zurück
     an derselben Stelle. --}}
@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('lastore::partials.sidebar_menu')
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

    <div class="section-heading">
        {{ __('Store') }}
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

        <div class="row" id="lastore-module-liste">
            @foreach ($inventory as $row)
                @if ($row['state'] === \Modules\LaStore\Support\InstalledModules::STATE_FOREIGN)
                    @continue
                @endif
                @include('lastore::partials.module_card', ['row' => $row])
            @endforeach
        </div>

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
@endsection

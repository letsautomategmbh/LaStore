{{-- Eine Karte im Stil von FreeScouts `modules/partials/module_card`.

     Die Klassen sind ABSICHTLICH dieselben (.module-card, .module-wrap,
     .module-details, .module-actions, .alert-module-update): so kommt das
     Aussehen aus FreeScouts CSS und wandert bei einer Auffrischung mit,
     statt bei uns zu veralten.

     Nachgebaut und nicht die Kern-Vorlage eingebunden, weil unsere Daten
     andere sind: Zustand aus der Lizenz statt aus der Modultabelle, zwei
     Versionen (installiert und im Katalog) statt einer, und ein Ablaufdatum,
     das FreeScout nicht kennt. --}}
@php
    $zustand = $row['state'];
    $verfuegbar = \Modules\LaStore\Support\InstalledModules::STATE_AVAILABLE;
    $uebernehmbar = \Modules\LaStore\Support\InstalledModules::STATE_ADOPTABLE;
    $lizenziert = \Modules\LaStore\Support\InstalledModules::STATE_LICENSED;
    $neuer = $row['available'] && $row['installed']
             && version_compare($row['available'], $row['installed'], '>');
    $ablauf = $row['license'] ? $row['license']->ablaufDatum() : null;
@endphp

<div class="module-card col-sm-10 col-md-8 @if ($row['active']) active @elseif (!$row['installed']) not-installed @endif"
     id="lastore-module-{{ $row['alias'] }}">
    <img src="{{ $row['img'] ?: \App\Module::IMG_DEFAULT }}" alt="">

    <div class="module-wrap">
        <h4>
            {{ $row['name'] }}
            @if ($zustand === $verfuegbar)
                <span class="label label-lightgrey">{{ __('Nicht installiert') }}</span>
            @elseif ($zustand === $uebernehmbar)
                <span class="label label-warning">{{ __('Zu übernehmen') }}</span>
            @elseif (!$row['active'])
                <span class="label label-lightgrey">{{ __('Nicht aktiviert') }}</span>
            @else
                <span class="label label-success">{{ __('Lizenziert') }}</span>
            @endif
        </h4>

        <p>{{ $row['summary'] }}</p>

        <div class="module-details">
            <span>{{ __('Installiert') }}: {{ $row['installed'] ?: '—' }}</span>
            | <span>{{ __('Im Katalog') }}: {{ $row['available'] ?: '—' }}</span>

            @if ($ablauf && $ablauf['datum'])
                {{-- Rot, sobald es vorbei ist. Ein abgelaufenes Datum in
                     derselben Farbe wie ein gültiges ist eine Angabe, die man
                     lesen kann, ohne sie zu bemerken. --}}
                {{-- Klasse von Hand und NICHT mit @class([...]): diese Direktive
                     kam mit Laravel 8.51, FreeScout fährt 5.5. Sie wird nicht
                     übersetzt, sondern landet als Text im HTML — der Ausdruck
                     stand sichtbar auf der Seite, und nichts war rot. --}}
                | <span class="nowrap{{ $ablauf['abgelaufen'] ? ' text-danger' : '' }}">
                    @if ($ablauf['art'] === 'testphase')
                        {{-- Eigene Beschriftung: der Kunde hat nichts gekauft,
                             und das soll auf der Karte stehen. "Nutzbar bis"
                             liest sich wie ein Vertrag. --}}
                        {{ $ablauf['abgelaufen'] ? __('Testphase endete') : __('Testphase bis') }}
                    @elseif ($ablauf['art'] === 'nutzung')
                        {{ $ablauf['abgelaufen'] ? __('Nutzung endete') : __('Nutzbar bis') }}
                    @else
                        {{-- „Support bis", nicht „Updates bis": an dem Datum
                             endet beides, und Support ist das, was den Kunden
                             interessiert. Das Modul läuft weiter -- deshalb
                             steht hier nicht „Nutzbar bis". --}}
                        {{ $ablauf['abgelaufen'] ? __('Support endete') : __('Support bis') }}
                    @endif
                    <strong>{{ $ablauf['datum']->format('d.m.Y') }}</strong>
                </span>
            @endif

            @if ($row['details_url'])
                | <a href="{{ $row['details_url'] }}" target="_blank" rel="noopener">{{ __('Details ansehen') }}</a>
            @endif
        </div>

        <div class="module-actions form-horizontal">
            @if ($zustand === $verfuegbar || $zustand === $uebernehmbar)
                {{-- Dieselben zwei Beschriftungen, die FreeScout für seine
                     eigenen Module benutzt: ist das Modul auf der Platte,
                     wird nur die Lizenz eingetragen; fehlt es, wird auch
                     heruntergeladen. --}}
                <form method="POST"
                      action="{{ $zustand === $verfuegbar ? route('lastore.install') : route('lastore.licenses.activate') }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="product_alias" value="{{ $row['alias'] }}">
                    <div class="input-group">
                        <input type="text" class="form-control license-key mono" name="license_key"
                               placeholder="{{ __('Lizenzschlüssel') }}" required
                               aria-label="{{ __('Lizenzschlüssel für :modul', ['modul' => $row['name']]) }}">
                        <span class="input-group-btn">
                            <button class="btn btn-primary" type="submit">
                                {{ $zustand === $verfuegbar ? __('Modul installieren') : __('Lizenz übernehmen') }}
                            </button>
                        </span>
                    </div>
                </form>
                @if ($row['details_url'])
                    <small><a href="{{ $row['details_url'] }}" target="_blank" rel="noopener">{{ __('Lizenz erwerben') }}</a></small>
                @endif
            @endif
        </div>

        @if (!empty($row['umzug']) && $zustand === $lizenziert)
            {{-- Ein umbenanntes Modul wird nicht einzeln aktualisiert: es
                 zieht mit allen anderen zusammen um, siehe Support\Umzug.
                 Der Knopf dafür steht einmal oben auf der Seite. --}}
            <div class="alert alert-info alert-module-update">
                {{ __('Neue Fassung :v mit neuem internen Namen. Sie kommt mit dem Umzug oben auf dieser Seite.', ['v' => $row['available']]) }}
            </div>
        @elseif ($neuer && $zustand === $lizenziert)
            <div class="alert alert-warning alert-module-update">
                {{ __('Eine neue Fassung ist verfügbar') }}: <strong>{{ $row['available'] }}</strong>
                <form method="POST" action="{{ route('lastore.install') }}" class="margin-left-10" style="display:inline">
                    {{ csrf_field() }}
                    <input type="hidden" name="product_alias" value="{{ $row['alias'] }}">
                    <button type="submit" class="btn btn-default btn-sm">
                        <i class="glyphicon glyphicon-refresh"></i> {{ __('Jetzt aktualisieren') }}
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>

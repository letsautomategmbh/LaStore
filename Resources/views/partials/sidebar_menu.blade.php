{{-- Dieselbe Seitenleiste wie auf FreeScouts Modulseite, damit der Sprung
     dorthin den Zusammenhang nicht verliert.

     NACHGEBAUT und nicht `modules/sidebar_menu` eingebunden - aus einem
     Grund, der nicht offensichtlich ist: dort sind die ersten drei Einträge
     Sprungmarken auf derselben Seite (`#installed`, `#directory`,
     `#third-party`). Von hier aus zeigen sie ins Leere. Sie brauchen die
     volle Adresse, und damit ist die Datei ohnehin eine andere.

     Der Preis: ändert FreeScout seine Seitenleiste, wandert das hier nicht
     mit. Dafür bricht auch nichts - es sind drei Verweise auf eine Seite,
     die es gibt.

     Die Zahlen in Klammern der ersten drei Einträge kommen aus
     `Modulzahlen` -- abgelesen beim Kern, nicht selbst gerechnet. Fehlen
     sie, bleibt der Eintrag ohne Klammer; dort steht, warum das in Ordnung
     ist.

     Die eigene Zahl kommt von der Seite selbst (`$eigeneAnzahl`) und zählt
     genau die Kacheln, die darunter stehen -- siehe `InstalledModules::sichtbar()`.
     Fehlt sie, weil die Leiste einmal von woanders eingebunden wird, zählt
     sie derselbe Filter nach.

     Das Sinnbild ist unsere eigene Marke und kein Glyphicon: der Marktplatz
     trug denselben Einkaufswagen, und zwei gleiche Symbole untereinander
     sind keine Unterscheidung. Siehe `Support/Marke.php`. --}}
@php
    $zahlen = \Modules\LaStore\Support\Modulzahlen::holen();
    $eigeneAnzahl = isset($eigeneAnzahl)
        ? $eigeneAnzahl
        : \Modules\LaStore\Support\InstalledModules::sichtbareAnzahl();
@endphp

<div class="sidebar-title">
    {{ __('Modules') }}
</div>
<ul class="sidebar-menu">
    <li>
        <a href="{{ route('modules') }}#installed">
            <i class="glyphicon glyphicon-saved"></i> {{ __('Installed Modules') }}@if (!empty($zahlen['installiert'])) <small>({{ $zahlen['installiert'] }})</small>@endif
        </a>
    </li>
    <li>
        <a href="{{ route('modules') }}#directory">
            <i class="glyphicon glyphicon-briefcase"></i> {{ __('Modules Directory') }}@if (!empty($zahlen['ordner'])) <small>({{ $zahlen['ordner'] }})</small>@endif
        </a>
    </li>
    <li>
        <a href="{{ route('modules') }}#third-party">
            <i class="glyphicon glyphicon-shopping-cart"></i> {{ __('Marketplace') }}@if (!empty($zahlen['marktplatz'])) <small>({{ $zahlen['marktplatz'] }})</small>@endif
        </a>
    </li>
    <li class="active">
        <a href="{{ route('lastore.index') }}">
            {!! \Modules\LaStore\Support\Marke::svg() !!} {{ __('LaStore') }}@if ($eigeneAnzahl) <small>({{ $eigeneAnzahl }})</small>@endif
        </a>
    </li>
</ul>

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

     Die Zahlen in Klammern kommen aus `Modulzahlen` -- abgelesen beim Kern,
     nicht selbst gerechnet. Fehlen sie, bleibt der Eintrag ohne Klammer;
     dort steht, warum das in Ordnung ist. Der eigene Eintrag trägt keine,
     genau wie auf der Modulseite des Kerns. --}}
@php $zahlen = \Modules\LaStore\Support\Modulzahlen::holen(); @endphp

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
            <i class="glyphicon glyphicon-shopping-cart"></i> {{ __('LaStore') }}
        </a>
    </li>
</ul>

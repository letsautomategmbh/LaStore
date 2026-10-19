<?php

namespace Modules\LaStore\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\LaStore\Entities\CatalogEntry;
use Modules\LaStore\Entities\Installation;
use Modules\LaStore\Entities\License;
use Illuminate\Http\Request;
use Modules\LaStore\Services\PackageInstaller;
use Modules\LaStore\Services\LicenseService;
use Modules\LaStore\Services\StoreClient;
use Modules\LaStore\Services\StoreException;
use Modules\LaStore\Support\Hinweise;
use Modules\LaStore\Support\InstalledModules;
use Modules\LaStore\Support\ModulAnmeldung;
use Modules\LaStore\Support\SelfUpdater;
use Modules\LaStore\Support\Sicherungen;
use Modules\LaStore\Support\Umzug;

class StoreController extends Controller
{
    public function __construct()
    {
        $this->middleware('roles')->only([]);
    }

    public function index()
    {
        $this->authorizeAdmin();

        $service = new LicenseService();
        $installation = $service->installation();
        $error = null;

        // Den Katalog frisch holen, wenn er aelter als 15 Minuten ist -
        // dieselbe Frist, die FreeScout fuer seine eigene Modulliste
        // verwendet. Scheitert das, wird der zuletzt gesehene gezeigt: ein
        // leerer Katalog sieht aus wie ein Fehler, und der Kunde ruft an.
        if ($this->catalogIsStale($installation)) {
            try {
                CatalogEntry::replaceAll($service->client()->catalog());
                $installation->last_catalog_sync_at = \Carbon\Carbon::now();
                $installation->save();
            } catch (StoreException $e) {
                $error = $e->getMessage();
            }
        }

        /*
         * Beilaeufig nachsehen, ob LaStore selbst etwas Neues hat -- hoechstens
         * einmal am Tag.
         *
         * Warum hier und nicht nur im naechtlichen Lauf: der braucht einen
         * eingerichteten Cron. Wo keiner laeuft, erschiene der Hinweis nie --
         * und genau das war die Sorge, dass ein Modul einmal installiert und
         * dann nie mehr angefasst wird.
         *
         * Der Grundsatz "kein HTTP im Seitenaufbau" gilt fuer die Seiten, die
         * ein Bearbeiter beim Ticketlesen sieht. DIESE Seite spricht schon mit
         * dem Server (Katalogabgleich, 15 Minuten), also ist es hier kein
         * neuer Bruch, sondern derselbe Weg.
         *
         * Scheitert es, bleibt der letzte Befund stehen. Ein Shop, der nicht
         * antwortet, darf keine Fehlerseite ergeben.
         */
        $selbstGeprueft = (int) \Option::get('lastore.selbst_geprueft_am', 0);

        if ($selbstGeprueft < time() - 86400) {
            try {
                SelfUpdater::pruefen();
            } catch (\Exception $e) {
                // Still. Der Befund von gestern ist besser als eine rote Seite.
            }

            \Option::set('lastore.selbst_geprueft_am', time());
        }

        $this->zieheNach();

        return view('lastore::index', [
            'inventory'    => InstalledModules::inventory(),
            'adoptable'    => InstalledModules::adoptable(),
            /*
             * `unsigned_update` wird der Ansicht nicht uebergeben: die
             * Warnung gehoert nicht in die Kundenansicht, denn dort ist
             * nichts zu entscheiden -- die Felder verschwinden beim naechsten
             * Update aus dem Shop von selbst.
             *
             * InstalledModules::withUnsignedUpdate() bleibt bestehen und ist
             * der Einstieg fuer den Betrieb; `lastore:adopt` zeigt es an.
             */
            'labels'       => InstalledModules::stateLabels(),
            'installation' => $installation,
            'error'        => $error,
            'transport'    => config('lastore.transport'),
            'hinweise'     => Hinweise::offen(),
            'selbstNeu'    => SelfUpdater::hinterlegt(),
            'selbstNotiz'  => SelfUpdater::hinterlegteNotiz(),
            'sicherungen'  => $this->sicherungenMitGroesse(),
            'umzug'        => Umzug::anstehend(),
        ]);
    }

    /**
     * Umbenannte Module umziehen, alle zusammen. Siehe Support\Umzug.
     */
    public function umzug(Request $request)
    {
        $this->authorizeAdmin();

        $ergebnis = Umzug::ausfuehren();

        switch ($ergebnis['status']) {
            case Umzug::KEINER:
                \Session::flash('flash_success_floating', __('Kein Umzug steht an.'));
                break;

            case Umzug::WARTEN:
                \Session::flash('flash_warning_floating', $ergebnis['fehler']);
                break;

            case Umzug::FEHLER:
                \Session::flash('flash_error_floating', __('Nicht umgezogen, der bisherige Stand ist unverändert: :fehler', ['fehler' => $ergebnis['fehler']]));
                break;

            default:
                /*
                 * Gleich danach alle übrigen Module aktualisieren.
                 *
                 * Module, die nicht umbenannt wurden, aber auf umbenannte
                 * zeigen (LaPortal auf `labexio.*`), müssen im selben Zug
                 * nachziehen. Bis der Knopf gedrückt wurde, waren Einzel-
                 * Updates gesperrt -- siehe install().
                 */
                try {
                    \Artisan::call('lastore:update', array('--yes' => true));
                } catch (\Throwable $e) {
                    \Session::flash('flash_warning_floating', __('Die Module sind umgezogen. Die übrigen Updates holt der nächste Lauf: :fehler', ['fehler' => $e->getMessage()]));
                }

                if ($ergebnis['offen']) {
                    $befehle = array();

                    foreach (array_keys($ergebnis['offen']) as $alias) {
                        $befehle[] = '<code>'.e(ModulAnmeldung::befehl($alias)).'</code>';
                    }

                    \Session::flash('flash_error_unescaped', __(
                        'Die Module sind umgezogen, aber nicht alle angemeldet. Bitte auf dem Server ausführen: :befehle',
                        ['befehle' => implode(' ', $befehle)]
                    ));
                } else {
                    \Session::flash('flash_success_floating', __(':n Module sind umgezogen.', ['n' => count($ergebnis['module'])]));
                }
        }

        return redirect()->route('lastore.index');
    }

    /**
     * Sicherungen alter Fassungen loeschen: eine, oder alle.
     *
     * Der Ordnername aus dem Formular wird nicht als Pfad benutzt, sondern
     * unter den gefundenen Sicherungen gesucht -- siehe Sicherungen::loeschen().
     */
    public function sicherungen(Request $request)
    {
        $this->authorizeAdmin();

        if ($request->input('alle')) {
            $vorher = count(Sicherungen::alle());
            $weg = Sicherungen::alleLoeschen();

            if (count($weg) < $vorher) {
                \Session::flash('flash_error_floating', __(':n von :m Sicherungen liessen sich nicht löschen. Fehlen dem Webserver die Rechte daran?', ['n' => $vorher - count($weg), 'm' => $vorher]));
            } else {
                \Session::flash('flash_success_floating', __('Alle Sicherungen sind gelöscht.'));
            }

            return redirect()->back();
        }

        if (Sicherungen::loeschen((string) $request->input('ordner'))) {
            \Session::flash('flash_success_floating', __('Die Sicherung ist gelöscht.'));
        } else {
            \Session::flash('flash_error_floating', __('Diese Sicherung liess sich nicht löschen.'));
        }

        return redirect()->back();
    }

    /**
     * Fuer die Ansicht: jede Sicherung samt Groesse.
     *
     * Die Groesse kostet einen Gang durch den Ordner. Bei einer Sicherung je
     * Modul sind das hoechstens zwei Dutzend, und diese Seite sieht nur der
     * Verwalter -- vertretbar, ohne Zwischenspeicher.
     *
     * @return array
     */
    protected function sicherungenMitGroesse()
    {
        $liste = Sicherungen::alle();

        foreach ($liste as $i => $sicherung) {
            $liste[$i]['bytes'] = Sicherungen::groesse($sicherung['pfad']);
        }

        return $liste;
    }

    /**
     * Nachziehen, wenn die laufende Fassung nie beim Kern angemeldet wurde.
     *
     * **Der Grund, und er ist unangenehm.** Eine Reparatur, die IM
     * Aktualisierungsweg sitzt, greift erst eine Fassung spaeter -- der
     * Tausch wird immer vom ALTEN Code ausgefuehrt. Genau das ist bei 1.0.8
     * passiert (der Modul-Cache blieb stehen) und waere hier wieder
     * passiert: 1.0.9 tauscht auf 1.2.0 und meldet nicht an, weil 1.0.9 das
     * noch nicht kann.
     *
     * Darum steht die Reparatur auch HIER, im neuen Stand: er sieht beim
     * ersten Aufruf, dass unter seiner Fassung noch nie angemeldet wurde,
     * und holt es nach. Damit heilt sich jeder Weg selbst -- unser
     * Aktualisierer, FreeScouts eigener, und ein von Hand entpacktes Archiv.
     *
     * Hoechstens einmal je Fassung: der Merker traegt die Fassung, nicht ein
     * Ja/Nein. Ein Ja/Nein waere nach dem naechsten Update wieder falsch.
     *
     * @return void
     */
    protected function zieheNach()
    {
        $laeuft = StoreClient::clientVersion();

        if ($laeuft === '' || $laeuft === \Option::get('lastore.angemeldet_fassung', '')) {
            return;
        }

        $anmeldung = ModulAnmeldung::anmelden(SelfUpdater::ALIAS);

        if (!$anmeldung['ok']) {
            // Nicht merken, wenn es nicht geklappt hat -- beim naechsten
            // Aufruf wird es wieder versucht. Und der Mensch soll es sehen:
            // neuer Code auf alter Datenbank ist die gefaehrlichere Lage.
            \Session::flash('flash_error_unescaped', __(
                'LaStore :v ist installiert, aber die Datenbank ist nicht nachgezogen. Bitte auf dem Server ausführen: :befehl',
                ['v' => $laeuft, 'befehl' => '<code>'.e(SelfUpdater::anmeldebefehl()).'</code>']
            ));

            return;
        }

        \Option::set('lastore.angemeldet_fassung', $laeuft);
    }

    /**
     * Autopilot ein- oder ausschalten.
     *
     * Eingeschaltet holt der naechtliche Lauf Updates fuer ALLE lizenzierten
     * Module und installiert sie - Signaturpruefung inbegriffen, denselben
     * Weg wie der Knopf von Hand.
     *
     * Standard ist AUS, und das ist Absicht: ein Update installiert Code auf
     * einer Anlage, die Tickets bearbeitet. Wer das ueber Nacht ohne Zuschauer
     * will, soll es sagen.
     */
    public function autopilot(Request $request)
    {
        $this->authorizeAdmin();

        $an = (bool) $request->input('autopilot');
        \Option::set('lastore.autopilot', $an ? 1 : 0);

        \Session::flash('flash_success_floating', $an
            ? __('Autopilot ist an. Updates werden nachts geholt und installiert.')
            : __('Autopilot ist aus. Updates bleiben Handarbeit.'));

        return redirect()->back();
    }

    /**
     * Schluessel eintragen, pruefen, installieren, registrieren - ein Druck.
     *
     * Genau der Ablauf, den FreeScout fuer seine eigenen Module hat. Vorher
     * war es bei uns zweigeteilt: die Lizenz liess sich hier uebernehmen, das
     * Modul aber nur ueber die Kommandozeile installieren (lastore:update).
     * Fuer einen Kunden, der ein Modul gekauft hat, war das ein Schritt zu
     * viel - und einer, den er ohne Serverzugang gar nicht gehen kann.
     *
     * FreeScouts eigener Knopf laesst sich dafuer NICHT benutzen: der Host
     * seiner Schnittstelle steht fest im Kern (config/app.php,
     * 'freescout_api' => 'https://api.freescout.net/wp-json/'), und der Kunde
     * fährt Standard-FreeScout. Die Schrittfolge ist aber dieselbe, mit einem
     * Unterschied: FreeScout vertraut beim Download auf TLS, wir pruefen
     * zusaetzlich die Signatur des Pakets.
     */
    /**
     * LaStore selbst aktualisieren.
     *
     * Im Web und nicht nur auf der Kommandozeile, weil genau das die Sorge
     * war: was von Hand geht, wird einmal gemacht und dann nie wieder. Ein
     * Knopf, der von selbst erscheint, sobald etwas bereitliegt, ist der
     * Unterschied zwischen einem aktuellen und einem stehengebliebenen
     * Vertrauensfundament.
     *
     * Nach dem Tausch wird nur noch umgeleitet: alles, was diese Antwort
     * braucht, ist bereits geladen. Der Selbstaktualisierer selbst macht nach
     * dem Tausch ausschliesslich Dateiarbeit.
     */
    public function selfUpdate(Request $request)
    {
        $this->authorizeAdmin();

        try {
            $ergebnis = SelfUpdater::aktualisieren();
        } catch (\Exception $e) {
            \Session::flash('flash_error_floating', $e->getMessage());

            return redirect()->back();
        }

        if ($ergebnis['status'] === SelfUpdater::AKTUELL) {
            \Session::flash('flash_success_floating', __('LaStore ist aktuell.'));

            return redirect()->back();
        }

        // Der Tausch hat geklappt -- das Anmelden beim Kern kann trotzdem
        // gescheitert sein. Dann liegt neuer Code auf einer alten Datenbank,
        // und das ist keine Erfolgsmeldung, sondern eine Aufgabe.
        $anmeldung = isset($ergebnis['anmeldung']) ? $ergebnis['anmeldung'] : null;

        if ($anmeldung && !$anmeldung['ok']) {
            \Session::flash('flash_error_unescaped', __(
                'LaStore läuft jetzt in Fassung :v, aber die Datenbank ist nicht nachgezogen: :fehler Bitte auf dem Server ausführen: :befehl',
                [
                    'v'      => $ergebnis['version'],
                    'fehler' => e($anmeldung['fehler']),
                    'befehl' => '<code>'.e(SelfUpdater::anmeldebefehl()).'</code>',
                ]
            ));

            return redirect()->route('lastore.index');
        }

        \Session::flash('flash_success_floating', __('LaStore läuft jetzt in Fassung :v.', ['v' => $ergebnis['version']]));

        return redirect()->route('lastore.index');
    }

    public function install(Request $request)
    {
        $this->authorizeAdmin();

        $alias = strtolower(trim((string) $request->input('product_alias')));
        $key = (string) $request->input('license_key');

        if ($alias === '') {
            \Session::flash('flash_error_floating', __('Es fehlt der Modul-Alias.'));

            return redirect()->back();
        }

        /*
         * Liegt das Modul noch unter seinem alten Kürzel hier, ist das ein
         * Umzug und keine Installation. Sonst entstünde `LaBexio` NEBEN
         * `Bexio`, und FreeScout lüde beide -- doppelte Strecken auf
         * denselben Tabellen.
         */
        /*
         * Solange ein Umzug ansteht, nichts einzeln installieren.
         *
         * Neue Fassungen zeigen schon auf die neuen Namen der Nachbarn
         * (`route('labexio.…')`). Käme eine davon VOR dem Umzug, fände sie
         * ihre Nachbarn nicht. Der Umzug aktualisiert danach alles in einem
         * Zug.
         */
        if (Umzug::anstehend()) {
            \Session::flash('flash_error_floating', __('Zuerst umziehen: Oben auf dieser Seite steht der Umzug auf die neuen internen Namen an. Danach werden alle Module in einem Zug aktualisiert.'));

            return redirect()->back();
        }

        foreach (Umzug::ersetzungen() as $alt => $neu) {
            if ($neu === $alias && \Module::findByAlias($alt)) {
                \Session::flash('flash_error_floating', __('Dieses Modul ist hier schon unter seinem früheren Namen installiert. Bitte oben «Jetzt umziehen» wählen.'));

                return redirect()->back();
            }
        }

        $service = new LicenseService();
        $vorhanden = License::where('product_alias', $alias)->first();

        /*
         * Der Schluessel ist NUR beim ersten Mal noetig.
         *
         * Beim Aktualisieren liegt die Lizenz lokal schon vor, und der Client
         * weist sich mit ihrem Token aus - nicht mit dem Schluessel. Den hier
         * trotzdem zu verlangen war ein Fehler: FreeScouts getLicense() gibt
         * fuer unsere Module einen LEEREN Text zurueck, mein Aktualisieren-
         * Knopf haette also immer mit "Es fehlt der Lizenzschluessel"
         * abgebrochen. Aufgefallen erst beim Rendern der echten Seite.
         */
        if (trim($key) === '' && !$vorhanden) {
            \Session::flash('flash_error_floating', __('Es fehlt der Lizenzschlüssel.'));

            return redirect()->back();
        }

        // 1. Die Lizenz - falls ein Schluessel dabei ist. Schlaegt sie fehl,
        //    wird NICHTS heruntergeladen: ein Paket zu holen, das man nicht
        //    benutzen darf, hilft niemandem.
        $license = $vorhanden;

        if (trim($key) !== '') {
            try {
                $license = $service->activate($key, $alias);
            } catch (StoreException $e) {
                \Session::flash('flash_error_floating', $e->getMessage());

                return redirect()->back();
            }
        }

        // 2. Herunterladen, Signatur pruefen, entpacken.
        $installer = new PackageInstaller($service->client());

        try {
            $meldung = $installer->available($alias);
            $zip = $installer->fetchVerified($meldung);
            $installer->install($zip, $alias);
        } catch (StoreException $e) {
            /*
             * Die Lizenz bleibt aktiviert. Das ist gewollt: sie ist
             * bezahlt und gehoert dem Kunden, auch wenn der Download
             * gerade scheitert. Er sieht das Modul danach unter "Zu
             * uebernehmen" und kann es erneut versuchen.
             */
            \Session::flash('flash_error_floating', __('Lizenz übernommen, aber das Installieren ist gescheitert: :fehler', array('fehler' => $e->getMessage())));

            return redirect()->back();
        }

        // 3. Bei FreeScout anmelden - dieselben Aufrufe, die sein eigener
        //    Knopf macht. Ohne sie liegt das Modul nur im Verzeichnis: der
        //    Kern kennt es nicht, die Wanderungen laufen nicht, und die
        //    oeffentlichen Dateien fehlen.
        \Module::clearCache();

        if (trim($key) !== '') {
            \App\Module::activateLicense($alias, $key);
        }

        \App\Module::setActive($alias, true);
        \Artisan::call('freescout:module-install', array('module_alias' => $alias));
        \Artisan::call('freescout:clear-cache');

        \Session::flash('flash_success_floating', __(':modul :version installiert und lizenziert.', array(
            'modul'   => $alias,
            'version' => isset($meldung['version']) ? $meldung['version'] : '',
        )));

        if ($license && $license->status === \Modules\LaStore\Support\LicenseToken::GRACE) {
            \Session::flash('flash_warning_floating', __('Die Lizenz läuft in der Gnadenfrist. Bitte im Kundenportal verlängern.'));
        }

        return redirect()->back();
    }


    protected function catalogIsStale(Installation $installation)
    {
        if ($installation->isOffline()) {
            return false;
        }

        if (!$installation->last_catalog_sync_at) {
            return true;
        }

        return $installation->last_catalog_sync_at->diffInMinutes(\Carbon\Carbon::now()) >= 15;
    }

    protected function authorizeAdmin()
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403);
        }
    }
}

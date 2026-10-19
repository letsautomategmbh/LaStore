<?php

namespace Modules\LaStore\Support;

use Modules\LaStore\Entities\CatalogEntry;
use Modules\LaStore\Entities\License;
use Modules\LaStore\Services\LicenseService;
use Modules\LaStore\Services\PackageInstaller;
use Modules\LaStore\Services\StoreException;

/**
 * Umbenannte Module umziehen: Bexio -> LaBexio, bexio -> labexio.
 *
 * Seit 19.10.2026 tragen alle Module von let's automate das Präfix «La», auch
 * intern: Ordner, Kürzel, Namensraum, Strecken, Haken, Optionen. Der Laden
 * meldet ab LaStore 2.5.0 im Katalog, welches frühere Kürzel ein Produkt
 * ersetzt (`ersetzt: ["bexio"]`). Daran erkennt dieser Schritt ein
 * installiertes Modul, das umziehen muss. Bauplan in Obsidian:
 * Freescout/Plattform/Umbenennung auf La.
 *
 * **Alle oder keines.** Die Module rufen sich gegenseitig über Streckennamen,
 * Ansichtsnamensräume und Haken auf (Invoicing über `route('bexio.…')`, HR
 * über `@include('invoicing::…')`). Zieht eines allein um, finden seine
 * Nachbarn es nicht mehr. Darum werden ERST alle Pakete geholt und geprüft,
 * DANN alle Ordner getauscht, und scheitert ein Tausch, gehen alle zurück.
 *
 * Reihenfolge:
 *
 *   1. Warteschlange: liegen Aufträge eines umziehenden Moduls in `jobs`,
 *      wird nicht umgezogen -- ihr serialisierter Klassenname zeigte danach
 *      ins Leere. Nächstes Mal wieder.
 *   2. Alle Pakete holen und prüfen (Signatur vor dem Entpacken).
 *   3. Alle Ordner tauschen; bei einem Fehler alle zurück.
 *   4. Datenbank in einer Transaktion: die Zeile in `modules` (aktiv,
 *      Lizenz des Kerns), `lastore_licenses`, Katalogzwischenspeicher.
 *   5. Beim Kern anmelden: Wanderungen (darunter die des Moduls selbst, die
 *      seine Optionen und Glockenmeldungen umschreibt), neuer Link in
 *      `public/modules/`.
 *   6. Den ALTEN Link in `public/modules/` auf den neuen Ordner biegen.
 *      Fremde Webseiten binden `/modules/booking/js/widget.js` ein, und
 *      verschickte Signaturen zeigen Bilder unter `/modules/signatures/`.
 *      Er bleibt dauerhaft (Entscheid André, 19.10.2026).
 *   7. Lizenzen neu holen, jetzt unter dem neuen Kürzel.
 *
 * Ab Schritt 5 wird nicht mehr zurückgerollt: eine halb gewanderte
 * Datenbank unter altem Code wäre schlimmer als ein gemeldeter Fehler --
 * dieselbe Abwägung wie in ModulAnmeldung.
 */
class Umzug
{
    const KEINER = 'keiner';
    const WARTEN = 'warten';
    const FERTIG = 'fertig';
    const FEHLER = 'fehler';

    /**
     * Was umziehen muss: installierte Module, deren Kürzel im Katalog unter
     * `ersetzt` eines anderen Produkts steht, und die hier lizenziert sind.
     *
     * Ohne Lizenzzeile kein Umzug: das Paket gibt es nur gegen eine Lizenz.
     * Solche Module meldet `ohneLizenz()`, damit der Mensch es sieht.
     *
     * @return array<int, array{alt: string, neu: string, name: string, pfad: string}>
     */
    public static function anstehend()
    {
        $raus = array();
        $lizenziert = License::pluck('product_alias')->all();

        foreach (self::kandidaten() as $kandidat) {
            if (in_array($kandidat['alt'], $lizenziert, true)) {
                $raus[] = $kandidat;
            }
        }

        return $raus;
    }

    /** @return array<int, array{alt: string, neu: string, name: string, pfad: string}> */
    public static function ohneLizenz()
    {
        $lizenziert = License::pluck('product_alias')->all();

        return array_values(array_filter(self::kandidaten(), function ($k) use ($lizenziert) {
            return !in_array($k['alt'], $lizenziert, true);
        }));
    }

    /**
     * Welches heutige Kürzel ein früheres ersetzt, aus dem Katalog.
     *
     * @return array<string, string> alt => neu
     */
    public static function ersetzungen()
    {
        $karte = array();

        foreach (CatalogEntry::all() as $eintrag) {
            foreach ((array) $eintrag->value('ersetzt', array()) as $alt) {
                $alt = strtolower((string) $alt);

                if ($alt !== '' && $alt !== $eintrag->alias) {
                    $karte[$alt] = $eintrag->alias;
                }
            }
        }

        return $karte;
    }

    /**
     * Den Umzug ausführen.
     *
     * @param callable|null $melden function(string $zeile) für Fortschritt
     *
     * @return array{status: string, module: array, fehler: ?string, offen: array}
     */
    public static function ausfuehren(?PackageInstaller $installer = null, ?callable $melden = null)
    {
        $installer = $installer ?: new PackageInstaller();
        $melden = $melden ?: function ($zeile) {
        };

        $liste = self::anstehend();

        if (!$liste) {
            return self::ergebnis(self::KEINER);
        }

        // ── 1. Warteschlange ────────────────────────────────────────────
        $belegt = self::wartendeAuftraege($liste);

        if ($belegt) {
            $melden('Aufträge in der Warteschlange für '.implode(', ', $belegt).' -- der Umzug wartet bis zum nächsten Lauf.');

            return self::ergebnis(self::WARTEN, array(), Text::get('In der Warteschlange liegen noch Aufträge für :module. Der Umzug wird beim nächsten Lauf nachgeholt.', array('module' => implode(', ', $belegt))));
        }

        // ── 2. Alle Pakete holen und prüfen ─────────────────────────────
        $pakete = array();

        try {
            foreach ($liste as $eintrag) {
                $melden(sprintf('%s -> %s: Paket holen und prüfen', $eintrag['alt'], $eintrag['neu']));

                // Gefragt wird unter dem ALTEN Kürzel: dazu gehört die
                // Aktivierung auf dem Server, und ein LaStore ab 2.5.0
                // bekommt darauf das Paket mit dem neuen.
                $meldung = $installer->available($eintrag['alt']);
                $imPaket = isset($meldung['alias']) ? strtolower((string) $meldung['alias']) : '';

                if ($imPaket !== $eintrag['neu']) {
                    throw new StoreException(
                        Text::get('Der Laden bietet für :alt kein Paket :neu an.', array('alt' => $eintrag['alt'], 'neu' => $eintrag['neu'])),
                        'umzug_kein_paket'
                    );
                }

                $pakete[$eintrag['alt']] = $installer->fetchVerified($meldung);
            }
        } catch (StoreException $e) {
            foreach ($pakete as $datei) {
                @unlink($datei);
            }

            return self::ergebnis(self::FEHLER, array(), $e->getMessage());
        }

        // ── 3. Alle Ordner tauschen, oder keinen ────────────────────────
        $getauscht = array();

        try {
            foreach ($liste as $eintrag) {
                $melden(sprintf('%s -> %s: Ordner tauschen', $eintrag['name'], $eintrag['neu']));
                $getauscht[$eintrag['alt']] = $installer->umziehen($pakete[$eintrag['alt']], $eintrag['neu'], $eintrag['pfad']);
                unset($pakete[$eintrag['alt']]);
            }
        } catch (StoreException $e) {
            foreach (array_reverse($getauscht) as $ergebnis) {
                $installer->umzugZuruecknehmen($ergebnis);
            }

            foreach ($pakete as $datei) {
                @unlink($datei);
            }

            self::modullisteLeeren();

            return self::ergebnis(self::FEHLER, array(), $e->getMessage());
        }

        // SOFORT nach dem Tausch, nicht erst nach der Datenbank: der Kern
        // merkt sich seine Modulliste samt Pfaden im Anwendungscache. Zeigt
        // sie auf einen Ordner, den es nicht mehr gibt, antwortet JEDE Seite
        // mit 500 («Class … ServiceProvider not found») -- in der
        // Entwicklungsumgebung am 19.10.2026 genau so gesehen.
        self::modullisteLeeren();

        // ── 4. Datenbank ────────────────────────────────────────────────
        try {
            \DB::transaction(function () use ($liste) {
                foreach ($liste as $eintrag) {
                    self::kernZeileUmschreiben($eintrag['alt'], $eintrag['neu']);

                    License::where('product_alias', $eintrag['alt'])->update(array('product_alias' => $eintrag['neu']));
                    CatalogEntry::where('alias', $eintrag['alt'])->delete();
                }
            });
        } catch (\Throwable $e) {
            // Noch ist nichts gewandert: die Dateien zurück, dann steht der
            // alte Stand wieder vollständig da.
            foreach (array_reverse($getauscht) as $ergebnis) {
                $installer->umzugZuruecknehmen($ergebnis);
            }

            self::modullisteLeeren();

            return self::ergebnis(self::FEHLER, array(), $e->getMessage());
        }

        self::modullisteLeeren();

        // ── 5. Anmelden, 6. alter Link ──────────────────────────────────
        $offen = array();

        foreach ($liste as $eintrag) {
            $melden(sprintf('%s: beim Kern anmelden', $eintrag['neu']));
            $anmeldung = ModulAnmeldung::anmelden($eintrag['neu']);

            if (!$anmeldung['ok']) {
                $offen[$eintrag['neu']] = $anmeldung['fehler'] ?: $anmeldung['ausgabe'];
            }

            self::altenLinkUmbiegen($eintrag['alt'], $getauscht[$eintrag['alt']]['ziel']);
        }

        // ── 7. Lizenzen unter dem neuen Kürzel ──────────────────────────
        try {
            (new LicenseService())->refreshAll();
        } catch (\Throwable $e) {
            // Kein Grund zur Sorge: das alte Token gilt weiter bis zu seinem
            // Ablauf, und der tägliche Abgleich holt das neue.
            $melden('Lizenzen werden beim nächsten Abgleich nachgeführt: '.$e->getMessage());
        }

        $module = array();

        foreach ($liste as $eintrag) {
            $module[] = array(
                'alt'     => $eintrag['alt'],
                'neu'     => $eintrag['neu'],
                'version' => $getauscht[$eintrag['alt']]['version'],
                'backup'  => $getauscht[$eintrag['alt']]['backup'],
            );
        }

        return self::ergebnis(self::FERTIG, $module, null, $offen);
    }

    // ─────────────────────────────────────────────────────── innere Teile

    /** @return array<int, array{alt: string, neu: string, name: string, pfad: string}> */
    protected static function kandidaten()
    {
        $karte = self::ersetzungen();

        if (!$karte) {
            return array();
        }

        $raus = array();

        foreach (\Module::all() as $modul) {
            $alt = strtolower((string) $modul->getAlias());

            if (!isset($karte[$alt])) {
                continue;
            }

            $raus[] = array(
                'alt'  => $alt,
                'neu'  => $karte[$alt],
                'name' => $modul->getName(),
                'pfad' => $modul->getPath(),
            );
        }

        return $raus;
    }

    /**
     * Die Zeile des Kerns in `modules` mitnehmen: aktiv, Lizenz.
     *
     * Nicht über \App\Module::setActive(): das legte eine NEUE Zeile an und
     * liesse die alte stehen. FreeScout lädt Module nach diesem Kürzel, und
     * die Spalte ist eindeutig.
     */
    protected static function kernZeileUmschreiben($alt, $neu)
    {
        $tabelle = \DB::table('modules');

        if (!$tabelle->where('alias', $alt)->exists()) {
            return;
        }

        // Eine leere Zeile unter dem neuen Kürzel (ein früherer Versuch,
        // eine Aktivierung von Hand) stünde der eindeutigen Spalte im Weg.
        \DB::table('modules')->where('alias', $neu)->delete();
        \DB::table('modules')->where('alias', $alt)->update(array('alias' => $neu));
    }

    /**
     * `public/modules/<alt>` auf den neuen Ordner zeigen lassen.
     *
     * Nur eine Verknüpfung wird ersetzt. Ein echtes Verzeichnis dort hat
     * jemand von Hand angelegt, und das löschen wir nicht.
     */
    protected static function altenLinkUmbiegen($alt, $neuerOrdner)
    {
        $link = public_path('modules/'.$alt);
        $ziel = $neuerOrdner.'/Public';

        if (!is_dir($ziel) || (file_exists($link) && !is_link($link))) {
            return;
        }

        if (is_link($link)) {
            @unlink($link);
        }

        @symlink($ziel, $link);
    }

    /**
     * Welche umziehenden Module noch Aufträge in der Warteschlange haben.
     *
     * Im JSON der Nutzlast stehen Namensraumtrenner doppelt
     * (`Modules\\Backup\\Jobs`). Gesucht wird darum in PHP und nicht mit
     * LIKE, dessen Fluchtzeichen der Backslash selbst ist.
     *
     * @return array<int, string> Namen der betroffenen Module
     */
    protected static function wartendeAuftraege(array $liste)
    {
        try {
            $nutzlasten = \DB::table('jobs')->limit(2000)->pluck('payload')->all();
        } catch (\Throwable $e) {
            return array();
        }

        $belegt = array();

        foreach ($liste as $eintrag) {
            $muster = array('Modules\\\\'.$eintrag['name'].'\\\\', 'Modules\\'.$eintrag['name'].'\\');

            foreach ($nutzlasten as $nutzlast) {
                foreach ($muster as $m) {
                    if (strpos((string) $nutzlast, $m) !== false) {
                        $belegt[$eintrag['name']] = $eintrag['name'];
                        continue 3;
                    }
                }
            }
        }

        return array_values($belegt);
    }

    /** Modulliste des Kerns und seine Zeilen aus `modules` neu lesen lassen. */
    protected static function modullisteLeeren()
    {
        try {
            \App\Module::clearModulesCache();
            \Module::clearCache();
        } catch (\Throwable $e) {
            // Leert der Anmeldebefehl gleich noch einmal.
        }
    }

    protected static function ergebnis($status, array $module = array(), $fehler = null, array $offen = array())
    {
        return array('status' => $status, 'module' => $module, 'fehler' => $fehler, 'offen' => $offen);
    }
}

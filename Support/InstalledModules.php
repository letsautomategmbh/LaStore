<?php

namespace Modules\LaStore\Support;

use Modules\LaStore\Entities\CatalogEntry;
use Modules\LaStore\Entities\License;

/**
 * Bestandsaufnahme: was ist installiert, was davon steht im Katalog, was hat
 * eine Lizenz - und was traegt in seiner module.json noch einen Updateweg an
 * der Signatur vorbei.
 *
 * Der erste Fall, der beim Umstieg real eintritt: eine Installation, auf der
 * die Module schon laufen und sich bisher ueber ein eingebettetes Token
 * aktualisiert haben. Sie sollen ununterbrochen weiterlaufen; es aendert sich
 * nur, woher die Updates kommen.
 */
class InstalledModules
{
    const STATE_LICENSED = 'licensed';
    const STATE_ADOPTABLE = 'adoptable';
    const STATE_FOREIGN = 'foreign';
    const STATE_AVAILABLE = 'available';

    /**
     * Eine Zeile je Modul und je Katalogeintrag.
     *
     * @return array
     */
    /**
     * Die Detailseite eines Moduls im LaStore.
     *
     * Die Beschreibung, die Bildschirmbilder und das Aenderungsprotokoll
     * stehen dort - und nur dort. Eine zweite Fassung im Modul zu pflegen
     * hiess, zwei Wahrheiten zu haben; FreeScout verweist bei seinen eigenen
     * Modulen aus demselben Grund nach draussen ("Details ansehen").
     *
     * An einer Stelle und nicht in der Ansicht zusammengesetzt: sonst steht
     * die Pfadform an jeder Verwendung neu da, und eine Aenderung am Shop
     * findet nur die Haelfte.
     */
    public static function shopUrl($alias)
    {
        $basis = rtrim((string) config('lastore.shop_url'), '/');

        return $basis.'/produkt/'.rawurlencode((string) $alias);
    }

    public static function inventory()
    {
        $catalog = CatalogEntry::all()->keyBy('alias');
        $licenses = License::all()->keyBy('product_alias');
        $rows = array();

        // Umbenannte Module (bexio -> labexio): das installierte alte Modul
        // gehört zum Katalogeintrag mit dem neuen Kürzel. Ohne diese Karte
        // fände es keinen Eintrag mehr, fiele als «fremd» aus der Liste, und
        // daneben stünde dasselbe Produkt als «nicht installiert».
        $ersetzt = Umzug::ersetzungen();
        $vergeben = array();

        foreach (\Module::all() as $module) {
            $alias = $module->getAlias();

            if ($alias === 'lastore') {
                continue;
            }

            $neu = isset($ersetzt[$alias]) ? $ersetzt[$alias] : null;
            $entry = $catalog->get($neu ?: $alias);
            $license = $licenses->get($alias);

            if ($neu) {
                $vergeben[$neu] = true;
            }

            $rows[$alias] = array(
                'alias'       => $alias,
                // Der Name aus dem Katalog gewinnt: der Kunde kauft
                // "Invoicing", auf der Platte heisst das Modul intern
                // "Activities". Ohne Katalogeintrag bleibt der interne Name -
                // besser als gar keiner.
                'name'        => $entry ? $entry->value('name', $module->getName()) : $module->getName(),
                'local_name'  => $module->getName(),
                'installed'   => (string) $module->get('version'),
                'active'      => (bool) $module->active(),
                'available'   => $entry ? $entry->value('version') : null,
                'in_catalog'  => (bool) $entry,
                'license'     => $license,
                'unsigned_update' => self::unsignedUpdatePath($module),
                'state'       => self::state($entry, $license),
                // Das neue Kürzel, wenn dieses Modul noch umziehen muss.
                'umzug'       => $neu,
                /*
                 * Das Sinnbild aus der module.json des installierten Moduls -
                 * genau die Quelle, aus der FreeScout es auf seiner eigenen
                 * Seite nimmt (`$module->get('img')`). Es ist dort eine
                 * base64-Datenadresse, also gross (rund 80-100 kB je Modul),
                 * aber kein zusaetzlicher Abruf.
                 *
                 * Der Katalog nur als Rueckfall: sein Bild ist fuer den Laden
                 * gedacht und muss nicht dasselbe Format haben. Fuer ein
                 * Modul, das hier liegt, ist die module.json die naehere
                 * Wahrheit - und sie ist das, was der Kunde in FreeScouts
                 * Modulliste daneben sieht.
                 */
                'img'         => $module->get('img') ?: ($entry ? $entry->value('img') : null),
                // Nie null: der Katalog liefert `summary: null`, wo der Laden
                // keinen Text hat, und e(null) bricht unter PHP 8.5 die
                // ganze Seite ab. Dann die Beschreibung aus der module.json.
                'summary'     => (string) (($entry ? $entry->value('summary') : null) ?: $module->getDescription()),
                'details_url' => $entry ? self::shopUrl($alias) : null,
            );
        }

        // Katalogeintraege, die hier nicht installiert sind - damit die Liste
        // auch zeigt, was es noch gaebe.
        foreach ($catalog as $alias => $entry) {
            if (isset($rows[$alias]) || isset($vergeben[$alias])) {
                continue;
            }

            $rows[$alias] = array(
                'alias'       => $alias,
                'name'        => $entry->value('name', $alias),
                'installed'   => null,
                'active'      => false,
                'available'   => $entry->value('version'),
                'in_catalog'  => true,
                'license'     => $licenses->get($alias),
                'unsigned_update' => null,
                'state'       => self::STATE_AVAILABLE,
                'umzug'       => null,
                'img'         => $entry->value('img'),
                'summary'     => (string) $entry->value('summary'),
                'details_url' => self::shopUrl($alias),
            );
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * Die Zeilen, die unsere Seite wirklich zeigt.
     *
     * `inventory()` liefert auch Module, die hier zwar liegen, aber nichts
     * mit dem Laden zu tun haben (`STATE_FOREIGN`). Die Kachelliste
     * überspringt sie -- und die Zahl in der Seitenleiste muss durch
     * **denselben** Filter, sonst verspricht sie eine Kachel mehr, als es
     * gibt. Darum steht die Bedingung hier und nicht zweimal in den
     * Ansichten.
     */
    public static function sichtbar(array $inventory)
    {
        return array_values(array_filter($inventory, function ($zeile) {
            return $zeile['state'] !== self::STATE_FOREIGN;
        }));
    }

    /**
     * Wie viele Kacheln die Seite zeigt -- für die Klammer im Menü.
     *
     * Rechnet das ganze Inventar durch, obwohl nur eine Zahl herauskommt.
     * Das ist Absicht: eine zweite, billigere Zählung wäre eine zweite
     * Wahrheit, und die beiden liefen beim nächsten Umbau auseinander.
     */
    public static function sichtbareAnzahl()
    {
        return count(self::sichtbar(self::inventory()));
    }

    /**
     * Nur die, die uebernommen werden koennen: installiert, im Katalog, ohne
     * Lizenz.
     *
     * @return array
     */
    public static function adoptable()
    {
        $adoptable = array();

        foreach (self::inventory() as $row) {
            if ($row['state'] === self::STATE_ADOPTABLE) {
                $adoptable[] = $row;
            }
        }

        return $adoptable;
    }

    /**
     * Welche installierten Module noch einen Updateweg an der Signatur
     * vorbei in ihrer module.json tragen.
     *
     * Hier stand: "erst wenn alle Kunden umgestellt sind, darf das Token aus
     * den Repositories verschwinden". Das ist am 04.09.2026 geschehen --
     * latestVersionUrl und latestVersionZipUrl sind aus allen elf
     * Repositories entfernt.
     *
     * Damit ist diese Liste nicht ueberfluessig, sondern wechselt die
     * Richtung: sie zeigt nicht mehr, was WIR noch aufraeumen muessen,
     * sondern was auf DIESER Installation noch aus der Zeit davor liegt. Ein
     * Repository zu aendern aendert keine ausgelieferte Kopie. Nach dem
     * Erneuern des Tokens zeigen diese Felder auf einen toten Weg, und der
     * Verwalter erfaehrt es nur hier.
     *
     * @return array
     */
    public static function withUnsignedUpdate()
    {
        $found = array();

        foreach (self::inventory() as $row) {
            if ($row['unsigned_update']) {
                $found[] = $row;
            }
        }

        return $found;
    }

    protected static function state($entry, $license)
    {
        if ($license && self::lizenzGilt($license)) {
            return self::STATE_LICENSED;
        }

        /*
         * Ohne GUELTIGE Lizenz ist ein Modul, das im Katalog steht, wieder
         * "zu uebernehmen" - der Verwalter braucht ein Schluesselfeld.
         *
         * Vorher stand hier nur `if ($license)`: die bloosse EXISTENZ einer
         * Zeile genuegte. Eine abgelaufene Lizenz zeigte damit weiter das
         * gruene "Lizenziert" und keinen Knopf - waehrend daneben das
         * Ablaufdatum in Rot stand. Zwei Angaben, die sich widersprachen.
         */
        return $entry ? self::STATE_ADOPTABLE : self::STATE_FOREIGN;
    }

    /**
     * Gilt die Lizenz noch?
     *
     * `grace_until` ist das tatsaechliche Ende: bis dahin laeuft das Modul,
     * auch wenn `valid_until` schon vorbei ist (Gnadenfrist). Ist es
     * verstrichen, ist Schluss - und das sticht den gespiegelten Zustand,
     * denn der wird nur bei einer Pruefung neu geschrieben, waehrend ein
     * Datum von selbst verstreicht.
     *
     * Andernfalls zaehlt nur ein Zustand, der aus einer BESTANDENEN Pruefung
     * stammt: OK oder Gnadenfrist.
     *
     * Vorher stand hier die Umkehrung - gueltig, solange nicht ausdruecklich
     * "abgelaufen". Der Gedanke war, ein nie geprueftes Feld solle keine
     * bezahlte Lizenz entwerten. Nur ist der Vorgabewert der Spalte
     * "unknown", und die Beschriftungsliste nennt ihn "Nicht geprueft": eine
     * Zeile, die nie eine gueltige Signatur gesehen hat, bekam damit das
     * gruene "Lizenziert". Auch "Signatur ungueltig" und "Unbekannter
     * Schluessel" galten so als gueltig.
     *
     * Nicht geprueft ist nicht lizenziert. Und syncFrom() schreibt bei jeder
     * bestandenen Pruefung einen echten Status - eine Zeile bleibt also nur
     * dann auf "unknown", wenn es nie eine gab.
     */
    private static function lizenzGilt($license)
    {
        if ($license->grace_until !== null && $license->grace_until->isPast()) {
            return false;
        }

        return in_array($license->status, array(
            \Modules\LaStore\Support\LicenseToken::OK,
            \Modules\LaStore\Support\LicenseToken::GRACE,
        ), true);
    }

    /**
     * Ob die module.json eines installierten Moduls einen Updateweg AN DER
     * SIGNATUR VORBEI traegt -- und welcher Art.
     *
     * **Was hier vorher stand und warum es sich geaendert hat.** Diese
     * Methode hiess credentialKind() und suchte nach den Praefixen von
     * GitHub-Token. Der Anlass: in elf unserer module.json standen
     * latestVersionUrl und latestVersionZipUrl, und das Zugangstoken steckte
     * IN der Adresse. Am 04.09.2026 sind beide Felder aus allen Repositories
     * entfernt.
     *
     * Gemeldet wird seitdem der Updateweg selbst, nicht das Token darin. Drei
     * Gruende, in dieser Reihenfolge:
     *
     * 1. **Es ist die richtige Sache.** Gefaehrlich ist nicht das Token,
     *    sondern dass FreeScouts eingebauter Updater ueber diese Felder
     *    UNGEPRUEFT nach Modules/ entpackt -- ohne Signatur, ohne Pruefsumme.
     *    Ein Feld ohne Token ist derselbe Weg, nur unbenutzbar.
     * 2. Der Befund bleibt nuetzlich, gerade jetzt. In einer Installation,
     *    die vor dem 04.09.2026 eingerichtet wurde, stehen die Felder
     *    weiterhin -- unsere Repositories zu aendern aendert keine
     *    ausgelieferte Kopie. Nach dem Erneuern des Tokens zeigen sie auf
     *    einen toten Weg, und diese Liste ist das Einzige, was das sagt.
     * 3. Und nebenbei: ohne die Praefixe im Quelltext laesst sich das Modul
     *    ueberhaupt ausliefern. Die Eingangspruefung des Shops sucht in jeder
     *    Datei nach genau diesen Zeichenfolgen und hat 1.2.0 abgelehnt --
     *    wegen dieser Methode. Ein Sucher, der sein Suchwort woertlich
     *    enthaelt, findet immer sich selbst. Das ist der schwaechste der drei
     *    Gruende und stand am Anfang; ohne die beiden anderen waere es keine
     *    Aenderung wert gewesen, sondern ein Ausweichen.
     *
     * Nie der Wert selbst, nur die Art -- eine Adresse mit Zugangsdaten
     * darin gehoert nicht in Protokolle und Bildschirmfotos.
     *
     * @return string|null
     */
    protected static function unsignedUpdatePath($module)
    {
        $path = $module->getPath().'/module.json';

        if (!is_file($path)) {
            return null;
        }

        $raw = (string) file_get_contents($path);
        $json = json_decode($raw, true);

        if (is_array($json)) {
            foreach (array('latestVersionUrl', 'latestVersionZipUrl') as $feld) {
                if (!empty($json[$feld])) {
                    // Traegt die Adresse Zugangsdaten, ist das die schaerfere
                    // Auskunft -- und sie sagt dem Verwalter, dass beim
                    // Aufraeumen ein Geheimnis mitgeht.
                    return preg_match('#://[^/@\s"]+@#', (string) $json[$feld])
                        ? 'Fremder Updateweg, mit Zugangsdaten'
                        : 'Fremder Updateweg';
                }
            }
        }

        if (preg_match('#://[^/@\s"]+@#', $raw)) {
            return 'Zugangsdaten in der URL';
        }

        return null;
    }

    /**
     * @return array
     */
    public static function stateLabels()
    {
        return array(
            self::STATE_LICENSED  => array('success', 'Lizenziert'),
            self::STATE_ADOPTABLE => array('warning', 'Zu übernehmen'),
            self::STATE_AVAILABLE => array('default', 'Nicht installiert'),
            self::STATE_FOREIGN   => array('default', 'Nicht aus dem Store'),
        );
    }
}

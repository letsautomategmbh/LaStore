<?php

namespace Modules\LaStore\Services;

use Modules\LaStore\Support\PackageVerifier;
use Modules\LaStore\Support\Text;

/**
 * Holt ein Paket, prueft es und installiert es.
 *
 * Die Reihenfolge ist die ganze Sicherheit dieser Klasse:
 *
 *     herunterladen -> hashen -> Signatur pruefen -> ENTPACKEN
 *
 * Wer entpackt, bevor er geprueft hat, hat den Code schon auf der Platte.
 * Deshalb passiert das Entpacken in einem Wegwerfverzeichnis und der Tausch
 * erst danach, und deshalb wird der alte Stand vorher beiseitegelegt.
 *
 * Was hier NICHT passiert: Migrationen, Cache leeren, Modul aktivieren. Das
 * ist Sache des Aufrufers, und zwar ueber Support\ModulAnmeldung.
 *
 * Hier stand einmal, FreeScout mache das "selbst beim naechsten Aufruf". Das
 * war falsch, und der Irrtum hielt, weil er nie nachgesehen wurde: der Kern
 * ruft module:migrate ausschliesslich in freescout:module-install, und das
 * laeuft beim AKTIVIEREN. Ein Update tauscht Dateien und sonst nichts --
 * auch FreeScouts eigenes. Am 04.09.2026 an der Produktion aufgefallen, wo
 * eine Wanderung nach zwei Selbstaktualisierungen ungelaufen dalag.
 */
class PackageInstaller
{
    /** Grenze fuer den Download. Ein Modul, das groesser ist, ist ein Fehler. */
    const MAX_BYTES = 104857600; // 100 MiB

    /**
     * Wieviele beiseitegelegte Staende je Modul stehen bleiben.
     *
     * Fuenf, nicht einer: wer nach einem Update etwas vermisst, merkt es
     * nicht immer am selben Tag, und der naechtliche Autopilot schiebt
     * inzwischen weitere Fassungen nach. Fuenf reichen ueber ein Wochenende
     * hinweg und kosten bei einem Modul von zwei Megabyte zehn.
     *
     * Aufgeraeumt wurde bisher gar nicht. Auf unserer eigenen Anlage lagen am
     * 22.09.2026 siebenundvierzig Sicherungen aus sechs Wochen -- 66 MB, die
     * niemand je wieder angesehen haette.
     */
    const SICHERUNGEN_BEHALTEN = 5;

    /** @var StoreClient */
    protected $client;

    public function __construct(?StoreClient $client = null)
    {
        $this->client = $client ?: new StoreClient();
    }

    /**
     * Was der Shop zur neuesten Version sagt.
     *
     * @param string $alias
     *
     * @return array
     */
    public function available($alias)
    {
        $kanal = config('lastore.update_channel', 'stable');

        return $this->client->latestPackage($alias, $kanal);
    }

    /**
     * Herunterladen und pruefen. Gibt den Pfad der GEPRUEFTEN Datei zurueck.
     *
     * @param array $meldung Antwort von available()
     *
     * @return string
     *
     * @throws StoreException
     */
    public function fetchVerified(array $meldung)
    {
        foreach (array('download', 'sha256', 'signature', 'signing_kid', 'version') as $feld) {
            if (empty($meldung[$feld])) {
                throw new StoreException(
                    Text::get('Die Antwort des Shops ist unvollständig — :feld fehlt. Es wird nichts installiert.', ['feld' => $feld]),
                    'incomplete_response'
                );
            }
        }

        $ziel = $this->tempFile('lastore-paket-', '.zip');

        try {
            $client = new \GuzzleHttp\Client(\Helper::setGuzzleDefaultOptions());
            $antwort = $client->request('GET', $meldung['download'], \Helper::setGuzzleDefaultOptions(array(
                'connect_timeout' => 15,
                // Ein Paket darf laenger brauchen als eine Abfrage.
                'timeout'         => 300,
                'http_errors'     => false,
                // sink statt in den Speicher: ein 40-MB-Modul im
                // memory_limit einer FreeScout-Installation ist ein
                // Fehlschlag, der nach "Server kaputt" aussieht.
                'sink'            => $ziel,
            )));
        } catch (\Exception $e) {
            @unlink($ziel);
            throw new StoreException($e->getMessage(), 'download_failed');
        }

        $code = (int) $antwort->getStatusCode();

        if ($code !== 200) {
            @unlink($ziel);
            throw new StoreException(
                Text::get('Der Download wurde mit :code abgewiesen. Die Adresse ist einmalig und nur 15 Minuten gültig — bitte erneut versuchen.', ['code' => $code]),
                'download_rejected'
            );
        }

        $groesse = @filesize($ziel);

        if ($groesse === false || $groesse === 0) {
            @unlink($ziel);
            throw new StoreException(Text::get('Die heruntergeladene Datei ist leer.'), 'download_empty');
        }

        if ($groesse > self::MAX_BYTES) {
            @unlink($ziel);
            throw new StoreException(Text::get('Die heruntergeladene Datei ist unerwartet gross und wird verworfen.'), 'download_too_large');
        }

        $pruefung = PackageVerifier::check($ziel, $meldung['sha256'], $meldung['signature'], $meldung['signing_kid']);

        if (!$pruefung->passed()) {
            // Die Datei wird SOFORT geloescht. Ein nicht bestandenes Paket
            // soll nicht im temporaeren Verzeichnis liegen bleiben, wo es
            // jemand von Hand entpackt.
            @unlink($ziel);

            throw new StoreException($pruefung->explain(), 'verification_failed:'.$pruefung->status());
        }

        return $ziel;
    }

    /**
     * Das geprueefte Paket installieren.
     *
     * @param string $zip     Pfad zur geprueften Datei
     * @param string $alias   erwarteter Modul-Alias
     *
     * @return array{name: string, version: string, backup: string|null}
     *
     * @throws StoreException
     */
    public function install($zip, $alias)
    {
        if (!class_exists('\ZipArchive')) {
            throw new StoreException(
                Text::get('Auf diesem Server fehlt die PHP-Erweiterung zip. Das Paket lässt sich nicht entpacken.'),
                'no_zip'
            );
        }

        $this->alteArbeitWegraeumen();

        $auspack = $this->arbeitsDir('.lastore-auspack-');

        try {
            $ordner = $this->entpacken($zip, $auspack);
            $json = $this->modulJson($auspack.'/'.$ordner);

            // Der Alias im Paket muss der sein, den wir angefordert haben.
            // Sonst installiert eine Antwort fuer "backup" das Modul "vault" -
            // signiert waere das trotzdem, denn beide sind von uns.
            $imPaket = isset($json['alias']) ? strtolower($json['alias']) : '';

            if ($imPaket !== strtolower($alias)) {
                throw new StoreException(
                    Text::get('Das Paket enthält :gefunden, angefordert war :erwartet. Es wird nicht installiert.',
                        array('gefunden' => $imPaket ?: '—', 'erwartet' => $alias)),
                    'alias_mismatch'
                );
            }

            $name = isset($json['name']) ? $json['name'] : $ordner;
            $ziel = $this->modulesPath().'/'.$name;

            $sicherung = $this->beiseite($ziel);

            if (!@rename($auspack.'/'.$ordner, $ziel)) {
                // Zurueck auf den alten Stand. Ein Modulverzeichnis, das
                // waehrend eines Updates verschwindet, nimmt FreeScout mit.
                $this->zurueck($sicherung, $ziel);

                throw new StoreException(
                    Text::get('Das Modulverzeichnis liess sich nicht ersetzen. Der bisherige Stand ist wiederhergestellt.'),
                    'swap_failed'
                );
            }

            $this->raeumeAlteSicherungen($ziel);

            return array(
                'name'    => $name,
                'version' => isset($json['version']) ? $json['version'] : '',
                'backup'  => $sicherung,
            );
        } finally {
            $this->loeschen($auspack);
            @unlink($zip);
        }
    }

    // ───────────────────────────────────────────────────────── innere Teile

    /**
     * Entpackt und gibt den Namen des obersten Ordners zurueck.
     *
     * @return string
     */
    protected function entpacken($zip, $nach)
    {
        $archiv = new \ZipArchive();

        if ($archiv->open($zip) !== true) {
            throw new StoreException(Text::get('Das Archiv liess sich nicht öffnen.'), 'zip_open_failed');
        }

        $oberste = array();

        for ($i = 0; $i < $archiv->numFiles; $i++) {
            $eintrag = $archiv->getNameIndex($i);

            // Zip-Slip: ein Eintrag wie "../../config/app.php" schreibt
            // ausserhalb des Zielverzeichnisses. Die Signatur schuetzt davor
            // NICHT - sie sagt nur, dass das Archiv von uns ist, nicht dass
            // es harmlos ist. Ein Fehler unsererseits soll nicht ausserhalb
            // des Modulordners landen.
            if ($eintrag === '' || $eintrag[0] === '/' || strpos($eintrag, '..') !== false || strpos($eintrag, "\0") !== false) {
                $archiv->close();
                throw new StoreException(
                    Text::get('Das Archiv enthält einen unerlaubten Pfad (:pfad) und wird verworfen.', array('pfad' => $eintrag)),
                    'unsafe_path'
                );
            }

            $teile = explode('/', $eintrag);
            $oberste[$teile[0]] = true;
        }

        if (count($oberste) !== 1) {
            $archiv->close();
            throw new StoreException(
                Text::get('Das Archiv hat :n oberste Ordner, erwartet ist genau einer.', array('n' => count($oberste))),
                'bad_layout'
            );
        }

        if (!$archiv->extractTo($nach)) {
            $archiv->close();
            throw new StoreException(Text::get('Das Archiv liess sich nicht entpacken.'), 'extract_failed');
        }

        $archiv->close();

        return key($oberste);
    }

    /** @return array */
    protected function modulJson($ordner)
    {
        $pfad = $ordner.'/module.json';

        if (!is_file($pfad)) {
            throw new StoreException(Text::get('Im Paket fehlt module.json.'), 'no_module_json');
        }

        $json = json_decode(file_get_contents($pfad), true);

        if (!is_array($json)) {
            throw new StoreException(Text::get('Die module.json im Paket ist unlesbar.'), 'bad_module_json');
        }

        return $json;
    }

    /**
     * Den bisherigen Stand beiseitelegen, nicht loeschen.
     *
     * Mit einem PUNKT davor, und das ist der Punkt: hier hiess die Sicherung
     * "Backup.vorher-20260908-083834" -- und darin liegt eine module.json mit
     * Namen "Backup". FreeScout findet Module ueber ein glob-Muster mit
     * Stern, und ein Stern nimmt jeden Namen ohne fuehrenden Punkt: die
     * Sicherung war damit ein auffindbares Modul, das sich als der ALTE Stand
     * desselben Moduls ausgibt.
     *
     * Nachgemessen auf unserer Anlage: geladen wurde trotzdem das richtige
     * Verzeichnis, weil die Sammlung nach Namen schluesselt und der Konflikt
     * zugunsten des echten ausging. Das ist aber Glueck und keine Zusage --
     * eine andere Reihenfolge von glob, und es laeuft der alte Code, waehrend
     * die Fassungsnummer die neue nennt. Genau diese Sorte Widerspruch hat
     * hier schon zweimal eine Fehlsuche verursacht.
     *
     * Der SelfUpdater legt seinen alten Stand seit je punktbenannt ab. Es war
     * nur diese Stelle, die es anders machte.
     *
     * @return string|null Pfad der Sicherung
     */
    protected function beiseite($ziel)
    {
        if (!is_dir($ziel)) {
            return null;
        }

        $sicherung = dirname($ziel).'/.'.basename($ziel).'.vorher-'.date('Ymd-His');

        if (!@rename($ziel, $sicherung)) {
            throw new StoreException(
                Text::get('Der bisherige Stand liess sich nicht beiseitelegen. Es wird nichts überschrieben.'),
                'backup_failed'
            );
        }

        return $sicherung;
    }

    /**
     * Alte beiseitegelegte Staende wegraeumen -- die juengsten behalten.
     *
     * Der Name traegt den Zeitstempel (.Modul.vorher-JJJJMMTT-HHMMSS), und
     * der ist so gebaut, dass alphabetisch sortieren nach Alter sortiert.
     * Deshalb genuegt sort(); ein filemtime je Verzeichnis waere langsamer
     * und bei einem kopierten Stand sogar falsch.
     *
     * Schlaegt das Wegraeumen fehl, passiert nichts weiter: das Update ist an
     * dieser Stelle schon durch, und eine liegengebliebene Sicherung ist
     * kein Grund, es scheitern zu lassen.
     */
    protected function raeumeAlteSicherungen($ziel)
    {
        $staende = (array) @glob(dirname($ziel).'/.'.basename($ziel).'.vorher-*', GLOB_ONLYDIR);
        sort($staende);

        $zuviel = count($staende) - self::SICHERUNGEN_BEHALTEN;

        for ($i = 0; $i < $zuviel; $i++) {
            $this->loeschen($staende[$i]);
        }
    }

    protected function zurueck($sicherung, $ziel)
    {
        if ($sicherung !== null && is_dir($sicherung) && !is_dir($ziel)) {
            @rename($sicherung, $ziel);
        }
    }

    /** @return string */
    protected function modulesPath()
    {
        $pfad = config('modules.paths.modules');

        return $pfad ? rtrim($pfad, '/') : base_path('Modules');
    }

    protected function tempFile($prefix, $suffix)
    {
        $pfad = tempnam(sys_get_temp_dir(), $prefix);
        @unlink($pfad);

        return $pfad.$suffix;
    }

    /**
     * Ein Arbeitsverzeichnis NEBEN dem Ziel, nicht in /tmp.
     *
     * Der Tausch am Ende ist ein rename(), und rename() kann nicht ueber
     * Dateisystemgrenzen. Hier stand sys_get_temp_dir(), und auf unserem
     * eigenen Host ist das eine solche Grenze: CloudLinux gibt dem PHP-
     * Prozess ein eigenes /tmp, das nicht auf dem Datentraeger des Kontos
     * liegt. Die Folge war ein Update, das NIE durchlief -- Paket geholt,
     * Signatur geprueft, dann "Das Modulverzeichnis liess sich nicht
     * ersetzen". Auf der Entwicklungsmaschine lag beides auf einem
     * Datentraeger, dort fiel es nie auf.
     *
     * Ein Pfad IM Modulverzeichnis kann diese Grenze nicht haben -- das ist
     * eine Zusicherung des Aufbaus und nicht eine Eigenschaft des Hosts.
     * Der SelfUpdater macht es seit je so (storage/app) und lief deshalb.
     *
     * Der fuehrende Punkt ist Absicht: FreeScout findet Module ueber ein
     * glob-Muster, das im Modulverzeichnis mit einem Stern nach Unterordnern
     * mit module.json sucht -- und ein Stern uebergeht in glob jeden Namen,
     * der mit einem Punkt beginnt. Das halbfertige Verzeichnis ist fuer
     * FreeScout also unsichtbar, solange es dort liegt.
     *
     * @return string
     */
    protected function arbeitsDir($prefix)
    {
        $pfad = $this->modulesPath().'/'.$prefix.bin2hex(random_bytes(6));

        if (!@mkdir($pfad, 0700, true) && !is_dir($pfad)) {
            throw new StoreException(
                Text::get('Im Modulverzeichnis liess sich kein Arbeitsverzeichnis anlegen. Fehlen dort Schreibrechte?'),
                'no_temp_dir'
            );
        }

        return $pfad;
    }

    /**
     * Liegengebliebene Arbeitsverzeichnisse entfernen.
     *
     * Bricht der Vorgang hart ab -- Zeitlimit, Speicher, Neustart --, bleibt
     * das halbfertige Verzeichnis liegen. Unsichtbar zwar, aber es belegt
     * Platz, und beim naechsten Mal soll niemand raten muessen, welches
     * davon von jetzt ist. Eine Stunde Abstand, damit ein PARALLEL laufender
     * Vorgang seines behaelt.
     */
    protected function alteArbeitWegraeumen()
    {
        $muster = $this->modulesPath().'/.lastore-auspack-*';

        foreach ((array) glob($muster, GLOB_ONLYDIR) as $pfad) {
            if (@filemtime($pfad) < time() - 3600) {
                $this->loeschen($pfad);
            }
        }
    }

    protected function loeschen($pfad)
    {
        if (!is_dir($pfad)) {
            @unlink($pfad);

            return;
        }

        foreach (scandir($pfad) as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $this->loeschen($pfad.'/'.$eintrag);
        }

        @rmdir($pfad);
    }
}

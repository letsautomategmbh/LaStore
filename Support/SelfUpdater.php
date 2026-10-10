<?php

namespace Modules\LaStore\Support;

use Modules\LaStore\Services\StoreClient;
use Modules\LaStore\Services\StoreException;

/**
 * LaStore aktualisiert sich selbst.
 *
 * Warum es das gibt, obwohl ein Modul, das sich selbst ersetzt, heikel ist:
 * dieses Modul prueft jede andere Signatur. Bleibt es stehen, bleibt alles
 * stehen -- nach einem Schluesselwechsel lehnt es jedes Paket ab, und die
 * Meldung dazu ("LaStore selbst braucht ein Update") waere eine Sackgasse.
 * Ein Weg von Hand ist kein Weg: er wird einmal gegangen und dann nie wieder.
 *
 * NICHT ueber FreeScouts eigenen Updater. Der laedt und entpackt ungeprueft
 * direkt nach Modules/ -- fuer das Stueck, das die Vertrauenswurzel traegt,
 * ist das die falsche Reihenfolge. Hier gilt:
 *
 *     Signatur pruefen -> laden -> hashen -> pruefen -> auspacken -> tauschen
 *
 * Die Signatur zuerst, ohne eine Datei: sie liegt ueber dem Hash, und den
 * nennt der Server in seiner Antwort. Eine gefaelschte Antwort scheitert
 * damit, BEVOR irgendetwas von der Adresse geholt wird, die in derselben
 * Antwort steht.
 *
 * Der Tausch sind zwei Umbenennungen im selben Dateisystem, nicht ein
 * Kopieren ueber den laufenden Stand. Nach der ersten liegt der alte Ordner
 * beiseite, nach der zweiten der neue am Platz; scheitert die zweite, wird
 * die erste zurueckgenommen. Ein halb ausgepacktes Modul gibt es damit nicht.
 *
 * ACHTUNG in Entwicklungsumgebungen: der Tausch ersetzt den GANZEN Ordner.
 * Wo Modules/LaStore eine Git-Arbeitskopie ist -- also bei uns --, waere .git
 * danach weg, samt allem, was nicht committet war. Darum bricht tauschen() ab,
 * wenn es ein .git findet: dort gehoert kein Selbstupdate hin, dort wird
 * entwickelt.
 *
 * WICHTIG fuer jeden, der hier etwas ergaenzt: nach dem Tausch darf keine
 * Klasse mehr NEU geladen werden. Die Dateien liegen dann woanders, und der
 * Autoloader findet nichts. Alles nach dem Tausch ist darum nur noch
 * Dateiarbeit mit eingebauten Funktionen.
 */
class SelfUpdater
{
    /** Der Ordner des Moduls in Modules/ -- gleich dem Namensraum. */
    const ORDNER = 'LaStore';

    /**
     * Der Alias aus module.json -- klein, und NICHT der Modulname.
     *
     * Der Name ist "LaStore", der Ordner "LaStore", der Alias "lastore". Drei
     * verschiedene Zeichenketten fuer dasselbe Modul, und jede Kern-Funktion
     * will eine andere davon. Genau daran ist \Module::find() nach der
     * Umbenennung gescheitert. Darum steht der Alias hier EINMAL.
     */
    const ALIAS = 'lastore';

    /** Grenze fuer den Download. Dieses Modul ist klein; 30 MiB sind schon viel. */
    const MAX_BYTES = 31457280;

    /**
     * Wo der Befund liegt, damit die Seite ihn OHNE Netz zeigen kann.
     *
     * Das Modul prueft Lizenztoken oertlich und faellt im Seitenaufbau in
     * keinen HTTP-Aufruf -- das soll der Hinweis auf eine neue Fassung nicht
     * durchbrechen. Der naechtliche Lauf schreibt hier hinein, die Seite
     * liest nur.
     */
    const MERKER = 'lastore.selbst_verfuegbar';

    /**
     * Die Notiz zur neuen Fassung, in der Sprache der Installation.
     *
     * Ein eigener Schluessel und nicht JSON im MERKER: der MERKER wird
     * verglichen (version_compare), und ein Feld, das zwei Dinge traegt,
     * verliert genau dann eines davon, wenn man es am dringendsten braucht.
     */
    const MERKER_NOTIZ = 'lastore.selbst_notiz';

    const AKTUELL = 'aktuell';
    const VERFUEGBAR = 'verfuegbar';
    const FERTIG = 'fertig';

    /**
     * Was der Server anbietet, und ob es neuer ist.
     *
     * Reine Auskunft: es wird nichts geladen und nichts getauscht. Die
     * Oberflaeche und der naechtliche Lauf benutzen das, um zu MELDEN --
     * getauscht wird nur auf Anweisung oder mit eingeschaltetem Autopilot.
     *
     * @return array{status:string, version:string, jetzt:string, meldung:array}
     */
    public static function pruefen(?StoreClient $client = null)
    {
        $client = $client ?: new StoreClient();
        $meldung = $client->clientLatest(config('lastore.update_channel', 'stable'));

        self::verlangeFelder($meldung);

        $jetzt = StoreClient::clientVersion();
        $neu = (string) $meldung['version'];

        $status = ($jetzt !== '' && version_compare($neu, $jetzt, '<=')) ? self::AKTUELL : self::VERFUEGBAR;

        // Den Befund hinterlegen, damit die Oberflaeche ihn ohne eigenen
        // Aufruf kennt. Leer heisst: nichts Neues.
        \Option::set(self::MERKER, $status === self::VERFUEGBAR ? $neu : '');

        // Die Notiz gleich mit: sie kommt aus derselben Antwort, und die
        // Seite darf dafuer nicht ein zweites Mal nach draussen.
        $notiz = isset($meldung['changelog']) ? $meldung['changelog'] : '';
        \Option::set(self::MERKER_NOTIZ, ($status === self::VERFUEGBAR && is_string($notiz)) ? $notiz : '');

        return [
            'status'  => $status,
            'version' => $neu,
            'jetzt'   => $jetzt,
            'meldung' => $meldung,
        ];
    }

    /**
     * Pruefen, holen, tauschen.
     *
     * @return array{status:string, version:string, jetzt:string, alt:?string}
     *
     * @throws StoreException
     */
    public static function aktualisieren(?StoreClient $client = null, $modules = null)
    {
        $bericht = self::pruefen($client);

        if ($bericht['status'] === self::AKTUELL) {
            return $bericht + ['alt' => null];
        }

        $meldung = $bericht['meldung'];

        // ── 1. Signatur, ohne eine Datei ──────────────────────────────
        $sig = PackageVerifier::checkSignature(
            $meldung['sha256'],
            $meldung['signature'],
            $meldung['signing_kid']
        );

        if (!$sig->passed()) {
            throw new StoreException($sig->explain(), 'signature_failed:'.$sig->status());
        }

        // ── 2. Laden ──────────────────────────────────────────────────
        $zip = self::laden($meldung['download']);

        try {
            // ── 3. Hash gegen den angekuendigten ──────────────────────
            $ist = hash_file('sha256', $zip);

            if (!is_string($ist) || !hash_equals(strtolower((string) $meldung['sha256']), strtolower($ist))) {
                throw new StoreException(
                    Text::get('Die geladene Datei hat nicht die angekündigte Prüfsumme. Es wird nichts getauscht.'),
                    'hash_mismatch'
                );
            }

            // ── 4. Auspacken, NEBEN das laufende Modul ────────────────
            $bau = self::auspacken($zip, (string) $meldung['version']);
        } finally {
            @unlink($zip);
        }

        // Diese Klasse wird erst NACH dem Tausch gebraucht -- und die Regel
        // oben sagt, dass dann keine mehr geladen werden darf. Also jetzt.
        // (Sie liegt danach zwar wieder am selben Pfad, aber sich darauf zu
        // verlassen heisst, den einen Fall zu verlieren, in dem der neue
        // Stand sie nicht mitbringt.)
        class_exists(ModulAnmeldung::class);

        try {
            // ── 5. Nachsehen, ob das Ausgepackte plausibel ist ────────
            self::pruefeBaum($bau, (string) $meldung['version']);

            // ── 6. Tauschen ───────────────────────────────────────────
            $alt = self::tauschen($bau, $modules);
        } catch (\Exception $e) {
            self::wegwerfen($bau);

            throw $e;
        }

        // ── 7. Zwischenspeicher raeumen, damit der neue Stand greift ──
        // Nur noch Dateiarbeit: die Klassen dieses Moduls liegen jetzt
        // woanders.
        // Der Merker VOR dem Raeumen: danach wird nichts mehr geladen, und
        // Option::set braucht die Datenbankschicht, die jetzt noch steht.
        \Option::set(self::MERKER, '');
        \Option::set(self::MERKER_NOTIZ, '');

        self::raeumeZwischenspeicher();

        // ── 8. Beim Kern anmelden: Wanderungen, Symlink, Cache ────────
        // Dateien tauschen genuegt NICHT -- weder bei uns noch bei
        // FreeScouts eigenem Knopf. Die Belege stehen in ModulAnmeldung.
        // Nach dem Raeumen, damit der Befehl die neue module.json sieht.
        $anmeldung = ModulAnmeldung::anmelden(self::ALIAS);

        self::raeumeAlteStaende($alt);

        return [
            'status'    => self::FERTIG,
            'version'   => (string) $meldung['version'],
            'jetzt'     => $bericht['jetzt'],
            'alt'       => $alt,
            'anmeldung' => $anmeldung,
        ];
    }

    /**
     * Was zuletzt gefunden wurde -- ohne Netz.
     *
     * @return string Fassung oder leer
     */
    public static function hinterlegt()
    {
        $wert = (string) \Option::get(self::MERKER, '');

        if ($wert === '') {
            return '';
        }

        // Der Merker kann veraltet sein: wer von Hand aktualisiert hat, hat
        // ihn nicht geloescht. Darum jedes Mal gegen die laufende Fassung
        // pruefen, statt ihn zu glauben.
        $jetzt = StoreClient::clientVersion();

        return ($jetzt !== '' && version_compare($wert, $jetzt, '<=')) ? '' : $wert;
    }

    /**
     * Die hinterlegte Notiz -- nur wenn auch eine Fassung bereitliegt.
     *
     * @return string
     */
    public static function hinterlegteNotiz()
    {
        return self::hinterlegt() === '' ? '' : (string) \Option::get(self::MERKER_NOTIZ, '');
    }

    /**
     * Der Befehl von Hand, falls das Anmelden scheitert.
     *
     * @return string
     */
    public static function anmeldebefehl()
    {
        return ModulAnmeldung::befehl(self::ALIAS);
    }

    /** @return void */
    private static function verlangeFelder(array $meldung)
    {
        foreach (['version', 'sha256', 'signature', 'signing_kid', 'download'] as $feld) {
            if (empty($meldung[$feld])) {
                throw new StoreException(
                    Text::get('Die Antwort des Shops ist unvollständig — :feld fehlt. Es wird nichts getauscht.', ['feld' => $feld]),
                    'incomplete_response'
                );
            }
        }
    }

    /** @return string Pfad der geladenen Datei */
    private static function laden($url)
    {
        $ziel = tempnam(sys_get_temp_dir(), 'lashop-');

        try {
            $http = new \GuzzleHttp\Client(\Helper::setGuzzleDefaultOptions());
            $antwort = $http->request('GET', $url, \Helper::setGuzzleDefaultOptions([
                'connect_timeout' => 15,
                'timeout'         => 300,
                'http_errors'     => false,
                'sink'            => $ziel,
            ]));
        } catch (\Exception $e) {
            @unlink($ziel);

            throw new StoreException($e->getMessage(), 'download_failed');
        }

        $code = (int) $antwort->getStatusCode();

        if ($code !== 200) {
            @unlink($ziel);

            throw new StoreException(
                Text::get('Der Download wurde mit :code abgewiesen.', ['code' => $code]),
                'download_rejected'
            );
        }

        $groesse = @filesize($ziel);

        if ($groesse === false || $groesse === 0) {
            @unlink($ziel);

            throw new StoreException(Text::get('Die geladene Datei ist leer.'), 'download_empty');
        }

        if ($groesse > self::MAX_BYTES) {
            @unlink($ziel);

            throw new StoreException(Text::get('Die geladene Datei ist unerwartet gross und wird verworfen.'), 'download_too_large');
        }

        return $ziel;
    }

    /**
     * In ein Wegwerfverzeichnis auspacken, NICHT nach Modules/.
     *
     * Ein Ordner unter Modules/ wird von FreeScout beim naechsten Aufruf als
     * Modul gelesen -- ein halb ausgepackter waere dann ein halbes Modul.
     * Darum storage/app, und der Tausch erst danach: derselbe Datentraeger,
     * also ist die Umbenennung eine Umbenennung und kein Kopieren.
     *
     * @return string Pfad des Wegwerfverzeichnisses
     */
    private static function auspacken($zip, $version)
    {
        $bau = storage_path('app/lashop-neu-'.preg_replace('/[^0-9A-Za-z.\-]/', '', $version).'-'.substr(md5(uniqid('', true)), 0, 8));

        if (!@mkdir($bau, 0755, true) && !is_dir($bau)) {
            throw new StoreException(Text::get('Das Verzeichnis für die neue Fassung lässt sich nicht anlegen.'), 'staging_failed');
        }

        $archiv = new \ZipArchive();

        if ($archiv->open($zip) !== true) {
            self::wegwerfen($bau);

            throw new StoreException(Text::get('Das Archiv lässt sich nicht öffnen.'), 'zip_open_failed');
        }

        $ok = $archiv->extractTo($bau);
        $archiv->close();

        if (!$ok) {
            self::wegwerfen($bau);

            throw new StoreException(Text::get('Das Archiv lässt sich nicht auspacken.'), 'zip_extract_failed');
        }

        return $bau;
    }

    /**
     * Der eine oberste Ordner im ausgepackten Archiv.
     *
     * Hier stand $bau.'/'.self::ORDNER, also die Annahme, das Archiv trage
     * den Namen unseres Verzeichnisses. Das tut es NICHT: der Shop benennt
     * den obersten Ordner beim Einliefern auf das Feld "name" aus der
     * module.json um -- und das ist bei uns "LaStore", waehrend das
     * Verzeichnis "LaStore" heisst. Ergebnis war "Im Archiv fehlt der Ordner
     * LaStore" bei einem Archiv, das voellig in Ordnung war; LaStore konnte
     * sich also nicht selbst aktualisieren, seit der Shop normalisiert.
     *
     * Der Name im Archiv ist auch die falsche Frage. Wohin getauscht wird,
     * weiss dieses Modul aus sich selbst -- es laeuft aus diesem
     * Verzeichnis. Was im Archiv steckt, wird ueber module.json geprueft:
     * Kuerzel und Fassung sagen mehr als ein Ordnername.
     *
     * Genau EIN Ordner muss es sein. Zwei bedeuten ein Archiv, das etwas
     * anderes ist als ein Modul, und dann ist Raten das Falscheste.
     *
     * @return string
     */
    private static function imArchiv($bau)
    {
        $ordner = array();

        foreach ((array) scandir($bau) as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..' || $eintrag === '__MACOSX') {
                continue;
            }

            if (is_dir($bau.'/'.$eintrag)) {
                $ordner[] = $eintrag;
            }
        }

        if (count($ordner) !== 1) {
            throw new StoreException(
                Text::get('Das Archiv hat nicht genau einen obersten Ordner, sondern :anzahl.', ['anzahl' => count($ordner)]),
                'bad_package_shape'
            );
        }

        return $bau.'/'.$ordner[0];
    }

    /**
     * Ist das Ausgepackte wirklich dieses Modul in dieser Fassung?
     *
     * Der Sinn ist nicht Misstrauen gegen die Signatur -- die ist geprueft.
     * Der Sinn ist, einen Irrtum zu fangen: ein Paket mit falschem Kuerzel,
     * ein Archiv ohne obersten Ordner, eine Fassung, die nicht die
     * angekuendigte ist. Alles drei waere nach dem Tausch nicht mehr zu
     * bemerken, weil dann die Oberflaeche fehlt, mit der man es merken
     * wuerde.
     *
     * @return void
     */
    private static function pruefeBaum($bau, $version)
    {
        $ordner = self::imArchiv($bau);

        $json = $ordner.'/module.json';

        if (!is_file($json)) {
            throw new StoreException(Text::get('Im Archiv fehlt die module.json.'), 'bad_package_shape');
        }

        $daten = json_decode((string) file_get_contents($json), true);

        if (!is_array($daten)) {
            throw new StoreException(Text::get('Die module.json im Archiv ist kein gültiges JSON.'), 'bad_package_shape');
        }

        $alias = isset($daten['alias']) ? (string) $daten['alias'] : '';

        if ($alias !== self::ALIAS) {
            throw new StoreException(
                Text::get('Das Archiv gehört zu :alias und nicht zu diesem Modul.', ['alias' => $alias ?: '?']),
                'wrong_package'
            );
        }

        $drin = isset($daten['version']) ? (string) $daten['version'] : '';

        if ($drin !== $version) {
            throw new StoreException(
                Text::get('Das Archiv enthält Fassung :drin, angekündigt war :soll.', ['drin' => $drin ?: '?', 'soll' => $version]),
                'wrong_version'
            );
        }

        // Die zwei Dateien, ohne die das Modul seine Aufgabe nicht mehr
        // hat. Fehlt eine, ist das Archiv beschnitten -- und ein LaStore
        // ohne Signaturpruefung waere schlimmer als keines.
        foreach (['Support/PublicKeys.php', 'Support/PackageVerifier.php'] as $pflicht) {
            if (!is_file($ordner.'/'.$pflicht)) {
                throw new StoreException(
                    Text::get('Im Archiv fehlt :datei.', ['datei' => $pflicht]),
                    'bad_package_shape'
                );
            }
        }
    }

    /**
     * Der Tausch: zwei Umbenennungen.
     *
     * @return string Pfad des beiseitegelegten alten Stands
     */
    private static function tauschen($bau, $modules = null)
    {
        $modules = $modules === null ? base_path('Modules') : $modules;
        $jetzt = $modules.'/'.self::ORDNER;

        /*
         * Eine Arbeitskopie wird nicht getauscht.
         *
         * Der Tausch ersetzt das ganze Verzeichnis. Liegt darin ein .git,
         * verschwaende er die Versionsgeschichte und jede nicht committete
         * Aenderung -- in der Entwicklungsumgebung ist genau das der Fall,
         * weil dort der Quellordner eingehaengt ist. Wer dort aktualisieren
         * will, benutzt git.
         */
        if (is_dir($jetzt.'/.git')) {
            throw new StoreException(
                Text::get('Dieses Modul ist hier eine Git-Arbeitskopie. Ein Selbstupdate würde sie ersetzen — in einer Entwicklungsumgebung wird mit git aktualisiert.'),
                'working_copy'
            );
        }
        // Der Ordner IM ARCHIV, wie er wirklich heisst -- nicht wie unser
        // Verzeichnis heisst. Der Shop benennt ihn auf "name" aus der
        // module.json um, und das ist "LaStore" und nicht "LaStore".
        $neu = self::imArchiv($bau);
        $alt = $modules.'/.'.strtolower(self::ORDNER).'-alt-'.date('Ymd-His');

        if (!is_dir($jetzt)) {
            // Kein laufender Stand: dann ist es keine Aktualisierung,
            // sondern eine Installation. Auch gut, aber sagen wir es.
            if (!@rename($neu, $jetzt)) {
                throw new StoreException(Text::get('Die neue Fassung lässt sich nicht an ihren Platz legen.'), 'swap_failed');
            }

            return '';
        }

        if (!@rename($jetzt, $alt)) {
            throw new StoreException(
                Text::get('Der bisherige Stand lässt sich nicht beiseitelegen. Es wurde nichts verändert.'),
                'swap_failed'
            );
        }

        if (!@rename($neu, $jetzt)) {
            // Zurueck. Das ist der einzige Punkt, an dem etwas fehlen
            // koennte, und er dauert eine Umbenennung.
            @rename($alt, $jetzt);

            throw new StoreException(
                Text::get('Die neue Fassung lässt sich nicht an ihren Platz legen. Der bisherige Stand ist zurückgelegt.'),
                'swap_failed'
            );
        }

        return $alt;
    }

    /**
     * Konfigurations- und Ansichtszwischenspeicher weg.
     *
     * Ohne das laeuft der alte Stand weiter: ein bestehender Konfig-Cache
     * laesst Laravel die .env gar nicht lesen, und kompilierte Ansichten
     * bleiben liegen. Genau daran ist am 02.09.2026 ein halber Tag
     * verlorengegangen.
     *
     * @return void
     */
    private static function raeumeZwischenspeicher()
    {
        /*
         * FreeScouts EIGENE Modulliste zuerst.
         *
         * Sie liegt im Anwendungszwischenspeicher, nicht in einer Datei, und
         * sie merkt sich Name UND Fassung jedes Moduls. Ohne dieses Leeren
         * las die Kommandozeile nach dem Tausch weiter die alte Fassung --
         * am 04.09.2026 auf der Produktion beobachtet: auf der Platte lag
         * 1.0.7, `lastore:self-update --pruefen` sagte "installiert: 1.0.6",
         * und der naechtliche Lauf haette darum jede Nacht dasselbe Paket
         * geholt. Die Weboberflaeche war richtig, weil sie ihren eigenen
         * Aufruf hat -- der Unterschied faellt genau dort auf, wo niemand
         * hinsieht.
         *
         * \Module und der Zwischenspeicher liegen im Kern und in vendor/,
         * nicht im getauschten Ordner. Der Aufruf ist nach dem Tausch also
         * erlaubt. In try/catch, weil ein Fehler hier den geglueckten Tausch
         * nicht zunichte machen darf.
         */
        try {
            \Module::clearCache();
        } catch (\Exception $e) {
            // Still: der Tausch ist gelungen, das Leeren holt der naechste
            // Aufruf von freescout:clear-cache nach.
        }

        @unlink(base_path('bootstrap/cache/config.php'));

        foreach ((array) @glob(storage_path('framework/views/*.php')) as $datei) {
            @unlink($datei);
        }
    }

    /**
     * Alte Staende aufraeumen -- den letzten behalten.
     *
     * Einen zu behalten ist Absicht: wer nach einem Update etwas vermisst,
     * hat den Vorgaenger noch auf der Platte und braucht kein Archiv.
     *
     * @return void
     */
    private static function raeumeAlteStaende($behalten)
    {
        $staende = (array) @glob(base_path('Modules/.'.strtolower(self::ORDNER).'-alt-*'), GLOB_ONLYDIR);
        sort($staende);

        foreach ($staende as $stand) {
            if ($stand !== $behalten && count($staende) > 1) {
                self::wegwerfen($stand);
                array_shift($staende);
            }
        }
    }

    /** @return void */
    private static function wegwerfen($pfad)
    {
        if (!is_dir($pfad)) {
            @unlink($pfad);

            return;
        }

        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($eintraege as $eintrag) {
            $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
        }

        @rmdir($pfad);
    }
}

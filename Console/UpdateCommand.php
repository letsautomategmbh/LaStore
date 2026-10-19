<?php

namespace Modules\LaStore\Console;

use Illuminate\Console\Command;
use Modules\LaStore\Entities\License;
use Modules\LaStore\Services\PackageInstaller;
use Modules\LaStore\Services\StoreException;
use Modules\LaStore\Support\InstalledModules;
use Modules\LaStore\Support\ModulAnmeldung;
use Modules\LaStore\Support\Umzug;

/**
 * Module aktualisieren - mit Pruefung der Signatur.
 *
 * Bis dahin lief das Installieren ueber FreeScouts eigenen Updater, und der
 * prueft NICHTS. Die Signatur wurde erzeugt, ausgeliefert und nicht benutzt.
 * Wer den Auslieferungsweg unterwandert, bekam damit seinen Code auf jede
 * Installation.
 *
 * Ohne Argument werden alle lizenzierten Module geprueft. Mit einem Alias nur
 * dieses. --pruefen laedt herunter und prueft, installiert aber nicht - der
 * Weg, um vor einem Wartungsfenster zu wissen, was ansteht.
 */
class UpdateCommand extends Command
{
    protected $signature = 'lastore:update
                            {alias? : Nur dieses Modul}
                            {--pruefen : Herunterladen und prüfen, aber nicht installieren}
                            {--yes : Ohne Rückfrage installieren}';

    protected $description = 'Modul-Updates holen, Signatur prüfen und installieren';

    public function handle()
    {
        $installer = new PackageInstaller();
        $alias = $this->argument('alias');

        /*
         * Zuerst umziehen, dann aktualisieren.
         *
         * Ein umbenanntes Modul (bexio -> labexio) bekommt unter seinem alten
         * Kürzel vom Laden das Paket mit dem neuen. install() wiese es ab
         * (alias_mismatch), und das zu Recht: es gehört an eine andere
         * Stelle. Der Umzug holt es dorthin, und danach läuft die Schleife
         * unten schon unter den neuen Kürzeln. Nicht bei --pruefen und nicht
         * für ein einzelnes Modul: der Umzug geht nur alle zusammen.
         */
        if (!$alias && !$this->option('pruefen') && Umzug::anstehend()) {
            $this->line('<options=bold>Umzug auf die neuen Modulnamen</>');
            $this->call('lastore:umzug', $this->option('yes') ? array('--yes' => true) : array());
        }

        // Steht er danach immer noch an (Warteschlange, Fehler, oder nur ein
        // Modul verlangt), wird heute nichts aktualisiert: neue Fassungen
        // zeigen auf die neuen Namen ihrer Nachbarn.
        if (!$this->option('pruefen') && Umzug::anstehend()) {
            $this->warn('Der Umzug auf die neuen Modulnamen steht noch an. Bis dahin werden keine Module aktualisiert.');

            return $alias ? 1 : 0;
        }

        $aliase = $alias
            ? array(strtolower($alias))
            : License::pluck('product_alias')->all();

        if (!$aliase) {
            $this->info('Keine lizenzierten Module. Nichts zu tun.');

            return 0;
        }

        $installiert = $this->installierteFassungen();
        $fehler = 0;
        $getan = 0;
        $offen = 0;

        foreach ($aliase as $a) {
            $this->line('');
            $this->line('<options=bold>'.$a.'</>');

            try {
                $meldung = $installer->available($a);
            } catch (StoreException $e) {
                $this->error('  Der Shop antwortet nicht wie erwartet: '.$e->getMessage());
                $fehler++;
                continue;
            }

            // Ein Paket unter einem anderen Kürzel ist ein Umzug, kein Update.
            if (isset($meldung['alias']) && strtolower((string) $meldung['alias']) !== strtolower($a)) {
                $this->warn('  umbenannt in '.$meldung['alias'].' -- zieht mit lastore:umzug um, nicht hier');
                continue;
            }

            $neu = isset($meldung['version']) ? $meldung['version'] : '';
            $alt = isset($installiert[$a]) ? $installiert[$a] : null;

            $this->line(sprintf('  installiert: %-12s verfügbar: %s', $alt ?: '—', $neu ?: '—'));

            if ($alt !== null && $neu !== '' && version_compare($neu, $alt, '<=')) {
                /*
                 * <comment> und NICHT <fg=gray>: die Symfony-Fassung, die in
                 * FreeScout steckt, kennt "gray" nicht und bricht mit
                 * "Invalid foreground color specified" ab. Das traf genau den
                 * Zweig "nichts zu tun" -- also jede Nacht, in der es nichts
                 * zu tun gab. Aufgefallen erst, als der Selbstaktualisierer
                 * dieselbe Zeile kopierte und der Zweig zum ersten Mal
                 * betreten wurde.
                 */
                $this->line('  <comment>aktuell, nichts zu tun</>');
                continue;
            }

            try {
                $datei = $installer->fetchVerified($meldung);
            } catch (StoreException $e) {
                // Eine fehlgeschlagene Pruefung ist KEIN Betriebsfehler unter
                // vielen - sie ist der Grund, warum es diesen Befehl gibt.
                $this->error('  '.$e->getMessage());
                $this->line('  <comment>Code: '.$e->errorCode().'</>');
                $fehler++;
                continue;
            }

            $groesse = round(filesize($datei) / 1024);
            $this->info(sprintf('  geprüft: unverändert und signiert mit %s (%d KB)', $meldung['signing_kid'], $groesse));

            if ($this->option('pruefen')) {
                @unlink($datei);
                $this->line('  <comment>--pruefen: nicht installiert</>');
                continue;
            }

            if (!$this->option('yes') && !$this->confirm('  '.$a.' auf '.$neu.' aktualisieren?', false)) {
                @unlink($datei);
                $this->line('  übersprungen');
                continue;
            }

            try {
                $ergebnis = $installer->install($datei, $a);
            } catch (StoreException $e) {
                $this->error('  '.$e->getMessage());
                $fehler++;
                continue;
            }

            $this->info(sprintf('  installiert: %s %s', $ergebnis['name'], $ergebnis['version']));

            if ($ergebnis['backup']) {
                $this->line('  <comment>bisheriger Stand: '.basename($ergebnis['backup']).'</>');
            }

            /*
             * Hier stand einmal nur ein Hinweis, der Mensch solle danach
             * migrate laufen lassen. Ein Hinweis ist keine Handlung: er wird
             * gelesen, genickt und nicht ausgefuehrt. Auf der Produktion lag
             * darum eine Wanderung ungelaufen auf der Platte, waehrend der
             * Code, der ihre Spalte schreibt, schon lief.
             */
            $anmeldung = ModulAnmeldung::anmelden($a);

            if ($anmeldung['ok']) {
                $this->line('  <comment>angemeldet: Wanderungen, Symlink, Cache</>');
            } else {
                $this->error('  Anmelden beim Kern gescheitert: '.$anmeldung['fehler']);
                $this->warn('  Bitte von Hand: '.ModulAnmeldung::befehl($a));
                $offen++;
            }

            $getan++;
        }

        $this->line('');

        if ($getan > 0) {
            $this->info($getan.' Modul(e) aktualisiert.');
        }

        /*
         * Zwei Zaehler und nicht einer: "nicht aktualisiert" waere fuer ein
         * Modul, dessen Dateien liegen und dessen Wanderung fehlt, die
         * falsche Auskunft -- und gerade die gefaehrlichere Lage.
         */
        if ($offen > 0) {
            $this->error($offen.' Modul(e) getauscht, aber NICHT angemeldet — die Wanderungen fehlen.');
        }

        if ($fehler > 0) {
            $this->error($fehler.' Modul(e) nicht aktualisiert.');
        }

        return ($fehler > 0 || $offen > 0) ? 1 : 0;
    }

    /**
     * Alias => installierte Fassung.
     *
     * Ueber getAlias() und NICHT ueber \Module::find(): das sucht nach dem
     * NAMEN. "invoicing" heisst intern "Activities" und faellt dort still
     * durch - derselbe Fehler, der im Abgleich schon einmal zugeschlagen hat.
     *
     * @return array
     */
    protected function installierteFassungen()
    {
        $raus = array();

        foreach (\Module::all() as $modul) {
            $raus[$modul->getAlias()] = $modul->get('version');
        }

        return $raus;
    }
}

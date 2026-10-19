<?php

namespace Modules\LaStore\Console;

use Illuminate\Console\Command;
use Modules\LaStore\Support\ModulAnmeldung;
use Modules\LaStore\Support\Umzug;

/**
 * Umbenannte Module umziehen (Bexio -> LaBexio). Siehe Support\Umzug.
 *
 *     lastore:umzug            zeigen, was ansteht, und nach Rückfrage umziehen
 *     lastore:umzug --yes      ohne Rückfrage (so ruft ihn lastore:update auf)
 */
class UmzugCommand extends Command
{
    protected $signature = 'lastore:umzug {--yes : Ohne Rückfrage}';

    protected $description = 'Umbenannte Module auf ihren neuen Namen umziehen';

    public function handle()
    {
        foreach (Umzug::ohneLizenz() as $k) {
            $this->warn(sprintf('%s (%s) müsste umziehen, ist hier aber nicht lizenziert. Bitte den Schlüssel unter Verwalten → LaStore eintragen.', $k['name'], $k['alt']));
        }

        $liste = Umzug::anstehend();

        if (!$liste) {
            $this->info('Kein Umzug steht an.');

            return 0;
        }

        foreach ($liste as $k) {
            $this->line(sprintf('  %-22s %s -> %s', $k['name'], $k['alt'], $k['neu']));
        }

        if (!$this->option('yes') && !$this->confirm(count($liste).' Modul(e) gemeinsam umziehen?', false)) {
            $this->info('Abgebrochen.');

            return 0;
        }

        $ergebnis = Umzug::ausfuehren(null, function ($zeile) {
            $this->line('  '.$zeile);
        });

        if ($ergebnis['status'] === Umzug::WARTEN) {
            $this->warn($ergebnis['fehler']);

            return 0;
        }

        if ($ergebnis['status'] === Umzug::FEHLER) {
            $this->error('Nicht umgezogen: '.$ergebnis['fehler']);
            $this->line('Der bisherige Stand ist unverändert.');

            return 1;
        }

        foreach ($ergebnis['module'] as $m) {
            $this->info(sprintf('  %s -> %s %s', $m['alt'], $m['neu'], $m['version']));
        }

        foreach ($ergebnis['offen'] as $alias => $fehler) {
            $this->error(sprintf('  %s ist umgezogen, aber nicht angemeldet: %s', $alias, $fehler));
            $this->warn('  Bitte von Hand: '.ModulAnmeldung::befehl($alias));
        }

        return $ergebnis['offen'] ? 1 : 0;
    }
}

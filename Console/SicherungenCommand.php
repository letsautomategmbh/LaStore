<?php

namespace Modules\LaStore\Console;

use Illuminate\Console\Command;
use Modules\LaStore\Support\Sicherungen;

/**
 * Die beiseitegelegten Staende alter Modulfassungen zeigen und wegraeumen.
 *
 *     lastore:sicherungen               zeigen, was liegt
 *     lastore:sicherungen --aufraeumen  die Regel anwenden (laeuft naechtlich)
 *     lastore:sicherungen --alle        alle loeschen, mit Rueckfrage
 *
 * Die Regel und ihr Warum stehen in Support\Sicherungen.
 */
class SicherungenCommand extends Command
{
    protected $signature = 'lastore:sicherungen
        {--aufraeumen : Je Modul die neueste behalten, nichts älter als 7 Tage}
        {--alle : Alle Sicherungen löschen}
        {--yes : Ohne Rückfrage}';

    protected $description = 'Sicherungen alter Modulfassungen zeigen und aufräumen';

    public function handle()
    {
        if ($this->option('aufraeumen')) {
            $weg = Sicherungen::aufraeumen();
            $this->info(count($weg) ? 'Gelöscht: '.implode(', ', $weg) : 'Nichts aufzuräumen.');

            return 0;
        }

        $liste = Sicherungen::alle();

        if (!$liste) {
            $this->info('Es liegen keine Sicherungen.');

            return 0;
        }

        $zeilen = array();
        $summe = 0;

        foreach ($liste as $sicherung) {
            $bytes = Sicherungen::groesse($sicherung['pfad']);
            $summe += $bytes;
            $zeilen[] = array($sicherung['modul'], date('d.m.Y H:i T', $sicherung['zeit']), self::mb($bytes), $sicherung['ordner']);
        }

        $this->table(array('Modul', 'Angelegt', 'Grösse', 'Ordner'), $zeilen);
        $this->line(count($liste).' Sicherungen, zusammen '.self::mb($summe).'.');

        if (!$this->option('alle')) {
            return 0;
        }

        if (!$this->option('yes') && !$this->confirm('Alle Sicherungen löschen? Ein Zurück auf die vorige Fassung geht danach nur noch über den Laden.', false)) {
            $this->info('Abgebrochen.');

            return 0;
        }

        $weg = Sicherungen::alleLoeschen();
        $this->info(count($weg).' von '.count($liste).' gelöscht.');

        return count($weg) === count($liste) ? 0 : 1;
    }

    /** @return string */
    protected static function mb($bytes)
    {
        return number_format($bytes / 1048576, 1, '.', "'").' MB';
    }
}

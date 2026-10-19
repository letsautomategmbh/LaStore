<?php

namespace Modules\LaStore\Support;

/**
 * Die beiseitegelegten Staende alter Modulfassungen: finden, zeigen, wegraeumen.
 *
 * Zwei Stellen legen sie an, und beide mit einem Punkt davor, damit FreeScout
 * sie nicht fuer Module haelt:
 *
 *     .Themes.vorher-20261017-155432    PackageInstaller, je Modul
 *     .lastore-alt-20261017-034012      SelfUpdater, LaStore selbst
 *
 * **Die Regel seit 2.4.0:** je Modul bleibt EINE Sicherung, und auch die
 * nur sieben Tage. Vorher waren es fuenf, und weggeraeumt wurde nur beim
 * naechsten Update desselben Moduls -- ein Modul, das lange keines bekam,
 * behielt seine fuenf fuer immer. André fand am 19.10.2026 auf der eigenen
 * Anlage je fuenf von Themes und Vault, und sah darin, was es war: Ballast.
 *
 * Eine reicht, weil sie nur fuer den einen Fall da ist, in dem man sie
 * braucht: das letzte Update hat etwas kaputt gemacht. Ein Rueckweg ueber
 * mehrere Fassungen hinweg geht ueber den Laden, nicht ueber die Platte.
 * Sieben Tage, weil man einen Fehler nicht immer am selben Tag bemerkt,
 * aber nach einer Woche ist er entweder gefunden oder kein Fehler.
 *
 * Das Alter steht im NAMEN, nicht in filemtime(): rename() laesst die Zeit
 * des Verzeichnisses stehen, und die ist die der letzten Aenderung darin --
 * bei einem Modul, das monatelang kein Update bekam, also uralt. Nach
 * filemtime() wuerde jede frische Sicherung sofort geloescht.
 */
class Sicherungen
{
    /** Wie lange eine Sicherung hoechstens liegen bleibt. */
    const TAGE = 7;

    /** Wie viele je Modul hoechstens liegen bleiben. */
    const JE_MODUL = 1;

    const MUSTER_MODUL = '/^\.(.+)\.vorher-(\d{8}-\d{6})$/';
    const MUSTER_SELBST = '/^\.lastore-alt-(\d{8}-\d{6})$/';

    /**
     * Alle Sicherungen, die neueste zuerst.
     *
     * @param string|null $modules Modulverzeichnis, fuer Tests
     *
     * @return array<int, array{pfad: string, ordner: string, modul: string, zeit: int}>
     */
    public static function alle($modules = null)
    {
        $modules = $modules === null ? self::modulesPath() : rtrim($modules, '/');
        $liste = array();

        foreach ((array) @scandir($modules) as $eintrag) {
            $eintrag = (string) $eintrag;
            $erkannt = self::erkennen($eintrag);

            // is_link zuerst: eine Verknuepfung mit passendem Namen ist keine
            // Sicherung von uns, und loeschen wollen wir nie, wohin sie zeigt.
            if ($erkannt === null || is_link($modules.'/'.$eintrag) || !is_dir($modules.'/'.$eintrag)) {
                continue;
            }

            $liste[] = array(
                'pfad'   => $modules.'/'.$eintrag,
                'ordner' => $eintrag,
                'modul'  => $erkannt['modul'],
                'zeit'   => $erkannt['zeit'],
            );
        }

        usort($liste, function ($a, $b) {
            return $b['zeit'] - $a['zeit'];
        });

        return $liste;
    }

    /**
     * Die Regel anwenden: je Modul die neueste, und keine aelter als TAGE.
     *
     * @param string|null $modules
     * @param int|null    $jetzt Zeitstempel, fuer Tests
     *
     * @return array<int, string> die geloeschten Ordnernamen
     */
    public static function aufraeumen($modules = null, $jetzt = null)
    {
        $jetzt = $jetzt === null ? time() : (int) $jetzt;
        $grenze = $jetzt - self::TAGE * 86400;
        $gezaehlt = array();
        $weg = array();

        // alle() liefert die neueste zuerst -- die ersten JE_MODUL je Modul
        // bleiben, sofern sie jung genug sind.
        foreach (self::alle($modules) as $sicherung) {
            $modul = strtolower($sicherung['modul']);
            $gezaehlt[$modul] = isset($gezaehlt[$modul]) ? $gezaehlt[$modul] + 1 : 1;

            if ($gezaehlt[$modul] <= self::JE_MODUL && $sicherung['zeit'] >= $grenze) {
                continue;
            }

            if (self::wegwerfen($sicherung['pfad'])) {
                $weg[] = $sicherung['ordner'];
            }
        }

        return $weg;
    }

    /**
     * Eine einzelne Sicherung loeschen, angegeben mit ihrem Ordnernamen.
     *
     * Der Name kommt aus einem Formular. Darum wird er nicht als Pfad
     * benutzt, sondern unter den gefundenen Sicherungen GESUCHT: was nicht
     * in alle() steht, wird nicht angefasst. So kommt kein "../" und kein
     * Modulordner durch, ganz gleich, was jemand einschickt.
     *
     * @return bool
     */
    public static function loeschen($ordner, $modules = null)
    {
        foreach (self::alle($modules) as $sicherung) {
            if ($sicherung['ordner'] === (string) $ordner) {
                return self::wegwerfen($sicherung['pfad']);
            }
        }

        return false;
    }

    /**
     * Alle Sicherungen loeschen.
     *
     * @return array<int, string> die geloeschten Ordnernamen
     */
    public static function alleLoeschen($modules = null)
    {
        $weg = array();

        foreach (self::alle($modules) as $sicherung) {
            if (self::wegwerfen($sicherung['pfad'])) {
                $weg[] = $sicherung['ordner'];
            }
        }

        return $weg;
    }

    /**
     * Groesse eines Verzeichnisses in Bytes. Nur fuer die Anzeige.
     *
     * @return int
     */
    public static function groesse($pfad)
    {
        $summe = 0;

        try {
            $eintraege = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($eintraege as $eintrag) {
                if ($eintrag->isFile() && !$eintrag->isLink()) {
                    $summe += $eintrag->getSize();
                }
            }
        } catch (\Exception $e) {
            // Eine unlesbare Datei darin macht die Zahl ungenau, nicht die
            // Seite kaputt.
        }

        return $summe;
    }

    /**
     * Ordnername zerlegen: welches Modul, wann. Null, wenn es keine Sicherung ist.
     *
     * @return array{modul: string, zeit: int}|null
     */
    public static function erkennen($ordner)
    {
        if (preg_match(self::MUSTER_SELBST, $ordner, $m)) {
            $modul = 'LaStore';
            $stempel = $m[1];
        } elseif (preg_match(self::MUSTER_MODUL, $ordner, $m)) {
            $modul = $m[1];
            $stempel = $m[2];
        } else {
            return null;
        }

        // Dieselbe Zeitzone, in der date() den Namen geschrieben hat.
        $zeit = \DateTime::createFromFormat('!Ymd-His', $stempel);

        if ($zeit === false) {
            return null;
        }

        return array('modul' => $modul, 'zeit' => $zeit->getTimestamp());
    }

    /** @return string */
    public static function modulesPath()
    {
        $pfad = function_exists('config') ? config('modules.paths.modules') : null;

        return $pfad ? rtrim($pfad, '/') : base_path('Modules');
    }

    /**
     * Verzeichnis samt Inhalt loeschen. Verknuepfungen werden entfernt, nie verfolgt.
     *
     * @return bool ob es danach weg ist
     */
    protected static function wegwerfen($pfad)
    {
        if (is_link($pfad) || !is_dir($pfad)) {
            @unlink($pfad);

            return !file_exists($pfad) && !is_link($pfad);
        }

        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($eintraege as $eintrag) {
            if ($eintrag->isDir() && !$eintrag->isLink()) {
                @rmdir($eintrag->getPathname());
            } else {
                @unlink($eintrag->getPathname());
            }
        }

        @rmdir($pfad);

        return !is_dir($pfad);
    }
}

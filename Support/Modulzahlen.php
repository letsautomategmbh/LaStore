<?php

namespace Modules\LaStore\Support;

/**
 * Die drei Zahlen aus der Seitenleiste von FreeScouts Modulseite.
 *
 * FreeScout schreibt dort hinter jeden Eintrag, wie viele es sind:
 * «Installierte Module (29)», «Modulordner (63)», «Marktplatz (5)». Unsere
 * Seite baut dieselbe Leiste nach und sah ohne diese Zahlen daneben aus wie
 * eine andere Anwendung.
 *
 * ---------------------------------------------------------------------------
 * WARUM WIR SIE NICHT SELBST AUSRECHNEN
 * ---------------------------------------------------------------------------
 * Nur die erste Zahl ist billig zu haben (`\Module::all()`). Die beiden
 * anderen entstehen in `ModulesController::modules()` aus einem Abruf bei
 * freescout.net (`WpApi::getModules()`, 15 Minuten im Cache) und werden
 * danach in einer knapp dreissig Zeilen langen Schleife auseinandersortiert:
 * installierte heraus, Fremdmodule in den Marktplatz, nicht offizielle weg.
 *
 * Das hier nachzubauen hiesse, eine fremde Entscheidung zu verdoppeln -- sie
 * würde bei der nächsten Änderung im Kern still falsch. Und der Abruf gehört
 * ohnehin nicht in den Aufbau unserer Seite.
 *
 * Darum lesen wir die Zahlen dort ab, wo der Kern sie ohnehin ausrechnet:
 * ein Ansichts-Komponist an `modules.sidebar_menu` schaut dem Kern beim
 * Rendern seiner eigenen Leiste über die Schulter.
 *
 * ---------------------------------------------------------------------------
 * ZWEI FOLGEN, BEIDE GEWOLLT
 * ---------------------------------------------------------------------------
 * 1. Vor dem ersten Besuch der Modulseite gibt es keine Zahlen, und unsere
 *    Leiste zeigt dann keine. Das ist genau dann unsichtbar, wenn es darauf
 *    ankommt: wer den Unterschied bemerkt, kommt von jener Seite -- und
 *    damit sind die Zahlen da.
 * 2. Sie können veralten, wenn zwischendurch ein Modul dazukommt. Eine Zahl
 *    in einer Sprungmarke ist eine Beschriftung, keine Auskunft; der Klick
 *    führt auf die Seite, die es genau weiss.
 *
 * Abgelegt wird in `\Option`, nicht im Cache: FreeScout leert den Cache bei
 * jeder Modulinstallation -- also genau dann, wenn jemand zwischen beiden
 * Seiten hin und her springt.
 */
class Modulzahlen
{
    const SCHLUESSEL = 'lastore.modulzahlen';

    /**
     * Die Zahlen aus den Daten der Kern-Ansicht herausnehmen und merken.
     *
     * Geschrieben wird nur, wenn sich etwas geändert hat: der Komponist läuft
     * bei jedem Aufruf der Modulseite, und eine Schreiboperation je Aufruf
     * wäre für drei unveränderte Zahlen zu viel.
     */
    public static function merken(array $daten)
    {
        $zahlen = [
            'installiert' => self::zaehlen($daten, 'installed_modules'),
            'ordner'      => self::zaehlen($daten, 'modules_directory'),
            'marktplatz'  => self::zaehlen($daten, 'third_party_modules'),
        ];

        if (in_array(null, $zahlen, true)) {
            return;
        }

        if ($zahlen !== self::holen()) {
            \Option::set(self::SCHLUESSEL, $zahlen);
        }
    }

    /**
     * Was zuletzt gesehen wurde, oder ein leeres Feld.
     */
    public static function holen()
    {
        $zahlen = \Option::get(self::SCHLUESSEL);

        return is_array($zahlen) ? $zahlen : [];
    }

    /**
     * Ein Feld aus den Ansichtsdaten zählen -- oder `null`, wenn es dort
     * nicht steht. `null` heisst: lieber gar keine Zahl als eine erfundene.
     */
    protected static function zaehlen(array $daten, $feld)
    {
        if (!isset($daten[$feld]) || !is_array($daten[$feld])) {
            return null;
        }

        return count($daten[$feld]);
    }
}

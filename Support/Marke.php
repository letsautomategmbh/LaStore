<?php

namespace Modules\LaStore\Support;

/**
 * Die Marke von let's automate als Symbol für die Menüeinträge.
 *
 * ---------------------------------------------------------------------------
 * WARUM NICHT DAS GANZE LOGO
 * ---------------------------------------------------------------------------
 * `logo-mark-navy.svg` ist ein dunkelblaues Quadrat mit der grünen Marke
 * darin. Bei 14 px -- der Grösse der Glyphicons in der Seitenleiste -- bleibt
 * davon ein dunkler Klecks übrig; nebeneinandergelegt am 15.10.2026 war die
 * Marke allein auch bei 14 px noch zu erkennen, das Quadrat nicht.
 *
 * Darum nur der Pfad, auf die Marke beschnitten, und `fill="currentColor"`:
 * damit nimmt sie die Farbe ihrer Zeile an, wie jedes Glyphicon daneben --
 * grau im Ruhezustand, weiss auf dem grünen Balken des aktiven Eintrags. Das
 * Markengrün wäre dort grün auf grün.
 *
 * ---------------------------------------------------------------------------
 * WARUM EINGEBETTET UND NICHT ALS DATEI
 * ---------------------------------------------------------------------------
 * Ein `<img src="…svg">` erbt `currentColor` nicht -- eine Datei kennt die
 * Farbe ihrer Umgebung nicht. Eingebettet schon. Der Preis sind rund 700
 * Bytes im HTML, die kein Zwischenspeicher einspart; dafür gibt es keinen
 * zweiten Abruf und keinen Pfad, der nach einem Update ins Leere zeigt.
 *
 * Eine Stelle für alle drei Verwendungen: unsere Seitenleiste, der Eintrag,
 * den wir auf FreeScouts Modulseite anhängen, und der im Verwaltungsmenü.
 */
class Marke
{
    /** Auf die Marke beschnitten -- das Quadrat des Logos bleibt aussen vor. */
    const AUSSCHNITT = '340 272 420 570';

    const PFAD = 'M581.53,348.41H528l32.17-55.71,7-12.09c-16.67,0-33,0-49.36,0a2.69,2.69,0,0,0-2.7,1.6q-27,47-54.22,93.86a3.27,3.27,0,0,0,0,3.81q27.22,47,54.31,94a2.55,2.55,0,0,0,2.57,1.49c15.74-.05,31.48,0,47.21,0h2.18c-.44-.81-.76-1.39-1.09-2q-21-36.39-42-72.76h57.47A117.65,117.65,0,0,1,699.05,518.11v146c0,64.79-53.24,117.5-118,117.5H495.37a80,80,0,1,1,0-160h75.92q-19.56,33.9-39.13,67.81l-1.07,1.93h2.15c15.5,0,31,0,46.5,0a2.52,2.52,0,0,0,2.54-1.46q26.69-46.33,53.5-92.62a3.2,3.2,0,0,0,0-3.75q-26.76-46.2-53.41-92.46a2.62,2.62,0,0,0-2.65-1.58c-16.1.06-32.21,0-48.63,0l6.86,11.91q16.73,29,33.47,58h-76c-72.9,0-132.21,59.31-132.21,132.2s59.31,132.21,132.21,132.21h86.16c93.34-.29,169.71-76.29,169.71-169.69v-146C751.24,424.54,675.11,348.41,581.53,348.41Z';

    /**
     * Das Sinnbild als HTML -- an der Stelle und in der Farbe eines
     * Glyphicons.
     *
     * ES TRÄGT DEREN KLASSE, und das ist der ganze Trick. Die Symbole in der
     * Seitenleiste stehen nicht im Textfluss:
     *
     *     .sidebar-menu > li > a  { padding-left: 50px; position: relative }
     *     .sidebar-menu .glyphicon { position: absolute; left: 20px; top: 10px }
     *
     * Ein frei fliessendes SVG landet darum erst hinter der Polsterung --
     * gemessen am 15.10.2026: die drei Glyphicons begannen bei 20 px, unser
     * Sinnbild bei 50, und die ganze Zeile stand sichtbar eingerückt. Mit der
     * Klasse greift dieselbe Regel, und es sitzt auf den Pixel genau dort,
     * wo die anderen sitzen -- auch im Verwaltungsmenü, wo andere Masse
     * gelten.
     *
     * Dasselbe gilt für die Farbe: `.sidebar-menu .active .glyphicon` färbt
     * mit `!important`, und `fill="currentColor"` folgt dem mit.
     *
     * `1em` statt fester Pixel, damit es der Schriftgrösse der jeweiligen
     * Regel folgt (in der Seitenleiste 16 px).
     *
     * `aria-hidden`, weil der Text daneben dasselbe sagt -- ein Vorleser
     * spräche sonst zweimal von demselben Eintrag.
     */
    public static function svg($klasse = 'glyphicon lastore-marke')
    {
        return '<svg class="'.e($klasse).'" viewBox="'.self::AUSSCHNITT.'" '
            .'xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" '
            .'style="width:1em;height:1em">'
            .'<path fill="currentColor" d="'.self::PFAD.'"/></svg>';
    }
}

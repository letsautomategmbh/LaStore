<?php

use Illuminate\Support\Facades\Route;

/* ZU JEDEM PRAEFIX GEHOERT \Helper::getSubdirectory().
 *
 * Steht FreeScout nicht in der Wurzel, sondern unter
 * https://kunde.ch/helpdesk, dann liefert der Aufruf "helpdesk" und jede
 * Route dieser Datei bekommt es vorangestellt. Ohne das meldet das Modul
 * seine Wege unter /<praefix>/... an, waehrend der Browser sie unter
 * /helpdesk/<praefix>/... sucht -- jede Seite des Moduls ist dann eine 404,
 * und zwar NUR bei Kunden mit Unterverzeichnis. In der Wurzel ist der
 * Rueckgabewert leer und es aendert sich nichts; deshalb faellt das Fehlen
 * bei uns selbst nie auf.
 *
 * Der Leitfaden nennt es als Pflicht, und der Modulgenerator setzt es von
 * selbst -- hier war es verlorengegangen. Verschachtelte Gruppen bekommen es
 * NICHT: die erben das Praefix ihrer Elterngruppe. */

Route::group([
    'namespace'  => 'Modules\LaStore\Http\Controllers',
    'middleware' => ['web', 'auth'],
    'prefix' => \Helper::getSubdirectory().'/store',
    'as'         => 'lastore.',
], function () {
    Route::get('/', 'StoreController@index')->name('index');
    // Die Lizenzseite gibt es nicht mehr: Lizenzen verwaltet der Kunde im
    // Portal. Hier bleibt nur, was das Portal nicht kann - die
    // Offline-Lizenzdatei auf DIESEN Server legen und die Prüfung anstossen.
    // Ein Druck: Schlüssel prüfen, installieren, registrieren.
    Route::post('installieren', 'StoreController@install')->name('install');
    Route::post('autopilot', 'StoreController@autopilot')->name('autopilot');
    // LaStore selbst. Eigener Weg, weil er das Modul austauscht, in dem er
    // laeuft -- nach dem Tausch wird nichts mehr nachgeladen.
    Route::post('selbst-aktualisieren', 'StoreController@selfUpdate')->name('self_update');
    Route::post('lizenzen/aktivieren', 'LicenseController@activate')->name('licenses.activate');
    Route::post('lizenzen/offline', 'LicenseController@importOffline')->name('licenses.offline');
    Route::post('lizenzen/pruefen', 'LicenseController@refresh')->name('licenses.refresh');
    // Keine Produktseite im Modul: die Details stehen im LaStore, und zwei
    // Fassungen derselben Beschreibung sind zwei Wahrheiten.
});

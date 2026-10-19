<?php

namespace Modules\LaStore\Tests\Unit;

use Modules\LaStore\Support\Sicherungen;
use PHPUnit\Framework\TestCase;

/**
 * Die Regel fuer Sicherungen: je Modul eine, keine aelter als sieben Tage.
 *
 * Gemessen wird am NAMEN, nicht an filemtime() -- rename() laesst die Zeit
 * des Ordners stehen. Die Tests legen darum Ordner mit Namen an und schieben
 * "jetzt" vor, statt Dateizeiten zu verbiegen.
 */
class SicherungenTest extends TestCase
{
    /** @var string */
    protected $modules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modules = sys_get_temp_dir().'/lastore-sicherungen-'.bin2hex(random_bytes(5));
        mkdir($this->modules, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->weg($this->modules);
        parent::tearDown();
    }

    public function testErkenntBeideArtenUndSonstNichts()
    {
        $this->assertSame('Themes', Sicherungen::erkennen('.Themes.vorher-20261017-155432')['modul']);
        $this->assertSame('LaStore', Sicherungen::erkennen('.lastore-alt-20261017-034012')['modul']);
        // Ein Modulname mit Punkt bleibt ganz.
        $this->assertSame('Ein.Modul', Sicherungen::erkennen('.Ein.Modul.vorher-20261017-155432')['modul']);

        foreach (array('Themes', '.lastore-auspack-abc', '.Themes.vorher-2026', '..', '.git', 'Themes.vorher-20261017-155432') as $kein) {
            $this->assertNull(Sicherungen::erkennen($kein), $kein.' ist keine Sicherung');
        }
    }

    public function testJeModulBleibtNurDieNeueste()
    {
        $jetzt = $this->zeit('20261019-120000');
        $this->anlegen('.Themes.vorher-20261015-104816');
        $this->anlegen('.Themes.vorher-20261017-155432');
        $this->anlegen('.Themes.vorher-20261019-100000');
        $this->anlegen('.Vault.vorher-20261017-213832');
        $this->anlegen('.lastore-alt-20261018-034000');

        $weg = Sicherungen::aufraeumen($this->modules, $jetzt);

        sort($weg);
        $this->assertSame(array('.Themes.vorher-20261015-104816', '.Themes.vorher-20261017-155432'), $weg);
        $this->assertSame(
            array('.Themes.vorher-20261019-100000', '.lastore-alt-20261018-034000', '.Vault.vorher-20261017-213832'),
            array_column(Sicherungen::alle($this->modules), 'ordner')
        );
    }

    public function testNachSiebenTagenGehtAuchDieLetzte()
    {
        $this->anlegen('.Vault.vorher-20261010-120000');
        $this->anlegen('.Themes.vorher-20261012-120001');

        // Genau sieben Tage nach Themes: die bleibt noch, Vault nicht.
        $weg = Sicherungen::aufraeumen($this->modules, $this->zeit('20261019-120001'));

        $this->assertSame(array('.Vault.vorher-20261010-120000'), $weg);
        $this->assertDirectoryExists($this->modules.'/.Themes.vorher-20261012-120001');
    }

    /**
     * Die Zeit des Ordners zaehlt nicht. Eine frische Sicherung eines Moduls,
     * das monatelang kein Update bekam, traegt eine uralte filemtime.
     */
    public function testDieOrdnerzeitSpieltKeineRolle()
    {
        $this->anlegen('.Themes.vorher-20261019-100000');
        touch($this->modules.'/.Themes.vorher-20261019-100000', strtotime('2025-01-01'));

        $this->assertSame(array(), Sicherungen::aufraeumen($this->modules, $this->zeit('20261019-120000')));
    }

    public function testModulordnerBleibenUnberuehrt()
    {
        $this->anlegen('Themes');
        $this->anlegen('.Themes.vorher-20261001-000000');

        Sicherungen::aufraeumen($this->modules, $this->zeit('20261019-120000'));
        Sicherungen::alleLoeschen($this->modules);

        $this->assertDirectoryExists($this->modules.'/Themes');
        $this->assertFileExists($this->modules.'/Themes/module.json');
    }

    /**
     * Der Name kommt aus einem Formular. Was nicht unter den gefundenen
     * Sicherungen steht, wird nicht angefasst -- auch kein Weg nach oben.
     */
    public function testLoeschenNimmtNurGefundeneSicherungen()
    {
        $this->anlegen('Themes');
        $this->anlegen('.Themes.vorher-20261019-100000');

        $this->assertFalse(Sicherungen::loeschen('Themes', $this->modules));
        $this->assertFalse(Sicherungen::loeschen('../'.basename($this->modules).'/Themes', $this->modules));
        $this->assertFalse(Sicherungen::loeschen('', $this->modules));
        $this->assertDirectoryExists($this->modules.'/Themes');

        $this->assertTrue(Sicherungen::loeschen('.Themes.vorher-20261019-100000', $this->modules));
        $this->assertSame(array(), Sicherungen::alle($this->modules));
    }

    /**
     * Eine Verknuepfung in der Sicherung wird entfernt, ihr Ziel nicht.
     */
    public function testVerknuepfungenWerdenNichtVerfolgt()
    {
        $draussen = $this->modules.'-draussen';
        mkdir($draussen);
        file_put_contents($draussen.'/wichtig.txt', 'bleibt');

        $this->anlegen('.Themes.vorher-20261001-000000');
        symlink($draussen, $this->modules.'/.Themes.vorher-20261001-000000/Public');

        Sicherungen::alleLoeschen($this->modules);

        $this->assertFileExists($draussen.'/wichtig.txt');
        $this->assertSame(array(), Sicherungen::alle($this->modules));
        $this->weg($draussen);
    }

    public function testGroesseZaehltDateien()
    {
        $this->anlegen('.Themes.vorher-20261019-100000');
        file_put_contents($this->modules.'/.Themes.vorher-20261019-100000/gross.bin', str_repeat('x', 5000));

        $this->assertGreaterThanOrEqual(5000, Sicherungen::groesse($this->modules.'/.Themes.vorher-20261019-100000'));
    }

    // ─────────────────────────────────────────────────────────────── Helfer

    protected function anlegen($ordner)
    {
        mkdir($this->modules.'/'.$ordner, 0777, true);
        file_put_contents($this->modules.'/'.$ordner.'/module.json', '{}');
    }

    protected function zeit($stempel)
    {
        return \DateTime::createFromFormat('!Ymd-His', $stempel)->getTimestamp();
    }

    protected function weg($pfad)
    {
        if (is_link($pfad) || is_file($pfad)) {
            @unlink($pfad);

            return;
        }

        if (!is_dir($pfad)) {
            return;
        }

        foreach (scandir($pfad) as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->weg($pfad.'/'.$e);
            }
        }

        @rmdir($pfad);
    }
}

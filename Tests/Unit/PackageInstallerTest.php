<?php

namespace Modules\LaStore\Tests\Unit;

use Modules\LaStore\Services\PackageInstaller;
use Modules\LaStore\Services\StoreException;
use PHPUnit\Framework\TestCase;

/**
 * Das Entpacken und der Tausch.
 *
 * Die Signatur sagt, dass ein Archiv von UNS ist. Sie sagt nicht, dass es
 * harmlos ist - ein Fehler unsererseits waere ebenso signiert. Darum wird
 * auch nach der Pruefung noch auf den Aufbau geschaut, und darum liegt der
 * alte Stand beiseite, bevor etwas ersetzt wird.
 */
class PackageInstallerTest extends TestCase
{
    /** @var string */
    protected $arbeit;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('\ZipArchive')) {
            $this->markTestSkipped('ZipArchive fehlt.');
        }

        $this->arbeit = sys_get_temp_dir().'/lastore-installertest-'.bin2hex(random_bytes(5));
        mkdir($this->arbeit.'/Modules', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->wegRaeumen($this->arbeit);
        parent::tearDown();
    }

    protected function wegRaeumen($pfad)
    {
        if (is_dir($pfad)) {
            foreach (array_diff(scandir($pfad), array('.', '..')) as $e) {
                $this->wegRaeumen($pfad.'/'.$e);
            }
            @rmdir($pfad);

            return;
        }

        @unlink($pfad);
    }

    /** @return string Pfad zum ZIP */
    protected function archiv(array $eintraege)
    {
        $pfad = $this->arbeit.'/paket-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        $zip->open($pfad, \ZipArchive::CREATE);

        foreach ($eintraege as $name => $inhalt) {
            $zip->addFromString($name, $inhalt);
        }

        $zip->close();

        return $pfad;
    }

    protected function installer()
    {
        // Der Modulpfad wird ueber die Konfiguration bestimmt. Fuer den Test
        // zeigt er in ein Wegwerfverzeichnis - es soll kein Test ein echtes
        // Modul ersetzen.
        $arbeit = $this->arbeit;

        return new class(null, $arbeit) extends PackageInstaller {
            protected $testPfad;

            public function __construct($client, $pfad)
            {
                $this->testPfad = $pfad.'/Modules';
            }

            protected function modulesPath()
            {
                return $this->testPfad;
            }

            // Nur fuer den Test nach aussen gelegt: die Lage des
            // Arbeitsverzeichnisses IST die Zusicherung, um die es hier geht.
            public function arbeitsDirOeffentlich($prefix)
            {
                return $this->arbeitsDir($prefix);
            }

            public function wegraeumenOeffentlich()
            {
                $this->alteArbeitWegraeumen();
            }

            public function modulesPathOeffentlich()
            {
                return $this->modulesPath();
            }
        };
    }

    public function testEinSauberesArchivWirdInstalliert()
    {
        $zip = $this->archiv(array(
            'Backup/module.json' => json_encode(array('name' => 'Backup', 'alias' => 'backup', 'version' => '1.9.5')),
            'Backup/Providers/X.php' => '<?php',
        ));

        $ergebnis = $this->installer()->install($zip, 'backup');

        $this->assertSame('Backup', $ergebnis['name']);
        $this->assertSame('1.9.5', $ergebnis['version']);
        $this->assertNull($ergebnis['backup'], 'Ohne Vorgänger gibt es nichts beiseitezulegen.');
        $this->assertFileExists($this->arbeit.'/Modules/Backup/module.json');
        $this->assertFileDoesNotExist($zip, 'Das Paket wird nach der Installation entfernt.');
    }

    public function testDerBisherigeStandWirdBeiseitegelegtUndNichtGeloescht()
    {
        mkdir($this->arbeit.'/Modules/Backup', 0777, true);
        file_put_contents($this->arbeit.'/Modules/Backup/alt.txt', 'wichtig');

        $zip = $this->archiv(array(
            'Backup/module.json' => json_encode(array('name' => 'Backup', 'alias' => 'backup', 'version' => '2.0.0')),
        ));

        $ergebnis = $this->installer()->install($zip, 'backup');

        $this->assertNotNull($ergebnis['backup']);
        $this->assertFileExists($ergebnis['backup'].'/alt.txt', 'Der alte Stand muss zurückholbar bleiben.');
        $this->assertFileExists($this->arbeit.'/Modules/Backup/module.json');
        $this->assertFileDoesNotExist($this->arbeit.'/Modules/Backup/alt.txt');
    }

    public function testEinPaketMitFalschemAliasWirdAbgelehnt()
    {
        // Signiert waere das trotzdem - beide Module sind von uns. Nur
        // angefordert war ein anderes.
        $zip = $this->archiv(array(
            'Vault/module.json' => json_encode(array('name' => 'Vault', 'alias' => 'vault', 'version' => '1.0.0')),
        ));

        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/vault.*backup|backup.*vault/is');

        $this->installer()->install($zip, 'backup');
    }

    public function testZipSlipWirdAbgelehnt()
    {
        // Der klassische Angriff: ein Eintrag, der aus dem Zielverzeichnis
        // hinausschreibt. Die Signatur schuetzt davor nicht.
        $zip = $this->archiv(array(
            'Backup/module.json' => json_encode(array('name' => 'Backup', 'alias' => 'backup')),
            'Backup/../../../config/app.php' => '<?php // uebernommen',
        ));

        $this->expectException(StoreException::class);

        try {
            $this->installer()->install($zip, 'backup');
        } catch (StoreException $e) {
            $this->assertSame('unsafe_path', $e->errorCode());
            $this->assertFileDoesNotExist($this->arbeit.'/Modules/Backup');
            throw $e;
        }
    }

    public function testZweiObersteOrdnerWerdenAbgelehnt()
    {
        $zip = $this->archiv(array(
            'Backup/module.json' => json_encode(array('name' => 'Backup', 'alias' => 'backup')),
            'Sonstwas/datei.txt' => 'x',
        ));

        $this->expectException(StoreException::class);
        $this->installer()->install($zip, 'backup');
    }

    public function testOhneModuleJsonWirdNichtsInstalliert()
    {
        $zip = $this->archiv(array('Backup/Providers/X.php' => '<?php'));

        $this->expectException(StoreException::class);

        try {
            $this->installer()->install($zip, 'backup');
        } catch (StoreException $e) {
            $this->assertSame('no_module_json', $e->errorCode());
            $this->assertFileDoesNotExist($this->arbeit.'/Modules/Backup');
            throw $e;
        }
    }

    /**
     * Der Grund, warum das Update auf dem echten Host nie durchlief.
     *
     * Ausgepackt wurde nach sys_get_temp_dir(), getauscht wird mit rename() --
     * und rename() kann nicht ueber Dateisystemgrenzen. Auf CloudLinux (unser
     * eigener Host) hat der PHP-Prozess ein eigenes /tmp auf einem anderen
     * Datentraeger als das Konto. Ergebnis: Paket geholt, Signatur geprueft,
     * dann "liess sich nicht ersetzen". Jedes Mal, seit es das Modul gibt.
     *
     * Dieser Test prueft nicht das Symptom, sondern die Zusicherung: das
     * Arbeitsverzeichnis liegt IM Modulverzeichnis. Dann KANN dort keine
     * Grenze sein -- unabhaengig davon, wie der Host sein /tmp einhaengt.
     * Ein Test auf "gleiche Geraetenummer" waere hier wertlos: genau die
     * stimmte auf dem Host ueberein, und rename() scheiterte trotzdem.
     */
    public function testDasArbeitsverzeichnisLiegtImModulverzeichnis()
    {
        $installer = $this->installer();

        $auspack = $installer->arbeitsDirOeffentlich('.lastore-auspack-');

        $this->assertDirectoryExists($auspack);
        $this->assertSame(
            $installer->modulesPathOeffentlich(),
            dirname($auspack),
            'Das Arbeitsverzeichnis muss NEBEN dem Ziel liegen, sonst ist rename() eine Wette auf den Host.'
        );
        $this->assertStringStartsWith(
            '.',
            basename($auspack),
            'Ohne fuehrenden Punkt findet FreeScouts glob das halbfertige Verzeichnis als Modul.'
        );
    }

    public function testNachDerInstallationBleibtKeinArbeitsverzeichnisLiegen()
    {
        $zip = $this->archiv(array(
            'Backup/module.json' => json_encode(array('name' => 'Backup', 'alias' => 'backup', 'version' => '1.9.5')),
        ));

        $this->installer()->install($zip, 'backup');

        $this->assertSame(
            array(),
            (array) glob($this->arbeit.'/Modules/.lastore-auspack-*', GLOB_ONLYDIR),
            'Das Arbeitsverzeichnis wird am Ende entfernt -- auch das unsichtbare.'
        );
    }

    /**
     * Die Sicherung darf FreeScout nicht als Modul unter die Augen kommen.
     *
     * Sie hiess "Backup.vorher-20260908-083834", und darin liegt eine
     * module.json mit Namen "Backup". Gefunden werden Module über ein
     * glob-Muster mit Stern — die Sicherung war damit ein auffindbares Modul,
     * das sich als der ALTE Stand desselben Moduls ausgibt.
     *
     * Auf unserer Anlage lief trotzdem das richtige Verzeichnis: die Sammlung
     * schlüsselt nach Namen, und der Konflikt ging gut aus. Verlassen kann
     * man sich darauf nicht — deshalb wird hier gezählt, was glob findet, und
     * nicht, was am Ende geladen wurde.
     */
    public function testDieSicherungIstFuerFreeScoutKeinModul()
    {
        mkdir($this->arbeit.'/Modules/Backup', 0777, true);
        file_put_contents(
            $this->arbeit.'/Modules/Backup/module.json',
            json_encode(array('name' => 'Backup', 'alias' => 'backup', 'version' => '1.9.5'))
        );

        $zip = $this->archiv(array(
            'Backup/module.json' => json_encode(array('name' => 'Backup', 'alias' => 'backup', 'version' => '1.9.6')),
        ));

        $ergebnis = $this->installer()->install($zip, 'backup');

        // Genau dasselbe Muster, mit dem FreeScout sucht.
        $gefunden = (array) glob($this->arbeit.'/Modules/*/module.json');

        $this->assertCount(
            1,
            $gefunden,
            'Nach dem Update darf genau EIN Modul auffindbar sein — die Sicherung ist keins.'
        );
        $this->assertSame($this->arbeit.'/Modules/Backup/module.json', $gefunden[0]);

        // Und sie ist trotzdem da, sonst gäbe es keinen Rückweg.
        $this->assertDirectoryExists($ergebnis['backup']);
        $this->assertStringStartsWith('.', basename($ergebnis['backup']));
    }

    public function testLiegengebliebeneArbeitBleibtEineStundeVerschont()
    {
        // Bricht der Vorgang hart ab, bleibt ein Verzeichnis liegen. Alte
        // werden geraeumt; ein PARALLEL laufender Vorgang darf seines aber
        // nicht unter den Haenden verlieren.
        $alt = $this->arbeit.'/Modules/.lastore-auspack-altaltalt';
        $neu = $this->arbeit.'/Modules/.lastore-auspack-frischfrisch';
        mkdir($alt, 0700, true);
        mkdir($neu, 0700, true);
        touch($alt, time() - 7200);

        $this->installer()->wegraeumenOeffentlich();

        $this->assertDirectoryDoesNotExist($alt, 'Zwei Stunden alt: weg.');
        $this->assertDirectoryExists($neu, 'Gerade angelegt: das koennte ein laufender Vorgang sein.');
    }

    // ─────────────────────────────────────────── Umzug (Bexio -> LaBexio)

    protected function altesModul($ordner = 'Bexio', $alias = 'bexio')
    {
        mkdir($this->arbeit.'/Modules/'.$ordner, 0777, true);
        file_put_contents($this->arbeit.'/Modules/'.$ordner.'/module.json', json_encode(array('name' => $ordner, 'alias' => $alias, 'version' => '3.1.1')));

        return $this->arbeit.'/Modules/'.$ordner;
    }

    protected function neuesPaket($version = '4.0.0')
    {
        return $this->archiv(array(
            'LaBexio/module.json' => json_encode(array('name' => 'LaBexio', 'alias' => 'labexio', 'version' => $version)),
        ));
    }

    /**
     * Der alte Ordner geht beiseite, der neue kommt unter seinem Namen --
     * und danach findet FreeScout genau EIN Modul, nicht zwei.
     */
    public function testUmzugErsetztDenAltenOrdnerUndLaesstKeinenZweiten()
    {
        $alt = $this->altesModul();

        $ergebnis = $this->installer()->umziehen($this->neuesPaket(), 'labexio', $alt);

        $this->assertSame('LaBexio', $ergebnis['name']);
        $this->assertFileExists($this->arbeit.'/Modules/LaBexio/module.json');
        $this->assertDirectoryDoesNotExist($alt);
        $this->assertFileExists($ergebnis['backup'].'/module.json', 'Der alte Stand bleibt als Sicherung.');
        $this->assertStringStartsWith('.Bexio.vorher-', basename($ergebnis['backup']));
        $this->assertCount(1, (array) glob($this->arbeit.'/Modules/*/module.json'));
    }

    /** Der alte Ordner wird über den Pfad gefunden, nicht über den Namen (Invoicing hiess «Activities»). */
    public function testUmzugNimmtDenAltenOrdnerAuchUnterAnderemNamen()
    {
        $alt = $this->altesModul('Activities', 'invoicing');
        $zip = $this->archiv(array(
            'LaInvoicing/module.json' => json_encode(array('name' => 'LaInvoicing', 'alias' => 'lainvoicing', 'version' => '3.0.0')),
        ));

        $this->installer()->umziehen($zip, 'lainvoicing', $alt);

        $this->assertDirectoryDoesNotExist($alt);
        $this->assertFileExists($this->arbeit.'/Modules/LaInvoicing/module.json');
    }

    public function testUmzugMitFalschemKuerzelImPaketAendertNichts()
    {
        $alt = $this->altesModul();

        try {
            $this->installer()->umziehen($this->neuesPaket(), 'lavault', $alt);
            $this->fail('Ein Paket mit fremdem Kürzel darf nicht umziehen.');
        } catch (StoreException $e) {
            $this->assertSame('alias_mismatch', $e->errorCode());
        }

        $this->assertFileExists($alt.'/module.json');
        $this->assertDirectoryDoesNotExist($this->arbeit.'/Modules/LaBexio');
    }

    /** Liegt unter dem neuen Namen schon etwas, wird nichts verschmolzen. */
    public function testUmzugInEinenBelegtenOrdnerUnterbleibt()
    {
        $alt = $this->altesModul();
        mkdir($this->arbeit.'/Modules/LaBexio', 0777, true);
        file_put_contents($this->arbeit.'/Modules/LaBexio/eigen.txt', 'fremd');

        try {
            $this->installer()->umziehen($this->neuesPaket(), 'labexio', $alt);
            $this->fail('Ein belegter Zielordner muss den Umzug verhindern.');
        } catch (StoreException $e) {
            $this->assertSame('umzug_ziel_belegt', $e->errorCode());
        }

        $this->assertFileExists($alt.'/module.json');
        $this->assertFileExists($this->arbeit.'/Modules/LaBexio/eigen.txt');
    }

    public function testUmzugAusserhalbDesModulverzeichnissesUnterbleibt()
    {
        $draussen = $this->arbeit.'/Anderswo';
        mkdir($draussen, 0777, true);

        $this->expectException(StoreException::class);
        $this->installer()->umziehen($this->neuesPaket(), 'labexio', $draussen);
    }

    /** Zurücknehmen: neuer Ordner weg, alter wieder an seinem Platz. */
    public function testUmzugLaesstSichZuruecknehmen()
    {
        $alt = $this->altesModul();
        $installer = $this->installer();

        $ergebnis = $installer->umziehen($this->neuesPaket(), 'labexio', $alt);

        $this->assertTrue($installer->umzugZuruecknehmen($ergebnis));
        $this->assertFileExists($alt.'/module.json');
        $this->assertDirectoryDoesNotExist($this->arbeit.'/Modules/LaBexio');
        $this->assertSame(array($this->arbeit.'/Modules/Bexio/module.json'), (array) glob($this->arbeit.'/Modules/*/module.json'));
    }
}

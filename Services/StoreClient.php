<?php

namespace Modules\LaStore\Services;

use Modules\LaStore\Entities\Installation;
use Modules\LaStore\Services\Transport\HttpTransport;
use Modules\LaStore\Services\Transport\StaticTransport;
use Modules\LaStore\Services\Transport\Transport;
use Modules\LaStore\Support\SecretString;

/**
 * Der Protokollclient. Eine Methode je Endpunkt aus der Spezifikation,
 * sonst nichts - die Entscheidungen liegen bei den Aufrufern.
 */
class StoreClient
{
    /** @var Transport */
    protected $transport;

    /** @var Installation */
    protected $installation;

    public function __construct(?Transport $transport = null, ?Installation $installation = null)
    {
        $this->installation = $installation === null ? Installation::current() : $installation;
        $this->transport = $transport === null ? self::defaultTransport() : $transport;
    }

    /**
     * Statisch oder echt, je nach Einstellung. Die Vorgabe ist der statische
     * Transport, solange es keinen Server gibt.
     *
     * @return Transport
     */
    public static function defaultTransport()
    {
        if (config('lastore.transport', 'static') === 'static') {
            return new StaticTransport(config('lastore.fixtures'));
        }

        return new HttpTransport(self::clientVersion());
    }

    /**
     * Die eigene Fassung, aus der module.json.
     *
     * findByAlias und NICHT find: \Module::find() sucht nach dem NAMEN des
     * Moduls, nicht nach dem Kuerzel. Solange beide "LaStore" hiessen, fiel
     * das nicht auf -- mit der Umbenennung auf "LaStore" fand sich das Modul
     * selbst nicht mehr, und clientVersion() gab eine leere Zeichenkette.
     *
     * Die Folge war still und haesslich: pruefen() vergleicht die eigene
     * Fassung mit der angebotenen, und ohne eigene Fassung ist JEDE angebotene
     * neuer. Der Hinweis "Fassung X liegt bereit" haette dauerhaft gestanden,
     * und der Autopilot haette jede Nacht dasselbe Paket eingespielt.
     * Aufgefallen erst im vollen Durchlauf, weil dort "installiert: —" stand.
     *
     * @return string
     */
    public static function clientVersion()
    {
        try {
            $module = \Module::findByAlias('lastore');

            return $module ? (string) $module->get('version') : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Bei mode = offline darf NICHTS nach draussen gehen. Ein stiller
     * Verbindungsversuch alle 24 Stunden taucht in einem ueberwachten Netz
     * als Alarm auf - und der Kunde hat diesen Modus genau deshalb gewaehlt.
     *
     * @return void
     */
    protected function refuseWhenOffline()
    {
        if ($this->installation->isOffline()) {
            throw new StoreException(
                __('Diese Installation ist auf Offline-Betrieb gestellt. Es werden keine Verbindungen aufgebaut.'),
                'offline_mode'
            );
        }
    }

    /** @return array */
    protected function installationHeaders()
    {
        return [
            'X-Store-Installation' => (string) $this->installation->installation_id,
            'X-Store-Secret'       => $this->installation->secretPlain(),
        ];
    }

    // ---- Katalog -----------------------------------------------------------

    /** @return array */
    public function catalog()
    {
        $this->refuseWhenOffline();

        $response = $this->transport->get('catalog');

        /*
         * Die Umbenennungen ALLER Module (alt => neu), auch derer, die nicht
         * im Katalog stehen -- Assets etwa ist intern, liegt aber lizenziert
         * hier. Abgelegt neben dem Katalog; Support\Umzug liest beides.
         * Fehlt das Feld (älterer Laden), bleibt der letzte Stand.
         */
        if (isset($response['umbenennungen']) && is_array($response['umbenennungen'])) {
            \Option::set('lastore.umbenennungen', $response['umbenennungen']);
        }

        return isset($response['products']) ? $response['products'] : [];
    }

    /**
     * @param string $alias
     *
     * @return array
     */
    public function product($alias)
    {
        $this->refuseWhenOffline();

        $response = $this->transport->get('catalog/'.$alias);

        return isset($response['product']) ? $response['product'] : [];
    }

    // ---- Installation ------------------------------------------------------

    /**
     * @param array $facts
     *
     * @return array
     */
    public function registerInstallation(array $facts)
    {
        $this->refuseWhenOffline();

        return $this->transport->post('installations', $facts);
    }

    // ---- Lizenzen ----------------------------------------------------------

    /**
     * @param SecretString|string $licenseKey
     * @param string              $productAlias
     *
     * @return array
     */
    public function activate($licenseKey, $productAlias)
    {
        $this->refuseWhenOffline();

        // Ausgepackt wird erst hier. Solange der Schluessel als Objekt
        // gereicht wird, steht in einem Stacktrace nur der Klassenname.
        $key = SecretString::wrap($licenseKey)->reveal();

        return $this->transport->post(
            'licenses/activate',
            ['product_alias' => $productAlias, 'license_key' => $key],
            array_merge($this->installationHeaders(), ['X-Store-License' => $key])
        );
    }

    /**
     * @param array $aliases
     * @param int   $userCount
     *
     * @return array
     */
    public function checkBatch(array $aliases, $userCount)
    {
        $this->refuseWhenOffline();

        return $this->transport->post(
            'licenses/check-batch',
            ['aliases' => array_values($aliases), 'user_count' => (int) $userCount],
            $this->installationHeaders()
        );
    }

    /**
     * @param string $licenseKey
     * @param string $productAlias
     *
     * @return array
     */
    public function release($licenseKey, $productAlias)
    {
        $this->refuseWhenOffline();

        return $this->transport->post(
            'licenses/release',
            ['product_alias' => $productAlias],
            array_merge($this->installationHeaders(), ['X-Store-License' => SecretString::wrap($licenseKey)->reveal()])
        );
    }

    // ---- Pakete ------------------------------------------------------------

    /**
     * @param string $alias
     * @param string $channel
     *
     * @return array
     */
    public function latestPackage($alias, $channel = 'stable')
    {
        $this->refuseWhenOffline();

        return $this->transport->get('packages/'.$alias.'/latest', ['channel' => $channel], $this->installationHeaders());
    }

    /**
     * Die neueste Fassung DIESES Moduls.
     *
     * Ohne Installations-Kopfzeilen: der Weg ist oeffentlich, weil LaStore
     * keine Lizenz hat und weil es das Stueck ist, ueber das alles andere
     * geprueft ankommt. Eine Installation, die sich noch nie angemeldet hat,
     * muss sich trotzdem aktualisieren koennen -- sonst ist ein
     * Schluesselwechsel eine Sackgasse.
     *
     * @param string $channel
     *
     * @return array
     */
    public function clientLatest($channel = 'stable')
    {
        $this->refuseWhenOffline();

        return $this->transport->get('client/latest', ['channel' => $channel]);
    }

    // ---- Heartbeat ---------------------------------------------------------

    /**
     * @param array $facts
     *
     * @return array
     */
    public function heartbeat(array $facts)
    {
        $this->refuseWhenOffline();

        return $this->transport->post('heartbeat', $facts, $this->installationHeaders());
    }

    /** @return Installation */
    public function installation()
    {
        return $this->installation;
    }
}

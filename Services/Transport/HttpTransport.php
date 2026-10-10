<?php

namespace Modules\LaStore\Services\Transport;

use Modules\LaStore\Services\StoreException;

/**
 * Der echte Transport.
 *
 * Bei einem Transportfehler wird genau einmal die Ausweichadresse versucht -
 * dasselbe Muster wie WpApi::request() im Kern, das bei einer Exception
 * rekursiv auf freescout_alt_api wechselt. Ein Fehler DORT wird nicht noch
 * einmal umgeleitet, sonst dreht sich der Aufruf im Kreis.
 *
 * **Der Ausweichweg ist AUS, solange keine Adresse eingestellt ist.**
 *
 * Hier stand eine Vorgabe: `https://cdn.letsautomate.ch/api/store/v1`. Diese
 * Unteradresse gibt es nicht und wird es nicht geben (Entscheid 04.09.2026).
 * Sie stand damit in jeder ausgelieferten Installation und richtete genau
 * einen Schaden an, aber einen taeglichen: NXDOMAIN kommt in 10 Millisekunden
 * zurueck, das kostete nichts -- aber der zweite Versuch WIRFT DIE ERSTE
 * MELDUNG WEG. Beim Kunden kam
 *
 *     Could not resolve host: cdn.letsautomate.ch
 *
 * an, statt "Shop nicht erreichbar" oder "Zeitueberschreitung". Der Support
 * haette nach einem CDN gesucht, das nie existierte, waehrend die wahre
 * Ursache -- die einzige, die zaehlt -- verworfen war.
 *
 * Nur GET weicht aus. Was eine Ausweichadresse ueberhaupt sein KANN, ist
 * eine statische Spiegelung der Leseseite (Katalog, client/latest); eine
 * Lizenzaktivierung ist ein POST und hat dort nichts zu holen. Ein POST, der
 * gegen eine Spiegelung wiederholt wird, bekommt bestenfalls 404 -- und im
 * schlechteren Fall sieht es aus, als sei nichts passiert.
 */
class HttpTransport implements Transport
{
    // Ueber die Einstellung ueberschreibbar, damit derselbe Stand gegen den
    // Entwicklungsserver und gegen die Produktivadresse laeuft.
    const BASE = 'https://shop.letsautomate.ch/api/store/v1';

    /**
     * Keine Vorgabe. Wer eine Spiegelung hat, stellt sie ein
     * (LASTORE_FALLBACK_URL); wer keine hat, bekommt die echte Fehlermeldung.
     */
    const FALLBACK = '';

    const CONNECT_TIMEOUT = 10;
    const TIMEOUT = 30;

    /** @var string */
    protected $clientVersion;

    public function __construct($clientVersion = '')
    {
        $this->clientVersion = (string) $clientVersion;
    }

    /**
     * Die eingestellte Ausweichadresse, oder leer.
     *
     * @return string
     */
    protected function ausweichadresse()
    {
        return self::normalisiereAdresse(config('lastore.fallback_url', self::FALLBACK));
    }

    /**
     * Was als Adresse zaehlt -- und was nicht.
     *
     * Eigene, reine Funktion und nicht ein trim() an der Aufrufstelle: nur so
     * laesst sich die Regel ohne hochgefahrene Anwendung festnageln. Der
     * erste Versuch, das ueber ausweichadresse() zu pruefen, lief in
     * config() und damit in den Dienstbehaelter.
     *
     * Getrimmt wird, weil eine Einstellung, die versehentlich auf ein
     * Leerzeichen steht, sonst als gesetzt gilt -- die Anfrage liefe gegen
     * "/catalog" ohne Host, und die Fehlermeldung waere wieder ueber die
     * falsche Sache.
     *
     * @param mixed $wert
     *
     * @return string
     */
    public static function normalisiereAdresse($wert)
    {
        return is_string($wert) ? trim($wert) : '';
    }

    public function get($path, array $query = [], array $headers = [])
    {
        return $this->request('GET', $path, $query, $headers);
    }

    public function post($path, array $body = [], array $headers = [])
    {
        return $this->request('POST', $path, $body, $headers);
    }

    protected function request($method, $path, array $data, array $headers, $useFallback = false)
    {
        $base = $useFallback
            ? $this->ausweichadresse()
            : config('lastore.base_url', self::BASE);

        $url = rtrim($base, '/').'/'.ltrim($path, '/');

        $options = [
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout'         => self::TIMEOUT,
            'http_errors'     => false,
            'headers'         => array_merge([
                'Accept'          => 'application/json',
                'Accept-Language' => $this->locale(),
                // Damit der Server veraltete Clients erkennen und einen
                // Hinweis anhaengen kann, statt die Verbindung zu verweigern.
                'X-Store-Client'  => $this->clientVersion,
            ], $headers),
        ];

        if ($method === 'POST') {
            $options['json'] = $data;
        } else {
            $options['query'] = $data;
        }

        try {
            $client = new \GuzzleHttp\Client(\Helper::setGuzzleDefaultOptions());
            $response = $client->request($method, $url, \Helper::setGuzzleDefaultOptions($options));
        } catch (\Exception $e) {
            // Nur ausweichen, wenn es wirklich eine Adresse gibt, und nur
            // beim Lesen. Sonst wird die einzige brauchbare Meldung durch
            // die eines Hosts ersetzt, der nichts mit der Sache zu tun hat.
            if (!$useFallback && $method === 'GET' && $this->ausweichadresse() !== '') {
                return $this->request($method, $path, $data, $headers, true);
            }

            throw new StoreException($e->getMessage(), 'transport_error');
        }

        return $this->decode($response);
    }

    protected function decode($response)
    {
        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true);

        if (!is_array($json)) {
            throw new StoreException(__('Der Shop hat eine unlesbare Antwort geschickt.'), 'bad_response', $status);
        }

        if (empty($json['ok'])) {
            $error = isset($json['error']) && is_array($json['error']) ? $json['error'] : [];

            throw new StoreException(
                isset($error['message']) ? $error['message'] : __('Unbekannter Fehler'),
                isset($error['code']) ? $error['code'] : 'unknown_error',
                $status
            );
        }

        return $json;
    }

    protected function locale()
    {
        $locale = \App::getLocale();

        return $locale ? $locale : 'de';
    }
}

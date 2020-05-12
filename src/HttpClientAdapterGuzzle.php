<?php

/**
 * Adapter for the Guzzle HTTP client library.
 *
 * @author Michael Stilkerich <michael@stilkerich.eu>
 * @copyright 2020 Michael Stilkerich
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License, version 2 (or later)
 * @internal
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use MStilkerich\CardDavClient\Exception\{ClientException, NetworkException};

/**
 * Adapter for the Guzzle HTTP client library.
 */
class HttpClientAdapterGuzzle implements HttpClientAdapterInterface
{
    /********* PROPERTIES *********/

    /** @var GuzzleClient The Client object of the Guzzle HTTP library. */
    private $client;

    /** @var resource|null Handle of the debug file if debugging is enabled. */
    private $debughandle = null;

    /********* PUBLIC FUNCTIONS *********/

    /** Constructs a HttpClientAdapterGuzzle object.
     *
     * @param string $base_uri Base URI to be used when relative URIs are given to requests.
     * @param string $username Username used to authenticate with the server.
     * @param string $password Password used to authenticate with the server.
     * @param array  $options  Options for the HTTP client, and default request options. May include any of the options
     *               accepted by {@see HttpClientAdapterInterface::sendRequest()}, plus the following:
     *               'debugfile' => string: Filename to be used for debug logging of all HTTP traffic.
     */
    public function __construct(string $base_uri, string $username, string $password, array $options = [])
    {
        $guzzleOptions = self::prepareGuzzleOptions($options);

        if (key_exists("debugfile", $options)) {
            $this->debughandle = fopen($options["debugfile"], "a");
            $guzzleOptions["debug"] = $this->debughandle;
        }

        $guzzleOptions['http_errors'] = false; // no exceptions on 4xx/5xx status, also required by PSR-18
        $guzzleOptions['base_uri'] = $base_uri;
        $guzzleOptions['auth'] = [$username, $password];

        $this->client = new GuzzleClient($guzzleOptions);
    }

    /** Destructor of the adapter.
     *
     * Closes the debug file if enabled.
     */
    public function __destruct()
    {
        if (isset($this->debughandle)) {
            echo "Closing Debug Handle\n";
            fclose($this->debughandle);
        }
    }

    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     */
    public function sendRequest(string $method, string $uri, array $options = []): Psr7Response
    {
        $guzzleOptions = self::prepareGuzzleOptions($options);

        try {
            $response = $this->client->request($method, $uri, $guzzleOptions);
            return $this->responsePostProcessing($response);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // thrown in the event of a networking error or too many redirects
            throw new NetworkException($e->getMessage(), intval($e->getCode()), $e->getRequest(), $e);
        } catch (\InvalidArgumentException | \GuzzleHttp\Exception\GuzzleException $e) {
            // Anything else
            throw new ClientException($e->getMessage(), intval($e->getCode()), $e);
        }
    }

    /********* PRIVATE FUNCTIONS *********/
    private function responsePostProcessing(Psr7Response $guzzleResponse): Psr7Response
    {
        if (isset($this->debughandle)) {
            fwrite($this->debughandle, (string) $guzzleResponse->getBody());
        }

        return $guzzleResponse;
    }

    private static function prepareGuzzleOptions(array $options): array
    {
        $guzzleOptions = [];

        foreach ([ "headers", "body" ] as $copyopt) {
            if (key_exists($copyopt, $options)) {
                $guzzleOptions[$copyopt] = $options[$copyopt];
            }
        }

        if (key_exists("allow_redirects", $options) && $options["allow_redirects"] === false) {
            $guzzleOptions["allow_redirects"] = false;
        } else {
            $guzzleOptions["allow_redirects"] = [
                'max'             => 5,
                'strict'          => true, // keep original method, i.e. do not perform GET on redirection target
                'referer'         => false,
                'protocols'       => ['http', 'https'],
                'track_redirects' => false
            ];
        }

        return $guzzleOptions;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

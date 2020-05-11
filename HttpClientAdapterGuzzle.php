<?php

/**
 * Class HttpClientAdapterGuzzle
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;

class HttpClientAdapterGuzzle implements HttpClientAdapterInterface
{
    /********* PROPERTIES *********/

    /** @var GuzzleClient */
    private $client;

    /** @var resource|null */
    private $debughandle = null;

    /********* PUBLIC FUNCTIONS *********/
    # Options: default options for request
    #   - headers => array('headername' => val (string) OR array(val1, val2, ...))
    #   - debugfile => string (filename)
    public function __construct(string $base_uri, string $username, string $password, array $options = [])
    {
        $guzzleOptions = self::prepareGuzzleOptions($options);

        if (array_key_exists("debugfile", $options)) {
            $this->debughandle = fopen($options["debugfile"], "a");
            $guzzleOptions["debug"] = $this->debughandle;
        }

        $guzzleOptions['http_errors'] = false; // no exceptions on 4xx/5xx status, also required by PSR-18
        $guzzleOptions['base_uri'] = $base_uri;
        $guzzleOptions['auth'] = [$username, $password];

        $this->client = new GuzzleClient($guzzleOptions);
    }

    public function __destruct()
    {
        if (isset($this->debughandle)) {
            echo "Closing Debug Handle\n";
            fclose($this->debughandle);
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
            if (array_key_exists($copyopt, $options)) {
                $guzzleOptions[$copyopt] = $options[$copyopt];
            }
        }

        if (array_key_exists("allow_redirects", $options) && $options["allow_redirects"] === false) {
            $guzzleOptions["allow_redirects"] = false;
        } else {
            $guzzleOptions["allow_redirects"] = [
                'max'             => 5,
                'strict'          => true, // keep original method, i.e. do not perform GET on redirection target
                'referer'         => false,
                'protocols'       => ['http', 'https'],
                'track_redirects' => true  // to be able to get the final resource location from a response object
            ];
        }

        return $guzzleOptions;
    }

    // Options: default options for request
    //   - headers => array('headername' => val (string) OR array(val1, val2, ...))
    //   - body => string (optional body content)
    //   TODO throw Psr\Http\Client\ClientExceptionInterface exception if request could not be sent or response could
    //   not be parsed
    //   TODO throw Psr\Http\Client\RequestExceptionInterface if request is not a well-formed HTTP request or is missing
    //   some critical piece of information (such as a Host or Method)
    //   TODO throw Psr\Http\Client\NetworkExceptionInterface if the request cannot be sent due to a network failure of
    //   any kind, including a timeout
    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     *
     * @return Psr7Response
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface If an error happens while processing the request.
     */
    public function sendRequest(string $method, string $uri, array $options = []): Psr7Response
    {
        $guzzleOptions = self::prepareGuzzleOptions($options);

        $response = $this->client->request($method, $uri, $guzzleOptions);
        return $this->responsePostProcessing($response);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

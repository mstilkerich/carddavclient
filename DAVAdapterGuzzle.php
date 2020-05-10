<?php

/**
 * Class DAVAdapterGuzzle
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Psr7Response;

class DAVAdapterGuzzle extends DAVAdapter
{
    /********* PROPERTIES *********/
    private $client;

    private $debughandle = false;

    /********* PUBLIC FUNCTIONS *********/
    # Options: default options for request
    #   - headers => array('headername' => val (string) OR array(val1, val2, ...))
    #   - debugfile => string (filename)
    public function __construct(string $base_uri, string $username, string $password, array $options = [])
    {
        parent::__construct($base_uri);

        $guzzleOptions = self::prepareGuzzleOptions($options);

        if (array_key_exists("debugfile", $options)) {
            $this->debughandle = fopen($options["debugfile"], "a");
            $guzzleOptions["debug"] = $this->debughandle;
        }

        $guzzleOptions['http_errors'] = false; // no exceptions on 4xx/5xx status
        $guzzleOptions['base_uri'] = $base_uri;
        $guzzleOptions['auth'] = [$username, $password];

        $this->client = new Client($guzzleOptions);
    }

    public function __destruct()
    {
        if ($this->debughandle !== false) {
            echo "Closing Debug Handle\n";
            fclose($this->debughandle);
        }
    }

    /********* PRIVATE FUNCTIONS *********/
    private function responsePostProcessing(Psr7Response $guzzleResponse): Psr7Response
    {
        if ($this->debughandle !== false) {
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
    public function request(string $method, string $uri, array $options = []): Psr7Response
    {
        $guzzleOptions = self::prepareGuzzleOptions($options);

        $response = $this->client->request($method, $uri, $guzzleOptions);
        return $this->responsePostProcessing($response);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

<?php

require 'vendor/autoload.php';

require_once 'DAVAdapter.php';

use GuzzleHttp\Client;

class DAVAdapterGuzzle extends DAVAdapter
{
	private $client;

	private $debughandle = false;

	public function __destruct()
	{
		if ($this->debughandle !== false)
		{
			echo "Closing Debug Handle\n";
			fclose($this->debughandle);
		}
	}

	# Options: default options for request
	#   - headers => array('headername' => val (string) OR array(val1, val2, ...))
	#   - debugfile => string (filename)
	public function init($base_uri, $username, $password, $options=array())
	{
		$guzzleOptions = self::prepareGuzzleOptions($options);

		if (array_key_exists("debugfile", $options))
		{
			$this->debughandle = fopen($options["debugfile"], "a");
			$guzzleOptions["debug"] = $this->debughandle;
		}

		$guzzleOptions['http_errors'] = false; // no exceptions on 4xx/5xx status
		$guzzleOptions['base_uri'] = $base_uri;
		$guzzleOptions['auth'] = [$username, $password];

		$this->client = new Client($guzzleOptions);
	}

	private function convertGuzzleToInternal($guzzleResponse)
	{
		$reply = array();
		$statuscode = $guzzleResponse->getStatusCode();
		$reply['success'] = ($statuscode >= 200) && ($statuscode < 300);
		$reply['status'] = $statuscode;
		$reply['statusmsg'] = $guzzleResponse->getReasonPhrase();
		$reply['headers'] = array();
		foreach ($guzzleResponse->getHeaders() as $name => $values)
		{
			$reply['headers'][strtolower($name)] = $values;
		}
		$reply['body'] = $guzzleResponse->getBody()->getContents();

		if ($this->debughandle !== false)
		{
			fwrite($this->debughandle, $reply['body']);
		}
		return $reply;
	}

	private static function prepareGuzzleOptions($options)
	{
		$guzzleOptions = array();

		if ( array_key_exists("headers", $options) )
		{
			$guzzleOptions["headers"] = $options["headers"];
		}

		if (array_key_exists("allow_redirects", $options) && $options["allow_redirects"] === false)
		{
			$guzzleOptions["allow_redirects"] = false;
		}
		else
		{
			$guzzleOptions["allow_redirects"] =
			[
				'max'             => 5,
				'strict'          => true, // keep original request method, i.e. do not perform GET on redirection target
				'referer'         => false,
				'protocols'       => ['http', 'https'],
				'track_redirects' => false
			];
		}

		return $guzzleOptions;
	}

	# Options: default options for request
	#   - headers => array('headername' => val (string) OR array(val1, val2, ...))
	public function propfind($uri, $body, $options=array())
	{
		$guzzleOptions = self::prepareGuzzleOptions($options);
		$guzzleOptions['body'] = $body;

		$response = $this->client->request('PROPFIND', $uri, $guzzleOptions);
		return $this->convertGuzzleToInternal($response);
	}

	public function report($uri, $body, $options=array())
	{
		$guzzleOptions = self::prepareGuzzleOptions($options);
		$guzzleOptions['body'] = $body;

		$response = $this->client->request('REPORT', $uri, $guzzleOptions);
		return $this->convertGuzzleToInternal($response);
	}

	public function get($uri, $options=array())
	{
		$guzzleOptions = self::prepareGuzzleOptions($options);

		$response = $this->client->get($uri, $guzzleOptions);
		return $this->convertGuzzleToInternal($response);
	}

	public function delete($uri, $options=array())
	{
		$guzzleOptions = self::prepareGuzzleOptions($options);

		$response = $this->client->delete($uri, $guzzleOptions);
		return $this->convertGuzzleToInternal($response);
	}

	public function put($uri, $body, $options=array())
	{
		$guzzleOptions = self::prepareGuzzleOptions($options);
		$guzzleOptions['body'] = $body;

		$response = $this->client->put($uri, $guzzleOptions);
		return $this->convertGuzzleToInternal($response);
	}
}

?>

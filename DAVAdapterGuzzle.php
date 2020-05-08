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
	public function __construct($base_uri, $username, $password, $options=array())
	{
		parent::__construct($base_uri);

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

	private function responsePostProcessing($guzzleResponse)
	{
		if ($this->debughandle !== false)
		{
			fwrite($this->debughandle, $guzzleResponse->getBody());
		}

		return $guzzleResponse;
	}

	private static function prepareGuzzleOptions($options)
	{
		$guzzleOptions = array();

		foreach ( [ "headers", "body" ] as $copyopt )
		{
			if ( array_key_exists($copyopt, $options) )
			{
				$guzzleOptions[$copyopt] = $options[$copyopt];
			}
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
				'track_redirects' => true  // to be able to get the final resource location from a response object
			];
		}

		return $guzzleOptions;
	}

	// Options: default options for request
	//   - headers => array('headername' => val (string) OR array(val1, val2, ...))
	//   - body => string (optional body content)
	public function request($method, $uri, $options=array())
	{
		$guzzleOptions = self::prepareGuzzleOptions($options);

		$response = $this->client->request($method, $uri, $guzzleOptions);
		return $this->responsePostProcessing($response);
	}
}

?>

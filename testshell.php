<?php
/*
require 'vendor/autoload.php';

use GuzzleHttp\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
 */

require_once 'CardDAV_Discovery.php';

include 'accounts.php';

/*
$body = <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"><D:prop>
	<D:current-user-principal/>
	<D:resourcetype />
	<D:displayname />
	<C:addressbook-home-set/>
</D:prop></D:propfind>
EOF;

$dav = DAVAdapter::createAdapter();

$dav->init("https://$srv", $usr, $pw, [
	"headers" =>
	[
		'User-Agent' => 'Carddav4RC'
	],
	"debugfile" => 'http.log'
]);
$reply = $dav->propfind('/carddav/v1/principals/stilkerich@gmail.com/lists/default', $body, [
	"headers" => [ 'X-Foo' => "Testheader" ]
]);

echo "REPLY: " . $reply["body"];
 */

function set_credentials($srv, $usr, $pw)
{
	global $accountdata;
	$def = false;
	$username = $usr;
	$password = $pw;

	if (array_key_exists($srv, $accountdata))
	{
		$def = $accountdata[$srv];
	}

	if (strlen($username) == 0)
	{
		if (is_array($def))
		{
			$username = $def["username"];
		}
		else
		{
			echo "Error: username not set\n";
			$username = false;
		}
	}
	if (strlen($password) == 0)
	{
		if (is_array($def))
		{
			$password = $def["password"];
		}
		else
		{
			echo "Error: password not set\n";
			$password = false;
		}
	}

	return [$username, $password];
}

function discover($srv, $usr="", $pw="")
{
	list($username, $password) = set_credentials($srv,$usr,$pw);
	$retval = false;

	if ($username !== false && $password !== false)
	{
		echo "Discover($srv,$username,$password)\n";

		$discover = new CardDAV_Discovery();
		$discover->discover_addressbooks($srv, $username, $password);
		$retval = true;
	}

	return $retval;
}

while ($cmd = readline("> "))
{
	$command_ok = false;

	$tokens = explode(" ", $cmd);

	if (is_array($tokens) && count($tokens) > 0)
	{
		$command = array_shift($tokens);
		switch ($command)
		{
		case "discover":
			if (count($tokens) > 0)
			{
				$command_ok = call_user_func_array('discover', $tokens);
			}
			else
			{
				echo "Usage: discover <servername> [<username>] [<password>]\n";
			}
		}
	}


	if ($command_ok)
	{
		readline_add_history($cmd);
	}
}

/*
$onRedirect = function(
	RequestInterface $request,
	ResponseInterface $response,
	UriInterface $uri
) {
	echo 'Redirecting! ' . $request->getUri() . ' to ' . $uri . "\n";
};


$client = new Client([
	'base_uri' => "https://$srv",
	'debug' => true,
	'auth'  => [$usr, $pw],
]);

$response = $client->request('PROPFIND',
	'/co',
	[
		'allow_redirects' => [
			'max'             => 10,        // allow at most 10 redirects.
			'strict'          => true,      // use "strict" RFC compliant redirects.
			'referer'         => true,      // add a Referer header
			'protocols'       => ['https'], // only allow https URLs
			'on_redirect'     => $onRedirect,
			'track_redirects' => true
		],
		'body'=> <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"><D:prop>
	<D:current-user-principal/>
	<D:resourcetype />
	<D:displayname />
	<C:addressbook-home-set/>
</D:prop></D:propfind>
EOF
	]
);

echo $response->getBody();
 */
?>

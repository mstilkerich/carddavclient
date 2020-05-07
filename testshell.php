<?php
/*
require 'vendor/autoload.php';

use GuzzleHttp\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
 */

require_once 'DAVAdapterGuzzle.php';
require_once 'CardDAV_Discovery.php';

require 'accounts.php';

$discover = new CardDAV_Discovery();
$discover->discover_addressbooks($srv, $usr, $pw);

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

$dav = new DAVAdapterGuzzle();

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
while ($cmd = readline("> "))
{
	readline_add_history($cmd);

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

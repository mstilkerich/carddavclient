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

?>

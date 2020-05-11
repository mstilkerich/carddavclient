<?php

/**
 * Simple CardDAV Shell, mainly for debugging the library.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Http\Message\ResponseInterface as Psr7Response;

require 'vendor/autoload.php';

include 'accounts.php';

function set_credentials(string $srv, ?string $usr, ?string $pw): array
{
    global $accountdata;
    $def = null;
    $username = $usr;
    $password = $pw;

    if (array_key_exists($srv, $accountdata)) {
        $def = $accountdata[$srv];
    }

    if (!isset($username)) {
        if (is_array($def)) {
            $username = $def["username"];
        } else {
            echo "Error: username not set\n";
        }
    }
    if (!isset($password)) {
        if (is_array($def)) {
            $password = $def["password"];
        } else {
            echo "Error: password not set\n";
        }
    }

    return [$username, $password];
}

function discover(string $srv, ?string $usr = null, ?string $pw = null): bool
{
    list($username, $password) = set_credentials($srv, $usr, $pw);
    $retval = false;

    if (isset($username) && isset($password)) {
        echo "Discover($srv, $username, $password)\n";

        $discover = new CardDavDiscovery(["debugfile" => "http.log"]);
        $discover->discoverAddressbooks($srv, $username, $password);
        $retval = true;
    }

    return $retval;
}

while ($cmd = readline("> ")) {
    $command_ok = false;

    $tokens = explode(" ", $cmd);

    $command = array_shift($tokens);
    switch ($command) {
        case "discover":
            if (count($tokens) > 0) {
                $command_ok = call_user_func_array('MStilkerich\CardDavClient\discover', $tokens);
            } else {
                echo "Usage: discover <servername> [<username>] [<password>]\n";
            }
            break;
        default:
            echo "Unknown command $command\n";
            break;
    }


    if ($command_ok) {
        readline_add_history($cmd);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

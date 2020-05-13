<?php

/**
 * Simple CardDAV Shell, mainly for debugging the library.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Shell;

use MStilkerich\CardDavClient\{AddressbookCollection, CardDavDiscovery};

class Shell
{
    /** @var array */
    private $accounts;

    /** @var AddressbookCollection[] */
    private $addressbooks = [];

    public function __construct(array $accountdata = [])
    {
        $this->accounts = $accountdata;
    }

    private function addAccount(string $name, string $srv, string $usr, string $pw): bool
    {
        $ret = false;

        if (key_exists($name, $this->accounts)) {
            echo "Account named $name already exists!\n";
        } else {
            $this->accounts[$name] = [
                'server'   => $srv,
                'username' => $usr,
                'password' => $pw
            ];
            $ret = true;
        }

        return $ret;
    }

    private function discoverAddressbooks(string $accountName): bool
    {
        $retval = false;

        if (isset($this->accounts[$accountName])) {
            [ 'server' => $srv, 'username' => $username, 'password' => $password ] = $this->accounts[$accountName];
            echo "Discover($srv, $username, $password)\n";

            $discover = new CardDavDiscovery(["debugfile" => "http.log"]);
            $abooks = $discover->discoverAddressbooks($srv, $username, $password);
            foreach ($abooks as $abook) {
                echo "Found addressbook: " . (string) $abook . "\n";
                $this->addressbooks[] = $abook;
            }
            $retval = true;
        } else {
            echo "Unknown account $accountName\n";
        }

        return $retval;
    }

    public function run(): void
    {
        while ($cmd = readline("> ")) {
            $command_ok = false;

            $tokens = preg_split("/\s+/", $cmd);

            $command = array_shift($tokens);
            switch ($command) {
                case "discover":
                    if (count($tokens) > 0) {
                        $command_ok = call_user_func_array([$this, 'discoverAddressbooks'], $tokens);
                    } else {
                        echo "Usage: discover <accountname>\n";
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
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

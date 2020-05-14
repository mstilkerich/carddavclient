<?php

/**
 * Simple CardDAV Shell, mainly for debugging the library.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Shell;

use MStilkerich\CardDavClient\{AddressbookCollection, CardDavDiscovery};

class Shell
{
    private const HISTFILE = ".davshell_history";

    private const COMMANDS = [
        'help' => [
            'synopsis' => 'Lists available commands or displays help on a specific command',
            'usage'    => 'Usage: discover <accountname>',
            'help'     => "If no command is specified, prints a list of available commands,\n"
                . "otherwise prints help on the specified command.",
            'callback' => 'showHelp',
            'minargs'  => 0
        ],
        'discover' => [
            'synopsis' => 'Discovers the available addressbooks in a specified CardDAV account',
            'usage'    => 'Usage: discover <accountname>',
            'help'     => "Discovers the available addressbooks in the specified account using the mechanisms\n"
                . "described by RFC6764 (DNS SRV/TXT lookups, /.well-known URI lookup, plus default locations).",
            'callback' => 'discoverAddressbooks',
            'minargs'  => 1
        ],
        'accounts' => [
            'synopsis' => 'Lists the available accounts',
            'usage'    => 'Usage: accounts [-p]',
            'help'     => "Lists the available accounts.\n"
                . "Option -p: Include the passwords with the output",
            'callback' => 'listAccounts',
            'minargs'  => 0
        ],
        'add_account' => [
            'synopsis' => 'Adds an account',
            'usage'    => 'Usage: add_account <name> <server> <username> <password>',
            'help'     => "Adds a new account to the list of accounts."
                . "name:   An arbitrary (but unique) name that the account is referenced by within this shell.\n"
                . "server: A servername or URI used as the basis for discovering addressbooks in the account.\n"
                . "username: Username used to authenticate with the server.\n"
                . "password: Password used to authenticate with the server.\n",
            'callback' => 'addAccount',
            'minargs'  => 4
        ],
        'addressbooks' => [
            'synopsis' => 'Lists the available addressbooks',
            'usage'    => 'Usage: accounts [<accountname>]',
            'help'     => "Lists the available addressbooks for the specified account.\n"
                . "If no account is specified, lists the addressbooks for all accounts. The list includes an"
                . "identifier for each addressbooks to be used within this shell to reference this addressbook in"
                . "operations",
            'callback' => 'listAddressbooks',
            'minargs'  => 0
        ],
        'show_addressbook' => [
            'synopsis' => 'Shows detailed information on the given addressbook.',
            'usage'    => 'Usage: show_addressbook [<addressbook_id>]',
            'help'     => "addressbook_id: Identifier of the addressbook as provided by the \"addressbooks\" command.",
            'callback' => 'showAddressbook',
            'minargs'  => 1
        ],
    ];

    /** @var array */
    private $accounts;

    public function __construct(array $accountdata = [])
    {
        $this->accounts = $accountdata;
    }

    private function listAccounts(string $opt = ""): bool
    {
        $showPw = ($opt == "-p");

        foreach ($this->accounts as $name => $accountInfo) {
            echo "Account $name\n";
            echo "    Server:   " . $accountInfo['server'] . "\n";
            echo "    Username: " . $accountInfo['username'] . "\n";
            if ($showPw) {
                echo "    Password: " . $accountInfo['password'] . "\n";
            }
        }

        return true;
    }

    private static function commandCompletion(string $word, int $index): array
    {
        // FIXME to be done
        //Get info about the current buffer
        $rl_info = readline_info();

        // Figure out what the entire input is
        $full_input = substr($rl_info['line_buffer'], 0, $rl_info['end']);

        $matches = array();

        // Get all matches based on the entire input buffer
        //foreach (phrases_that_begin_with($full_input) as $phrase) {
            // Only add the end of the input (where this word begins)
            // to the matches array
        //    $matches[] = substr($phrase, $index);
        //}

        return $matches;
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

    private function showHelp(string $command = null): bool
    {
        $ret = false;

        if (isset($command)) {
            if (isset(self::COMMANDS[$command])) {
                echo "$command - " . self::COMMANDS[$command]['synopsis'] . "\n";
                echo self::COMMANDS[$command]['usage'] . "\n";
                echo self::COMMANDS[$command]['help'] . "\n";
                $ret = true;
            } else {
                echo "Unknown command: $command\n";
            }
        } else {
            foreach (self::COMMANDS as $command => $commandDesc) {
                echo "$command: " . $commandDesc['synopsis'] . "\n";
            }
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

            $this->accounts[$accountName]['addressbooks'] = [];
            foreach ($abooks as $abook) {
                echo "Found addressbook: " . (string) $abook . "\n";
                $this->accounts[$accountName]['addressbooks'][] = $abook;
            }
            $retval = true;
        } else {
            echo "Unknown account $accountName\n";
        }

        return $retval;
    }

    private function listAddressbooks(string $accountName = null): bool
    {
        $ret = false;

        if (isset($accountName)) {
            if (isset($this->accounts[$accountName])) {
                $accounts = [ $accountName => $this->accounts[$accountName] ];
                $ret = true;
            } else {
                echo "Unknown account $accountName\n";
            }
        } else {
            $accounts = $this->accounts;
            $ret = true;
        }

        foreach ($this->accounts as $name => $accountInfo) {
            $id = 0;

            foreach (($accountInfo["addressbooks"] ?? []) as $abook) {
                echo "$name@$id - " . (string) $abook . "\n";
                ++$id;
            }
        }

        return $ret;
    }

    private function showAddressbook(string $abookId): bool
    {
        $ret = false;

        if (preg_match("/^(.*)@(\d+)$/", $abookId, $matches)) {
            [, $accountName, $abookIdx] = $matches;

            $abook = $this->accounts[$accountName]["addressbooks"][$abookIdx] ?? null;

            if (isset($abook)) {
                echo $abook->getDetails();
                $ret = true;
            } else {
                echo "Invalid addressbook ID $abookId\n";
            }
        } else {
            echo "Invalid addressbook ID $abookId\n";
        }

        return $ret;
    }

    public function run(): void
    {
        readline_read_history(self::HISTFILE);

        while ($cmd = readline("> ")) {
            $cmd = trim($cmd);
            $tokens = preg_split("/\s+/", $cmd);
            $command = array_shift($tokens);

            if (isset(self::COMMANDS[$command])) {
                if (count($tokens) >= self::COMMANDS[$command]['minargs']) {
                    if (call_user_func_array([$this, self::COMMANDS[$command]['callback']], $tokens)) {
                        readline_add_history($cmd);
                    }
                } else {
                    echo "Too few arguments to $command.\n";
                    echo self::COMMANDS[$command]['usage'] . "\n";
                }
            } else {
                echo "Unknown command $command. Type \"help\" for a list of available commands\n";
            }
        }

        readline_write_history(self::HISTFILE);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

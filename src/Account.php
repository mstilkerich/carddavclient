<?php

/**
 * This is a simple class to represent credentials to a CardDAV Server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

class Account
{
    /********* PROPERTIES *********/

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var string */
    private $baseUri;

    /********* PUBLIC FUNCTIONS *********/
    public function __construct(string $baseUri, string $username, string $password)
    {
        $this->baseUri  = $baseUri;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Provides a CardDavClient object to interact with the server for this account.
     *
     * @param array $davClientOptions
     *  Default options for the CardDavClient object.
     *
     * @return CardDavClient
     *  A CardDavClient object to interact with the server for this account.
     */
    public function getClient(array $davClientOptions = []): CardDavClient
    {
        $davClient = new CardDavClient($this->baseUri, $this->username, $this->password, $davClientOptions);
        return $davClient;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

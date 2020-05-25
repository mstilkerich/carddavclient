<?php

/**
 * Objects of this class represent a collection on a WebDAV server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

class WebDavCollection
{
    /** @var string URI of the Collection */
    protected $uri;

    /** @var array WebDAV properties of the Collection */
    protected $props;

    /** @var Account The CardDAV account this WebDAV resource is associated/accessible with. */
    protected $account;

    private const PROPNAMES = [
        "{" . CardDavClient::NSDAV . "}resourcetype",
        "{" . CardDavClient::NSDAV . "}displayname",
        "{" . CardDavClient::NSDAV . "}supported-report-set",
        "{" . CardDavClient::NSDAV . "}sync-token"
    ];

    public function __construct(string $uri, Account $account, array $props = null)
    {
        $this->uri = $uri;
        $this->account = $account;

        if (!isset($props)) {
            $propNames = $this->getNeededCollectionPropertyNames();
            $client = $this->getClient();
            $result = $client->findProperties($this->uri, $propNames);
            if (isset($result[0]["props"])) {
                $this->props = $result[0]["props"];
            } else {
                throw new \Exception("Failed to retrieve properties for collection " . $this->uri);
            }
        } else {
            $this->props = $props;
        }
    }

    public function getClient(array $davClientOptions = []): CardDavClient
    {
        return $this->account->getClient($davClientOptions, $this->uri);
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    protected function getNeededCollectionPropertyNames(): array
    {
        return self::PROPNAMES;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

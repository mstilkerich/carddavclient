<?php

/**
 * Objects of this class represent a collection on a WebDAV server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class WebDavCollection implements \JsonSerializable
{
    /** @var string URI of the Collection */
    protected $uri;

    /** @var array WebDAV properties of the Collection */
    protected $props;

    /** @var Account The CardDAV account this WebDAV resource is associated/accessible with. */
    protected $account;

    private const PROPNAMES = [
        XmlEN::RESTYPE,
        XmlEN::SYNCTOKEN,
        XmlEN::ADD_MEMBER
    ];

    public function __construct(string $uri, Account $account)
    {
        $this->uri = $uri;
        $this->account = $account;

        $propNames = $this->getNeededCollectionPropertyNames();
        $client = $this->getClient();
        $result = $client->findProperties($this->uri, $propNames);
        if (isset($result[0]["props"])) {
            $this->props = $result[0]["props"];
        } else {
            throw new \Exception("Failed to retrieve properties for collection " . $this->uri);
        }
    }

    public function jsonSerialize(): array
    {
        return [ "uri" => $this->uri ];
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getClient(array $davClientOptions = []): CardDavClient
    {
        return $this->account->getClient($davClientOptions, $this->uri);
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function downloadResource(string $uri): array
    {
        $client = $this->getClient();
        $response = $client->getResource($uri);
        $body = (string) $response->getBody(); // checked to be present in CardDavClient::getResource()
        return [ 'body' => $body ];
    }

    protected function getNeededCollectionPropertyNames(): array
    {
        return self::PROPNAMES;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

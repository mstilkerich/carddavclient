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

    public function __construct(string $uri, Account $account, array $props)
    {
        $this->uri = $uri;
        $this->account = $account;
        $this->props = $props;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

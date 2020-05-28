<?php

/**
 * Synchronization handler that collects the synchronization result to local variables for
 * later processing.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Shell;

use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavClient\Services\SyncHandler;

class ShellSyncHandlerCollectChanges implements SyncHandler
{
    /** @var array */
    private $changedCards = [];

    /** @var array */
    private $deletedCards = [];

    public function addressObjectChanged(string $uri, string $etag, VCard $card): void
    {
        $uid = (string) $card->UID;
        Shell::$logger->debug("Existing object: $uri ($uid)");
        $this->changedCards[$uid] = [
            'uri'   => $uri,
            'etag'  => $etag,
            'vcard' => $card
        ];
    }

    public function addressObjectDeleted(string $uri): void
    {
        Shell::$logger->error("Deleted object: $uri not expected with this Sync Handler");
        $this->deletedCards[] = $uri;
    }

    /**
     * This sync handler is meant to collect all the changed cards in an addressbook, which we
     * do by emulating an empty/unsynchronized local cache.
     */
    public function getExistingVCardETags(): array
    {
        return [];
    }

    public function getCardByUID(string $uid): ?array
    {
        return $this->changedCards[$uid] ?? null;
    }

    public function getChangedCards(): array
    {
        return $this->changedCards;
    }

    public function getRemovedCards(): array
    {
        return $this->deletedCards;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

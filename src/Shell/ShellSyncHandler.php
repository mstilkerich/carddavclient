<?php

/**
 * Synchronization handler of the CardDAV Shell
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Shell;

use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\{AddressbookCollection, CardDavSyncHandler};

class ShellSyncHandler implements CardDavSyncHandler
{
    public function addressObjectChanged(string $uri, string $etag, VCard $card): void
    {
        Shell::$logger->info("Changed object: $uri (" . $card->FN . ")");
    }

    public function addressObjectDeleted(string $uri): void
    {
        Shell::$logger->info("Deleted object: $uri");
    }

    public function getExistingVCardETags(): array
    {
        return [];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

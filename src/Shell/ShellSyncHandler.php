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
        echo "Changed object: $uri (" . $card->FN . ")\n";
    }

    public function addressObjectDeleted(string $uri): void
    {
        echo "Deleted object: $uri\n";
    }

    public function getExistingETagForVCard(string $uri): ?string
    {
        return null;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

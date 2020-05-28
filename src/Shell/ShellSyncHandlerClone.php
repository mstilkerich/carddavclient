<?php

/**
 * Synchronization handler that clones the changes of the given addressbook to another addressbook.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Shell;

use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavClient\Services\SyncHandler;

class ShellSyncHandlerClone implements SyncHandler
{
    /** @var AddressbookCollection */
    private $destAbook;

    /** @var ShellSyncHandlerCollectChanges */
    private $destState;

    /**
     * @var bool Whether to only clone / add cards that do not exist in destination (rest left alone)
     */
    private $newOnly;

    public function __construct(
        AddressbookCollection $target,
        ShellSyncHandlerCollectChanges $destState,
        bool $newOnly = false
    ) {
        $this->destAbook = $target;
        $this->destState = $destState;
        $this->newOnly = $newOnly;
    }

    public function addressObjectChanged(string $uri, string $etag, VCard $card): void
    {
        $uid = (string) $card->UID;

        $existingCard = $this->destState->getCardByUID($uid);

        if (isset($existingCard)) {
            $fn = $existingCard["vcard"]->FN;

            if ($this->newOnly) {
                Shell::$logger->debug("Skip existing card: $uid ($fn)");
            } else {
                Shell::$logger->debug("Overwriting existing card: $uid ($fn)");
                $this->destAbook->updateCard($existingCard["uri"], $card, $existingCard["etag"]);
            }
        } else {
            [ "uri" => $newuri ] = $this->destAbook->createCard($card);
            Shell::$logger->debug("Cloned object: $uri (" . $card->FN . ") to $newuri");
        }
    }

    public function addressObjectDeleted(string $uri): void
    {
        Shell::$logger->error("Deleted object: $uri not expected during clone");
    }

    public function getExistingVCardETags(): array
    {
        return [];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

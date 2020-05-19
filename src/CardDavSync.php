<?php

/**
 * Class CardDavSync
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

class CardDavSync
{
    /********* PROPERTIES *********/

    /** @var array Options to the HttpClient */
    private $davClientOptions = [];

    /********* PUBLIC FUNCTIONS *********/
    public function __construct(array $options = [])
    {
        if (key_exists("debugfile", $options)) {
            $this->davClientOptions["debugfile"] = $options["debugfile"];
        }
    }

    /**
     * Performs a synchronization of the given addressbook.
     *
     * @return string
     *  The sync token corresponding to the just synchronized (or slightly earlier) state of the collection.
     */
    public function synchronize(
        AddressbookCollection $abook,
        CardDavSyncHandler $handler,
        array $requestedVCardProps = [],
        string $prevSyncToken = ""
    ): string {

        // DETERMINE WHICH ADDRESS OBJECTS HAVE CHANGED
        // If sync-collection is supported by the server, attempt synchronization using the report

        $newSyncToken = null;
        $client = $abook->getAccount()->getClient($this->davClientOptions);

        if ($abook->supportsSyncCollection()) {
            $syncresult = $client->syncCollection($abook->getUri(), $prevSyncToken);
        }

        // If sync-collection failed or is not supported, determine changes using getctag property, PROPFIND and address
        // objects' etags
        if (!isset($newSyncToken)) {
            // if server supports getctag, take a short cut if nothing changed
//            $newSyncToken = $this->getCTag($abook);

//          if (empty($prevSyncToken) || empty($newSyncToken) || ($prevSyncToken !== $newSyncToken)) {
//              $this->getCardEtags($abook, $prevSyncToken);
//          }
        }

        // DELETE THE DELETED ADDRESS OBJECTS

        // FETCH THE CHANGED ADDRESS OBJECTS
        return "";
    }

    /********* PRIVATE FUNCTIONS *********/
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

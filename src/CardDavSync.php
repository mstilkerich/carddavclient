<?php

/**
 * Class CardDavSync
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

class CardDavSync
{
    /********* PROPERTIES *********/


    /********* PUBLIC FUNCTIONS *********/

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
        $client = $abook->getClient();

        $syncResult = null;

        // DETERMINE WHICH ADDRESS OBJECTS HAVE CHANGED
        // If sync-collection is supported by the server, attempt synchronization using the report
        if ($abook->supportsSyncCollection()) {
            $syncResult = $this->syncCollection($client, $abook, $prevSyncToken);
        }

        // If sync-collection failed or is not supported, determine changes using getctag property, PROPFIND and address
        // objects' etags
        if (!isset($syncResult)) {
            // if server supports getctag, take a short cut if nothing changed
//            $newSyncToken = $this->getCTag($abook);

//          if (empty($prevSyncToken) || empty($newSyncToken) || ($prevSyncToken !== $newSyncToken)) {
//              $this->getCardEtags($abook, $prevSyncToken);
//          }
        }

        if (!isset($syncResult)) {
            throw new \Exception('CardDavSync could not determine the changes for synchronization');
        }

        // DELETE THE DELETED ADDRESS OBJECTS
        foreach ($syncResult->deletedObjects as $delUri) {
            $handler->addressObjectDeleted($delUri);
        }

        // FETCH THE CHANGED ADDRESS OBJECTS
        if ($abook->supportsMultiGet()) {
            $this->multiGetChanges($client, $abook, $syncResult, $requestedVCardProps);
        }

        if ($syncResult->createVCards() === false) {
            Config::$logger->warning("Not for all changed objects, the VCard data was provided by the server");
        }

        foreach ($syncResult->changedObjects as $obj) {
            $handler->addressObjectChanged($obj["uri"], $obj["etag"], $obj["vcard"]);
        }
        return "";
    }

    /********* PRIVATE FUNCTIONS *********/
    private function syncCollection(
        CardDavClient $client,
        AddressbookCollection $abook,
        string $prevSyncToken
    ): CardDavSyncResult {
        $abookUri = $abook->getUri();
        $multistatus = $client->syncCollection($abookUri, $prevSyncToken);

        if (!isset($multistatus->synctoken)) {
            throw new \Exception("No sync token contained in response to sync-collection REPORT.");
        }

        $syncResult = new CardDavSyncResult($multistatus->synctoken);

        foreach ($multistatus->responses as $response) {
            $respUri = $response->href;

            if (CardDavClient::compareUrlPaths($respUri, $abookUri)) {
                // If the result set is truncated, the response MUST use status code 207 (Multi-Status), return a
                // DAV:multistatus response body, and indicate a status of 507 (Insufficient Storage) for the
                // request-URI.
                if (isset($response->status) && stripos($response->status, " 507 ") !== false) {
                    $syncResult->syncAgain = true;
                } else {
                    Config::$logger->debug("Ignoring response on addressbook itself");
                }

            // For members that have been removed, the DAV:response MUST contain one DAV:status with a value set to
            // "404 Not Found" and MUST NOT contain any DAV:propstat element.
            } elseif (isset($response->status) && stripos($response->status, " 404 ") !== false) {
                $syncResult->deletedObjects[] = $respUri;

            // For members that have changed (i.e., are new or have had their mapped resource modified), the
            // DAV:response MUST contain at least one DAV:propstat element and MUST NOT contain any DAV:status
            // element.
            } elseif (!empty($response->propstat)) {
                foreach ($response->propstat as $propstat) {
                    if (isset($propstat->status) && stripos($propstat->status, " 200 ") !== false) {
                        $syncResult->changedObjects[] = [
                            'uri' => $respUri,
                            'etag' => $propstat->prop->props["{DAV:}getetag"]
                        ];
                    }
                }
            } else {
                Config::$logger->warning("Unexpected response element in sync-collection result\n");
            }
        }

        return $syncResult;
    }

    private function multiGetChanges(
        CardDavClient $client,
        AddressbookCollection $abook,
        CardDavSyncResult $syncResult,
        array $requestedVCardProps
    ): void {
        $requestedUris = array_map(
            function (array $changeObj): string {
                return $changeObj["uri"];
            },
            $syncResult->changedObjects
        );

        $multistatus = $client->multiGet($abook->getUri(), $requestedUris, $requestedVCardProps);

        foreach ($multistatus->responses as $response) {
            $respUri = $response->href;

            if (!empty($response->propstat)) {
                foreach ($response->propstat as $propstat) {
                    if (isset($propstat->status) && stripos($propstat->status, " 200 ") !== false) {
                        $syncResult->addVcfForChangedObj(
                            $respUri,
                            $propstat->prop->props["{DAV:}getetag"],
                            $propstat->prop->props["{urn:ietf:params:xml:ns:carddav}address-data"]
                        );
                    }
                }
            } else {
                Config::$logger->warning("Unexpected response element in multiget result\n");
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

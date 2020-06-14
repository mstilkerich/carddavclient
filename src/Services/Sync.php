<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (C) 2020 Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of PHP-CardDavClient.
 *
 * PHP-CardDavClient is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP-CardDavClient is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP-CardDavClient.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Class Sync
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Services;

use MStilkerich\CardDavClient\{AddressbookCollection, CardDavClient, Config};
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class Sync
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
        SyncHandler $handler,
        array $requestedVCardProps = [],
        string $prevSyncToken = ""
    ): string {
        $client = $abook->getClient();

        $syncResult = null;

        // DETERMINE WHICH ADDRESS OBJECTS HAVE CHANGED
        // If sync-collection is supported by the server, attempt synchronization using the report
        if ($abook->supportsSyncCollection()) {
            Config::$logger->debug("Attempting sync using sync-collection report of " . $abook->getUri());

            try {
                $syncResult = $this->syncCollection($client, $abook, $prevSyncToken);
            } catch (\Exception $e) {
                Config::$logger->error("sync-collection REPORT produced exception", [ 'exception' => $e ]);
            }
        }

        // If sync-collection failed or is not supported, determine changes using getctag property, PROPFIND and address
        // objects' etags
        if (!isset($syncResult)) {
            // if server supports getctag, take a short cut if nothing changed
            $newSyncToken = $abook->getCTag();

            if (empty($prevSyncToken) || empty($newSyncToken) || ($prevSyncToken !== $newSyncToken)) {
                Config::$logger->debug("Attempting sync by ETag comparison against local state of " . $abook->getUri());
                $syncResult = $this->determineChangesViaETags($client, $abook, $handler);
            } else {
                Config::$logger->debug("Skipping sync of up-to-date addressbook (by ctag) " . $abook->getUri());
                $syncResult = new SyncResult($prevSyncToken);
            }
        }

        // DELETE THE DELETED ADDRESS OBJECTS
        foreach ($syncResult->deletedObjects as $delUri) {
            $handler->addressObjectDeleted($delUri);
        }

        // FETCH THE CHANGED ADDRESS OBJECTS
        if (!empty($syncResult->changedObjects)) {
            if ($abook->supportsMultiGet()) {
                $this->multiGetChanges($client, $abook, $syncResult, $requestedVCardProps);
            }

            // try to manually fill all VCards where multiget did not provide VCF data
            foreach ($syncResult->changedObjects as &$objref) {
                if (!isset($objref["vcf"])) {
                    Config::$logger->debug("Fetching " . $objref['uri'] . " via GET");
                    print_r($objref);
                    [
                        'etag' => $objref["etag"],
                        'vcf' => $objref["vcf"],
                        'vcard' => $objref["vcard"]
                    ] = $abook->getCard($objref["uri"]);
                }
            }

            if ($syncResult->createVCards() === false) {
                Config::$logger->warning("Not for all changed objects, the VCard data was provided by the server");
            }

            foreach ($syncResult->changedObjects as $obj) {
                $handler->addressObjectChanged($obj["uri"], $obj["etag"], $obj["vcard"]);
            }
        }

        $handler->finalizeSync();

        return $syncResult->syncToken;
    }

    /********* PRIVATE FUNCTIONS *********/
    private function syncCollection(
        CardDavClient $client,
        AddressbookCollection $abook,
        string $prevSyncToken
    ): SyncResult {
        $abookUrl = $abook->getUri();
        $multistatus = $client->syncCollection($abookUrl, $prevSyncToken);

        if (!isset($multistatus->synctoken)) {
            throw new \Exception("No sync token contained in response to sync-collection REPORT.");
        }

        $syncResult = new SyncResult($multistatus->synctoken);

        foreach ($multistatus->responses as $response) {
            $respUri = $response->href;

            if (CardDavClient::compareUrlPaths($respUri, $abookUrl)) {
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
                            'etag' => $propstat->prop->props[XmlEN::GETETAG]
                        ];
                    }
                }
            } else {
                Config::$logger->warning("Unexpected response element in sync-collection result\n");
            }
        }

        return $syncResult;
    }

    private function determineChangesViaETags(
        CardDavClient $client,
        AddressbookCollection $abook,
        SyncHandler $handler
    ): SyncResult {
        $abookUrl = $abook->getUri();

        $responses = $client->findProperties($abookUrl, [ XmlEN::GETCTAG, XmlEN::GETETAG, XmlEN::SYNCTOKEN ], "1");

        // array of local VCards basename (i.e. only the filename) => etag
        $localCacheState = $handler->getExistingVCardETags();

        $newSyncToken = "";
        $changes = [];
        foreach ($responses as $response) {
            $url = $response["uri"];
            $props = $response["props"];

            if (CardDavClient::compareUrlPaths($url, $abookUrl)) {
                $newSyncToken = $props[XmlEN::GETCTAG] ?? $props[XmlEN::SYNCTOKEN] ?? "";
                if (empty($newSyncToken)) {
                    Config::$logger->notice("The server provides no token that identifies the addressbook version");
                }
            } else {
                $etag = $props[XmlEN::GETETAG] ?? null;
                if (!isset($etag)) {
                    Config::$logger->warning("Server did not provide an ETag for $url, skipping");
                } else {
                    ['path' => $uri] = \Sabre\Uri\parse($url);

                    // add new or changed cards to the list of changes
                    if (
                        (!isset($localCacheState[$uri]))
                        || ($etag !== $localCacheState[$uri])
                    ) {
                        $changes[] = [
                            'uri' => $uri,
                            'etag' => $etag
                        ];
                    }

                    // remove seen so that only the unseen remain for removal
                    if (isset($localCacheState[$uri])) {
                        unset($localCacheState[$uri]);
                    }
                }
            }
        }
        $syncResult = new SyncResult($newSyncToken);
        $syncResult->deletedObjects = array_keys($localCacheState);
        $syncResult->changedObjects = $changes;

        return $syncResult;
    }

    private function multiGetChanges(
        CardDavClient $client,
        AddressbookCollection $abook,
        SyncResult $syncResult,
        array $requestedVCardProps
    ): void {
        $requestedUris = array_map(
            function (array $changeObj): string {
                return $changeObj["uri"];
            },
            $syncResult->changedObjects
        );

        $multistatus = $client->multiGet($abook->getUri(), $requestedUris, $requestedVCardProps);

        $results = [];
        foreach ($multistatus->responses as $response) {
            $respUri = $response->href;

            if (!empty($response->propstat)) {
                foreach ($response->propstat as $propstat) {
                    if (isset($propstat->status) && stripos($propstat->status, " 200 ") !== false) {
                        Config::$logger->debug("VCF for $respUri received via multiget");
                        $results[$respUri] = [
                            "etag" => $propstat->prop->props[XmlEN::GETETAG],
                            "vcf" => $propstat->prop->props[XmlEN::ADDRDATA]
                        ];
                    }
                }
            } else {
                Config::$logger->warning("Unexpected response element in multiget result\n");
            }
        }

        foreach ($syncResult->changedObjects as &$objref) {
            $couri = $objref["uri"];
            if (isset($results[$couri])) {
                $objref["etag"] = $results[$couri]["etag"];
                $objref["vcf"] = $results[$couri]["vcf"];
            } else {
                Config::$logger->warning("Server did not return data for $couri");
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

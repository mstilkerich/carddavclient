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
 * Objects of this class represent an addressbook collection on a WebDAV
 * server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Sabre\VObject\UUIDUtil;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class AddressbookCollection extends WebDavCollection
{
    private const PROPNAMES = [
        XmlEN::DISPNAME,
        XmlEN::GETCTAG,
        XmlEN::SUPPORTED_ADDRDATA,
        XmlEN::ABOOK_DESC,
        XmlEN::MAX_RESSIZE
    ];

    /**
     * Returns a displayname for the addressbook.
     *
     * If a server-side displayname exists in the DAV:displayname property, it is returned. Otherwise, the last
     * component of the URL is returned. This is suggested by RFC6352 to compose the addressbook name.
     *
     * @return string Name of the addressbook
     */
    public function getName(): string
    {
        $props = $this->getProperties();
        return $props[XmlEN::DISPNAME] ?? basename($this->uri);
    }

    public function __toString(): string
    {
        $desc  = $this->getName() . " (" . $this->uri . ")";
        return $desc;
    }

    public function getDetails(): string
    {
        $desc  = "Addressbook " . $this->getName() . "\n";
        $desc .= "    URI: " . $this->uri . "\n";

        $props = $this->getProperties();
        foreach ($props as $propName => $propVal) {
            $desc .= "    $propName: ";

            if (is_array($propVal)) {
                if (isset($propVal[0]) && is_array($propVal[0])) {
                    $propVal = array_map(
                        function (array $subarray): string {
                            return implode(" ", $subarray);
                        },
                        $propVal
                    );
                }
                $desc .= implode(", ", $propVal);
            } else {
                $desc .= $propVal;
            }

            $desc .= "\n";
        }

        return $desc;
    }

    public function supportsMultiGet(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_MULTIGET);
    }

    public function getCTag(): ?string
    {
        $props = $this->getProperties();
        return $props[XmlEN::GETCTAG] ?? null;
    }

    /**
     * Retrieves an address object from the addressbook collection and parses it to a VObject.
     *
     * @param string $uri
     *  URI of the address object to fetch
     * @return array
     *  Associative array with keys
     *   - etag(string): Entity tag of the returned card
     *   - vcf(string): VCard as string
     *   - vcard(VCard): VCard as Sabre/VObject VCard
     */
    public function getCard(string $uri): array
    {
        $client = $this->getClient();
        $response = $client->getAddressObject($uri);
        $response["vcard"] = \Sabre\VObject\Reader::read($response["vcf"]);
        return $response;
    }

    /**
     * Deletes a VCard from the addressbook.
     *
     * @param string $uri The URI of the VCard to be deleted.
     */
    public function deleteCard(string $uri): void
    {
        $client = $this->getClient();
        $client->deleteResource($uri);
    }

    /**
     * Creates a new VCard in the addressbook.
     *
     * If the given VCard lacks the mandatory UID property, one will be generated. If the server provides an add-member
     * URI, the new card will be POSTed to that URI. Otherwise, the function attempts to store the card do a URI whose
     * last path component (filename) is derived from the UID of the VCard.
     *
     * @param VCard $vcard The VCard to be stored.
     */
    public function createCard(VCard $vcard): array
    {
        $props = $this->getProperties();

        // Add UID if not present
        if (empty($vcard->select("UID"))) {
            $uuid = UUIDUtil::getUUID();
            Config::$logger->notice("Adding missing UID property to new VCard ($uuid)");
            $vcard->UID = $uuid;
        }

        // Assert validity of the Card for CardDAV, including valid UID property
        $this->validateCard($vcard);

        $client = $this->getClient();

        $addMemberUrl = $props[XmlEN::ADD_MEMBER] ?? null;

        if (isset($addMemberUrl)) {
            $newResInfo = $client->createResource(
                $vcard->serialize(),
                $client->absoluteUrl($addMemberUrl),
                true
            );
        } else {
            $newResInfo = $client->createResource(
                $vcard->serialize(),
                $client->absoluteUrl($vcard->UID . ".vcf")
            );
        }

        return $newResInfo;
    }

    /**
     * Updates an existing VCard of the addressbook.
     *
     * The update request to the server will be made conditional depending on that the provided ETag value of the card
     * matches that on the server, meaning that the card has not been changed on the server in the meantime.
     *
     * @param string $uri The URI of the card to update.
     * @param VCard $vcard The updated VCard to be stored.
     * @param string $etag The ETag of the card that was originally retrieved and modified.
     * @return ?string Returns the ETag of the updated card if provided by the server, null otherwise. If null is
     *                 returned, it must be assumed that the server stored the card with modifications and the card
     *                 should be read back from the server (this is a good idea anyway).
     */
    public function updateCard(string $uri, VCard $vcard, string $etag): ?string
    {
        // Assert validity of the Card for CardDAV, including valid UID property
        $this->validateCard($vcard);

        $client = $this->getClient();
        $etag = $client->updateResource($vcard->serialize(), $uri, $etag);

        return $etag;
    }


    /**
     * Validates a VCard before sending it to a CardDAV server.
     *
     * @param VCard $vcard The VCard to be validated.
     */
    protected function validateCard(VCard $vcard): void
    {
        $hasError = false;
        $errors = "";

        // Assert validity of the Card for CardDAV, including valid UID property
        $validityIssues = $vcard->validate(\Sabre\VObject\Node::PROFILE_CARDDAV | \Sabre\VObject\Node::REPAIR);
        foreach ($validityIssues as $issue) {
            $name = $issue["node"]->name;
            $msg = "Issue with $name of new VCard: " . $issue["message"];

            if ($issue["level"] <= 2) { // warning
                Config::$logger->warning($msg);
            } else { // error
                Config::$logger->error($msg);
                $errors .= "$msg\n";
                $hasError = true;
            }
        }

        if ($hasError) {
            Config::$logger->debug($vcard->serialize());
            throw new \InvalidArgumentException($errors);
        }
    }

    /**
     * Provides the list of property names that should be requested upon call of refreshProperties().
     *
     * @return string[] A list of property names including namespace prefix (e. g. '{DAV:}resourcetype').
     *
     * @see parent::getProperties()
     * @see parent::refreshProperties()
     */
    protected function getNeededCollectionPropertyNames(): array
    {
        $parentPropNames = parent::getNeededCollectionPropertyNames();
        $propNames = array_merge($parentPropNames, self::PROPNAMES);
        return array_unique($propNames);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Sabre\VObject\UUIDUtil;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\XmlElements\{Filter,ResponsePropstat,ResponseStatus};

/**
 * Represents an addressbook collection on a WebDAV server.
 *
 * @psalm-import-type SimpleConditions from Filter
 * @psalm-import-type ComplexConditions from Filter
 *
 * @psalm-type VcardValidateResult = array{
 *   level: int,
 *   message: string,
 *   node: \Sabre\VObject\Component | \Sabre\VObject\Property
 * }
 *
 * @package Public\Entities
 */
class AddressbookCollection extends WebDavCollection
{
    /**
     * List of properties to query in refreshProperties() and returned by getProperties().
     * @psalm-var list<string>
     * @see WebDavResource::getProperties()
     * @see WebDavResource::refreshProperties()
     */
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
     * @api
     */
    public function getName(): string
    {
        $props = $this->getProperties();
        return $props[XmlEN::DISPNAME] ?? $this->getBasename();
    }

    /**
     * Returns value of the DAV:displayname for the addressbook collection.
     *
     * @return ?string DAV:displayname of the addressbook collection, null if not set.
     * @api
     */
    public function getDisplayName(): ?string
    {
        $props = $this->getProperties();
        return $props[XmlEN::DISPNAME] ?? null;
    }

    /**
     * Returns value of the CARDDAV:addressbook-description for the addressbook collection.
     *
     * @return ?string CARDDAV:addressbook-description of the addressbook collection, null if not set.
     * @api
     */
    public function getDescription(): ?string
    {
        $props = $this->getProperties();
        return $props[XmlEN::ABOOK_DESC] ?? null;
    }

    /**
     * Provides a stringified representation of this addressbook (name and URI).
     *
     * Note that the result of this function is meant for display, not parsing. Thus the content and formatting of the
     * text may change without considering backwards compatibility.
     */
    public function __toString(): string
    {
        $desc  = $this->getName() . " (" . $this->uri . ")";
        return $desc;
    }

    /**
     * Provides the details for this addressbook as printable text.
     *
     * Note that the result of this function is meant for display, not parsing. Thus the content and formatting of the
     * text may change without considering backwards compatibility.
     *
     * @api
     */
    public function getDetails(): string
    {
        $desc  = "Addressbook " . $this->getName() . "\n";
        $desc .= "    URI: " . $this->uri . "\n";

        $props = $this->getProperties();
        foreach ($props as $propName => $propVal) {
            $desc .= "    " . $this->shortenXmlNamespacesForPrinting($propName) . ": ";

            switch (gettype($propVal)) {
                case 'integer':
                case 'string':
                    $desc .= $this->shortenXmlNamespacesForPrinting((string) $propVal);
                    break;

                case 'array':
                    // can be list of strings or list of array<string,string>
                    $sep = "";
                    foreach ($propVal as $v) {
                        $desc .= $sep;
                        $sep = ", ";

                        if (is_string($v)) {
                            $desc .= $this->shortenXmlNamespacesForPrinting($v);
                        } else {
                            $strings = [];
                            $fields = array_keys($v);
                            sort($fields);
                            foreach ($fields as $f) {
                                $strings[] = "$f: $v[$f]";
                            }
                            $desc .= '[' . implode(', ', $strings) . ']';
                        }
                    }
                    break;

                default:
                    $desc .= print_r($propVal, true);
                    break;
            }

            $desc .= "\n";
        }

        return $desc;
    }

    /**
     * Queries whether the server supports the addressbook-multiget REPORT on this addressbook collection.
     *
     * @return bool True if addressbook-multiget is supported for this collection.
     * @api
     */
    public function supportsMultiGet(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_MULTIGET);
    }

    /**
     * Retrieves the getctag property for this addressbook collection (if supported by the server).
     *
     * @return ?string The getctag property, or null if not provided by the server.
     * @api
     */
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
     * @psalm-return array{vcard: VCard, etag: string, vcf: string}
     * @return array<string,mixed> Associative array with keys
     *   - etag(string): Entity tag of the returned card
     *   - vcf(string): VCard as string
     *   - vcard(VCard): VCard as Sabre/VObject VCard
     * @api
     */
    public function getCard(string $uri): array
    {
        $client = $this->getClient();
        $response = $client->getAddressObject($uri);
        $vcard = \Sabre\VObject\Reader::read($response["vcf"]);
        if (!($vcard instanceof VCard)) {
            throw new \Exception("Parsing of string did not result in a VCard object: {$response["vcf"]}");
        }
        $response["vcard"] = $vcard;
        return $response;
    }

    /**
     * Deletes a VCard from the addressbook.
     *
     * @param string $uri The URI of the VCard to be deleted.
     * @api
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
     * URI, the new card will be POSTed to that URI. Otherwise, the function attempts to store the card to a URI whose
     * last path component (filename) is derived from the UID of the VCard.
     *
     * @param VCard $vcard The VCard to be stored.
     * @psalm-return array{uri: string, etag: string}
     * @return array<string,string> Associative array with keys
     *   - uri (string): URI of the new resource if the request was successful
     *   - etag (string): Entity tag of the created resource if returned by server, otherwise empty string.
     * @api
     */
    public function createCard(VCard $vcard): array
    {
        $props = $this->getProperties();

        // Add UID if not present
        if (empty($vcard->select("UID"))) {
            $uid = UUIDUtil::getUUID();
            Config::$logger->notice("Adding missing UID property to new VCard ($uid)");
            $vcard->UID = $uid;
        } else {
            $uid = (string) $vcard->UID;
            // common case for v4 vcards where UID must be a URI
            $uid = str_replace("urn:uuid:", "", $uid);
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
            // restrict to allowed characters
            $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $uid);
            $newResInfo = $client->createResource(
                $vcard->serialize(),
                $client->absoluteUrl("$name.vcf")
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
     * @return ?string On success, returns the ETag of the updated card if provided by the server, an empty string
     *                 otherwise. In the latter case, it must be assumed that the server stored the card with
     *                 modifications and the card should be read back from the server (this is a good idea anyway).
     *                 In case of ETag precondition failure, null is returned. For other errors, an exception is thrown.
     * @api
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
     * Issues an addressbook-query report.
     *
     * @psalm-param SimpleConditions|ComplexConditions $conditions
     * @param array $conditions
     *  The query filter conditions, see {@see Filter::__construct()} for format.
     * @psalm-param list<string> $requestedVCardProps
     * @param array<int,string> $requestedVCardProps
     *  A list of the requested VCard properties. If empty array, the full VCards are requested from the server.
     * @param bool $matchAll
     *  Whether all or any of the conditions needs to match.
     * @param int $limit
     *  Tell the server to return at most $limit results. 0 means no limit.
     *
     * @psalm-return array<string, array{vcard: VCard, etag: string}>
     * @return array<string, array> Returns an array of matched VCards:
     *  - The keys of the array are the URIs of the vcards
     *  - The values are associative arrays with keys etag (type: string) and vcard (type: VCard)
     * @see Filter
     * @since 1.1.0
     * @api
     */
    public function query(
        array $conditions,
        array $requestedVCardProps = [],
        bool $matchAll = false,
        int $limit = 0
    ) {
        $conditions = new Filter($conditions, $matchAll);
        $client = $this->getClient();
        $multistatus = $client->query($this->uri, $conditions, $requestedVCardProps, $limit);

        $results = [];
        foreach ($multistatus->responses as $response) {
            if ($response instanceof ResponsePropstat) {
                $respUri = $response->href;

                foreach ($response->propstat as $propstat) {
                    if (stripos($propstat->status, " 200 ") !== false) {
                        Config::$logger->debug("VCF for $respUri received via query");
                        $vcf = $propstat->prop->props[XmlEN::ADDRDATA] ?? "";
                        $vcard = \Sabre\VObject\Reader::read($vcf);
                        if ($vcard instanceof VCard) {
                            $results[$respUri] = [
                                "etag" => $propstat->prop->props[XmlEN::GETETAG] ?? "",
                                "vcard" => $vcard
                            ];
                        } else {
                            Config::$logger->error("sabre reader did not return a VCard object for $vcf\n");
                        }
                    }
                }
            } elseif ($response instanceof ResponseStatus) {
                foreach ($response->hrefs as $respUri) {
                    if (CardDavClient::compareUrlPaths($respUri, $this->uri)) {
                        if (stripos($response->status, " 507 ") !== false) {
                            // results truncated by server
                        } else {
                            Config::$logger->debug(__METHOD__ . " Ignoring response on addressbook itself");
                        }
                    } else {
                        Config::$logger->warning(__METHOD__ . " Unexpected respstatus element {$response->status}");
                    }
                }
            }
        }

        return $results;
    }

    /**
     * This function replaces some well-known XML namespaces with a long name with shorter names for printing.
     *
     * @param string $s The fully-qualified XML element name (e.g. {urn:ietf:params:xml:ns:carddav}prop)
     * @return string The short name (e.g. {CARDDAV}prop)
     */
    protected function shortenXmlNamespacesForPrinting(string $s): string
    {
        return str_replace(
            [ "{" . XmlEN::NSCARDDAV . "}", "{" . XmlEN::NSCS . "}" ],
            [ "{CARDDAV}", "{CS}" ],
            $s
        );
    }

    /**
     * Validates a VCard before sending it to a CardDAV server.
     *
     * @param VCard $vcard The VCard to be validated.
     * @throws \InvalidArgumentException if the validation fails.
     */
    protected function validateCard(VCard $vcard): void
    {
        $hasError = false;
        $errors = "";

        // Assert validity of the Card for CardDAV, including valid UID property
        /** @psalm-var list<VcardValidateResult> */
        $validityIssues = $vcard->validate(\Sabre\VObject\Node::PROFILE_CARDDAV | \Sabre\VObject\Node::REPAIR);
        foreach ($validityIssues as $issue) {
            $msg = "Issue with provided VCard: " . $issue["message"];

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
     * @psalm-return list<string>
     * @return array<int,string> A list of property names including namespace prefix (e. g. '{DAV:}resourcetype').
     *
     * @see WebDavResource::getProperties()
     * @see WebDavResource::refreshProperties()
     */
    protected function getNeededCollectionPropertyNames(): array
    {
        $parentPropNames = parent::getNeededCollectionPropertyNames();
        $propNames = array_merge($parentPropNames, self::PROPNAMES);
        return array_values(array_unique($propNames));
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

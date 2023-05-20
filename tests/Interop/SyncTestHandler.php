<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\Services\{SyncHandler};
use PHPUnit\Framework\Assert;
use Sabre\VObject\Component\VCard;

/**
 * This is a sync handler to test reported sync results against expected ones.
 *
 * Generally, the sync handler will check that the changes performed by the test case between two syncs are reported in
 * the 2nd sync. In the first sync, the contents of the addressbook are not known, so any cards reported have to be
 * accepted. However, if the test performed some changes before the first sync, it can check for those. Between the two
 * syncs, no external changes to the addressbook are assumed, to the handler can check for the exact changes.
 */
final class SyncTestHandler implements SyncHandler
{
    /**
     * @var AddressbookCollection The addressbook on that the sync is performed.
     */
    private $abook;

    /**
     * @var bool $allowAdditionalChanges If true, the sync result may include unknown changes/deletes which are
     *    accepted.
     */
    private $allowAdditionalChanges;

    /**
     * Maps URI to [ 'vcard' => VCard object, 'etag' => string expected etag ]
     * @var array<string, array{vcard: VCard, etag: string, seen?: bool}>
     *      The new/changed cards that are expected to be reported by the sync
     */
    private $expectedChangedCards;

    /**
     * @var array<string,bool> An array of URIs => bool expected to be reported as deleted by the Sync.
     *    The values are used to record which cards have been reported as deleted during the sync.
     */
    private $expectedDeletedUris;

    /**
     * @var string $opSequence A log of the operation sequence invoked on this sync handler.
     *
     * String contains the characters:
     *   - C (addressObjectChanged)
     *   - D (addressObjectDeleted)
     *   - F (finalizeSync)
     *
     *  getExistingVCardETags is not recorded as no logical ordering is defined.
     */
    private $opSequence = "";

    /**
     * @var array<string, string>
     *     The state of the (simulated) local cache. This is an associative array mapping URIs of
     *     cards that are assumed to be locally present to the ETags of their local version. Is provided during sync to
     *     the sync service upon request. Is updated during the sync according to the changes reported by the sync
     *     handler.
     */
    private $cacheState;

    /**
     * @param array<string, array{vcard: VCard, etag: string}> $expectedChangedCards
     * @param list<string> $expectedDeletedUris
     * @param array<string, string> $cacheState
     */
    public function __construct(
        AddressBookCollection $abook,
        bool $allowAdditionalChanges,
        array $expectedChangedCards = [],
        array $expectedDeletedUris = [],
        array $cacheState = []
    ) {
        $this->abook = $abook;
        $this->expectedChangedCards = $expectedChangedCards;
        $this->expectedDeletedUris = array_fill_keys($expectedDeletedUris, false);
        $this->allowAdditionalChanges = $allowAdditionalChanges;
        $this->cacheState = $cacheState;
    }

    public function addressObjectChanged(string $uri, string $etag, ?VCard $card): void
    {
        $this->opSequence .= "C";
        $this->cacheState[$uri] = $etag; // need the relative URI as reported by the server here

        Assert::assertNotNull($card, "VCard data for $uri could not be retrieved/parsed");

        $uri = TestInfrastructure::normalizeUri($this->abook, $uri);

        if ($this->allowAdditionalChanges === false) {
            Assert::assertArrayHasKey($uri, $this->expectedChangedCards, "Unexpected change reported: $uri");
        }

        if (isset($this->expectedChangedCards[$uri])) {
            Assert::assertArrayNotHasKey(
                "seen",
                $this->expectedChangedCards[$uri],
                "Change reported multiple times: $uri"
            );
            $this->expectedChangedCards[$uri]["seen"] = true;

            // the ETag is optional in the expected cards - the server may not report it after the insert in case
            // the card was changed server side
            if (!empty($this->expectedChangedCards[$uri]["etag"])) {
                Assert::assertEquals(
                    $this->expectedChangedCards[$uri]["etag"],
                    $etag,
                    "ETag of changed card different from time ETag reported after change"
                );
            }

            TestInfrastructure::compareVCards($this->expectedChangedCards[$uri]["vcard"], $card, true);
        }
    }

    public function addressObjectDeleted(string $uri): void
    {
        $this->opSequence .= "D";

        if (! $this->allowAdditionalChanges) {
            Assert::assertArrayHasKey($uri, $this->cacheState, "Delete for URI not in cache: $uri");
        }
        unset($this->cacheState[$uri]);

        $uri = TestInfrastructure::normalizeUri($this->abook, $uri);

        if (! $this->allowAdditionalChanges) {
            Assert::assertArrayHasKey($uri, $this->expectedDeletedUris, "Unexpected delete reported: $uri");
        }

        if (isset($this->expectedDeletedUris[$uri])) {
            Assert::assertFalse($this->expectedDeletedUris[$uri], "Delete reported multiple times: $uri");
        }
        $this->expectedDeletedUris[$uri] = true;
    }

    /** @return array<string,string> */
    public function getExistingVCardETags(): array
    {
        return $this->cacheState;
    }

    public function finalizeSync(): void
    {
        $this->opSequence .= "F";
    }

    /** @return array<string,string> */
    public function testVerify(): array
    {
        $numDel =  '{' . count($this->expectedDeletedUris) . '}';
        $numChgMin = count($this->expectedChangedCards);
        $numChgMax = $this->allowAdditionalChanges ? "" : $numChgMin;
        $numChg = '{' . $numChgMin . ',' . $numChgMax . '}';

        Assert::assertMatchesRegularExpression(
            "/^D{$numDel}C{$numChg}F$/",
            $this->opSequence,
            "Delete must be reported before changes"
        );

        foreach ($this->expectedDeletedUris as $uri => $seen) {
            Assert::assertTrue($seen, "Deleted card NOT reported as deleted: $uri");
        }

        foreach ($this->expectedChangedCards as $uri => $attr) {
            Assert::assertArrayHasKey("seen", $attr, "Changed card NOT reported as changed: $uri");
            Assert::assertTrue($attr["seen"] ?? false, "Changed card NOT reported as changed: $uri");
        }

        return $this->cacheState;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

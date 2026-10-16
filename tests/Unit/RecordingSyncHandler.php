<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2026 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\Services\SyncHandler;
use Sabre\VObject\Component\VCard;

/**
 * A SyncHandler that records the sequence of the operations reported by the Sync service.
 */
final class RecordingSyncHandler implements SyncHandler
{
    /**
     * @var list<string> The reported operations in the order reported
     *
     * Each entry is one of "D <uri>" (deleted), "C <uri>" (changed) or "F" (finalizeSync).
     */
    public $ops = [];

    /** @var array<string,string> The simulated local cache state, mapping URIs to ETags */
    private $localETags;

    /**
     * @param array<string,string> $localETags The simulated local cache state, mapping URIs to ETags
     */
    public function __construct(array $localETags = [])
    {
        $this->localETags = $localETags;
    }

    public function addressObjectChanged(string $uri, string $etag, ?VCard $card): void
    {
        TestCase::assertNotNull($card, "No VCard provided for changed card $uri");
        $this->ops[] = "C $uri";
    }

    public function addressObjectDeleted(string $uri): void
    {
        $this->ops[] = "D $uri";
    }

    public function getExistingVCardETags(): array
    {
        return $this->localETags;
    }

    public function finalizeSync(): void
    {
        $this->ops[] = "F";
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

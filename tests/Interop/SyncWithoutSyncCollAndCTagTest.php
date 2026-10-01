<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2026 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\CardDavClient\AddressbookCollection;

/**
 * Tests the Sync service's fallback for servers that support neither the sync-collection REPORT nor the CTag.
 *
 * The changes are determined by ETag comparison on every sync.
 */
final class SyncWithoutSyncCollAndCTagTest extends SyncTestBase
{
    protected function getSyncAddressbook(string $abookname): AddressbookCollection
    {
        $abook = TestInfrastructureSrv::getAddressbook($abookname);
        return new AddressbookCollectionNoSyncColl($abook->getUri(), $abook->getAccount(), true);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

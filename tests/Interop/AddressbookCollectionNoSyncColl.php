<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2026 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\CardDavClient\{Account,AddressbookCollection};

/**
 * An addressbook that pretends the server does not support the sync-collection REPORT, and optionally the CTag.
 *
 * This allows to test the fallback synchronization paths of the Sync service against servers that support these
 * features.
 *
 * Note that a new object should be used for each sync, as the CTag is cached with the properties of the object.
 */
final class AddressbookCollectionNoSyncColl extends AddressbookCollection
{
    /**
     * @var bool If true, the object pretends the server does not provide the CTag property.
     */
    private $hideCTag;

    public function __construct(string $uri, Account $account, bool $hideCTag)
    {
        parent::__construct($uri, $account);
        $this->hideCTag = $hideCTag;
    }

    public function supportsSyncCollection(): bool
    {
        return false;
    }

    public function getCTag(): ?string
    {
        return $this->hideCTag ? null : parent::getCTag();
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

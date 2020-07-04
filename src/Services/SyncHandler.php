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
 * Interface for application-level synchronization handler.
 *
 * During an addressbook synchronization, the corresponding methods of this interface
 * are invoked for events such as changed or deleted address objects, to be handled
 * in an application-specific manner.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Services;

use Sabre\VObject\Component\VCard;

/**
 * Interface for application-level synchronization handler.
 */
interface SyncHandler
{
    /**
     * This method is called for each changed address object, including new address objects.
     *
     * @param string $uri
     *  URI of the changed or added address object.
     * @param string $etag
     *  ETag of the retrieved version of the address object.
     * @param VCard $card
     *  A (partial) VCard containing (at least, if available)the requested VCard properties.
     */
    public function addressObjectChanged(string $uri, string $etag, VCard $card): void;

    /**
     * This method is called for each deleted address object.
     *
     * @param string $uri
     *  URI of the deleted address object.
     */
    public function addressObjectDeleted(string $uri): void;

    /**
     * Provides the URIs and ETags of all VCards existing locally.
     *
     * During synchronization, it may be required to identify the version of locally existing address objects to
     * determine whether the server-side version is newer than the local version. This is the case if the server does
     * not support the sync-collection report, or if the sync-token has expired on the server and thus the server is not
     * able to report the changes against the local state.
     *
     * @return array
     *  Associative array with URIs (URL path component without server) as keys, ETags as values.
     */
    public function getExistingVCardETags(): array;

    /**
     * Called upon completion of the synchronization process to enable the handler to
     * perform final actions if needed.
     */
    public function finalizeSync(): void;
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
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

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Services;

use Sabre\VObject\Component\VCard;

/**
 * Interface for application-level synchronization handler.
 *
 * During an addressbook synchronization, the corresponding methods of this interface are invoked for events such as
 * changed or deleted address objects, to be handled in an application-specific manner.
 *
 * @package Public\Services
 */
interface SyncHandler
{
    /**
     * This method is called for each changed address object, including new address objects.
     *
     * In case an error occurs attempting to retrieve or to parse the address data for an URI that the server reported
     * as changed, this method is invoked with a null $card parameter. This allows the client to know that there was a
     * change that is missing from the sync, and to handle or ignore it as it sees fit.
     *
     * @param string $uri
     *  URI of the changed or added address object.
     * @param string $etag
     *  ETag of the retrieved version of the address object.
     * @param ?VCard $card
     *  A (partial) VCard containing (at least, if available)the requested VCard properties. Null in case an error
     *  occurred retrieving or parsing the VCard retrieved from the server.
     *
     * @see Sync
     * @api
     */
    public function addressObjectChanged(string $uri, string $etag, ?VCard $card): void;

    /**
     * This method is called for each deleted address object.
     *
     * @param string $uri
     *  URI of the deleted address object.
     *
     * @see Sync
     * @api
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
     * For the first sync, returns an empty array. The {@see Sync} service will consider cards as:
     *  - new: URI not contained in the returned aray
     *  - changed: URI contained, assigned local ETag differs from server-side ETag
     *  - unchanged: URI contained, assigned local ETag equals server-side ETag
     *  - deleted: URI contained in array, but not reported by server as content of the addressbook
     *
     * Note: This array is only requested by the {@see Sync} service if needed, which is only the case if the
     * sync-collection REPORT cannot be used. Therefore, if it is expensive to construct this array, make sure
     * construction is done on demand in this method, which will not be called if the data is not needed.
     *
     * @return array<string,string>
     *  Associative array with URIs (URL path component without server) as keys, ETags as values.
     *
     * @see Sync
     * @api
     */
    public function getExistingVCardETags(): array;

    /**
     * Called upon completion of the synchronization process to enable the handler to perform final actions if needed.
     *
     * @see Sync
     * @api
     */
    public function finalizeSync(): void;
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

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
use MStilkerich\CardDavClient\{CardDavClient, Config};

/**
 * Stores the changes reported by the server to be processed during a sync operation.
 *
 * This class is used internally only by the {@see Sync} service.
 *
 * @package Internal\Services
 */
class SyncResult
{
    /**
     * The new sync token returned by the server.
     * @var string
     */
    public $syncToken;

    /**
     * True if the server limited the returned differences and another followup sync is needed.
     * @var bool
     */
    public $syncAgain = false;

    /**
     * URIs of deleted objects.
     *
     * @psalm-var list<string>
     * @var array<int,string>
     */
    public $deletedObjects = [];

    /**
     * URIs and ETags of new or changed address objects.
     *
     * @psalm-var list<array{uri: string, etag: string, vcf?: string, vcard?: VCard}>
     * @var array
     */
    public $changedObjects = [];

    /**
     * Construct a new sync result.
     *
     * @param string $syncToken The new sync token returned by the server.
     */
    public function __construct(string $syncToken)
    {
        $this->syncToken = $syncToken;
    }

    /**
     * Creates VCard objects for all changed cards.
     *
     * The objects are inserted into the {@see SyncResult::$changedObjects} array. In case the VCard object cannot be
     * created for some of the cards (for example parse error), an error is logged. If no vcard string data is available
     * in {@see SyncResult::$changedObjects} for a VCard, a warning is logged.
     *
     * @return bool
     *  True if a VCard could be created for all cards in {@see SyncResult::$changedObjects}, false otherwise.
     */
    public function createVCards(): bool
    {
        $ret = true;

        foreach ($this->changedObjects as &$obj) {
            if (!isset($obj["vcard"])) {
                if (isset($obj["vcf"])) {
                    try {
                        $obj["vcard"] = \Sabre\VObject\Reader::read($obj["vcf"]);
                    } catch (\Exception $e) {
                        Config::$logger->error("Could not parse VCF for " . $obj["uri"], [ 'exception' => $e ]);
                        $ret = false;
                    }
                } else {
                    Config::$logger->warning("No VCF for address object " . $obj["uri"] . " available");
                    $ret = false;
                }
            }
        }

        return $ret;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

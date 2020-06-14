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
 * Class SyncResult
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Services;

use MStilkerich\CardDavClient\{CardDavClient, Config};

class SyncResult
{
    /** @var string */
    public $syncToken;
    /** @var bool */
    public $syncAgain = false;
    /** @var array */
    public $deletedObjects = [];
    /** @var array */
    public $changedObjects = [];

    public function __construct(string $syncToken)
    {
        $this->syncToken = $syncToken;
    }

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

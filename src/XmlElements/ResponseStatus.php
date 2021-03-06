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

namespace MStilkerich\CardDavClient\XmlElements;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\Exception\XmlParseException;

/**
 * Represents XML DAV:response elements with status children as PHP objects.
 *
 * @psalm-immutable
 *
 * @package Internal\XmlElements
 */
class ResponseStatus extends Response
{
    /**
     * URIs the status in this reponse applies to. MUST contain a URI or a relative reference.
     * @psalm-var list<string>
     * @var array<int,string>
     */
    public $hrefs;

    /**
     * The HTTP status value of this response.
     * @var string
     */
    public $status;

    /**
     * Constructs a new ResponseStatus object.
     *
     * @psalm-param list<string> $hrefs
     * @param array<int,string> $hrefs
     * @param string $status
     */
    public function __construct(array $hrefs, string $status)
    {
        $this->hrefs = $hrefs;
        $this->status = $status;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

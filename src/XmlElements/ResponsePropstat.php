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
 * Represents XML DAV:response elements with propstat children as PHP objects.
 *
 * @psalm-immutable
 *
 * @package Internal\XmlElements
 */
class ResponsePropstat extends Response
{
    /**
     * URI the response applies to. MUST contain a URI or a relative reference.
     * @var string
     */
    public $href;

    /**
     * Propstat child elements.
     * @psalm-var list<Propstat>
     * @var array<int, Propstat>
     */
    public $propstat;

    /**
     * Constructs a new ResponsePropstat element.
     *
     * @param string $href URI the response applies to
     * @psalm-param list<Propstat> $propstat
     * @param array<int, Propstat> $propstat Propstat child elements
     */
    public function __construct(string $href, array $propstat)
    {
        $this->href = $href;
        $this->propstat = $propstat;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

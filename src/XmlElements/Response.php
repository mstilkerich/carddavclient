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
 * Class to represent XML DAV:response elements as PHP objects.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

class Response
{
    /** @var ?string MUST contain a URI or a relative reference. */
    public $href;

    /** @var array */
    public $propstat = [];

    /** @var ?string Holds a single HTTP status-line. */
    public $status;

    // FIXME DAV:error might also be needed
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
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

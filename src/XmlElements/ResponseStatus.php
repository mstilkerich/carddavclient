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

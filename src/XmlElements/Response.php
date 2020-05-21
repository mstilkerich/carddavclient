<?php

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

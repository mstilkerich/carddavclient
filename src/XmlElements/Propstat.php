<?php

/**
 * Class to represent XML DAV:propstat elements as PHP objects.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

class Propstat
{
    /** @var ?string Holds a single HTTP status-line. */
    public $status;

    /** @var ?Prop Contains properties related to a resource. */
    public $prop;

    // FIXME DAV:error might also be needed
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

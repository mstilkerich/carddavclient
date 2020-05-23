<?php

/**
 * Class to represent XML DAV:current-user-principal elements as PHP objects. (RFC5397)
 *
 * Per RFC5397, the element's value is a single DAV:href or DAV:unauthenticated element.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

class CurrentUserPrincipal
{
    /** @var ?string URL to a principal resource corresponding to the currently authenticated user. */
    public $href;

    /** @var ?string When authentication has done or failed, contains DAV:unauthenticated pseudo-principal. */
    public $unauthenticated;
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

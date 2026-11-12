<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2026 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Exception;

/**
 * Thrown when a request is refused because the application does not permit access to its target.
 *
 * This only happens for accounts that use the `allow_host_callback` HTTP option, see
 * {@see \MStilkerich\CardDavClient\Account::__construct()}. No connection to the target has been made when this
 * exception is thrown.
 *
 * @package Public\Exceptions
 */
class ForbiddenHostException extends ClientException
{
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Exception;

/**
 * Exception type to indicate that a parsed XML did not comply with the requirements described in its RFC definition.
 *
 * @package Public\Exceptions
 */
class XmlParseException extends \Exception
{
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Implementation of PSR-18 ClientExceptionInterface.
 *
 * @package Public\Exceptions
 */
class ClientException extends \Exception implements ClientExceptionInterface
{
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

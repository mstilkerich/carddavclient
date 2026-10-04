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
 * Thrown when the server answers a request with an HTTP status the operation does not accept.
 *
 * Applications can tell the failure from their status code, e.g. 401 or 403 for refused credentials, without having to
 * parse the message.
 */
class HttpStatusException extends \Exception
{
    /** @var int */
    private $statusCode;

    public function __construct(string $message, int $statusCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    /**
     * Returns the HTTP status code of the server's response.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

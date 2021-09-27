<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Implementation of PSR-18 NetworkExceptionInterface.
 *
 * @package Public\Exceptions
 */
class NetworkException extends ClientException implements NetworkExceptionInterface
{
    /** @var RequestInterface */
    private $request;

    public function __construct(string $message, int $code, RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->request = $request;
    }

    /**
     * Returns the request.
     *
     * The request object MAY be a different object from the one passed to ClientInterface::sendRequest()
     *
     * @return RequestInterface
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

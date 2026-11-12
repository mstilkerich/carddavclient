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
 * Thrown when the discovery of the addressbooks of an account fails.
 *
 * The discovery tries several locations to find the addressbooks, see
 * {@see \MStilkerich\CardDavClient\Services\Discovery::discoverAddressbooks()}. This exception provides the errors
 * that occurred for the individual locations, which allows an application to determine the cause of the failure, e.g.
 * an {@see HttpStatusException} with status 401 for refused credentials, a {@see NetworkException} for an unreachable
 * server or a {@see ForbiddenHostException} for a server the application does not permit access to. Locations that
 * were tried without an error, but did not provide the information needed to find the addressbooks, are not included.
 *
 * The first error is also available as the previous exception ({@see \Exception::getPrevious()}).
 *
 * @psalm-type DiscoveryError = array{uri: string, exception: \Exception}
 *
 * @package Public\Exceptions
 */
class DiscoveryException extends \Exception
{
    /**
     * @psalm-var list<DiscoveryError>
     * @var array
     */
    private $errors;

    /**
     * @psalm-param list<DiscoveryError> $errors
     * @param array $errors The errors that occurred during the discovery, in the order of their occurrence.
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message, 0, $errors[0]['exception'] ?? null);
        $this->errors = $errors;
    }

    /**
     * Returns the errors that occurred for the locations tried during the discovery.
     *
     * @psalm-return list<DiscoveryError>
     * @return array
     *  The errors in the order of their occurrence. Each error is an array with the keys `uri` (the URI of the
     *  location the discovery was attempted with) and `exception` (the exception thrown during the attempt).
     * @api
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

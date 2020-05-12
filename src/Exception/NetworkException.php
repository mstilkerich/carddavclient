<?php

/**
 * Implementation of PSR-18 NetworkExceptionInterface.
 *
 * @author Michael Stilkerich <michael@stilkerich.eu>
 * @copyright 2020 Michael Stilkerich
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License, version 2 (or later)
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Implementation of PSR-18 NetworkExceptionInterface.
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

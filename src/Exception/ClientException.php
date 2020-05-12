<?php

/**
 * Implementation of PSR-18 ClientExceptionInterface.
 *
 * @author Michael Stilkerich <michael@stilkerich.eu>
 * @copyright 2020 Michael Stilkerich
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License, version 2 (or later)
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Implementation of PSR-18 ClientExceptionInterface.
 */
class ClientException extends \Exception implements ClientExceptionInterface
{

}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

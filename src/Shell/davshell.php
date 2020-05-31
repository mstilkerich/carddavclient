<?php

/**
 * Simple CardDAV Shell, mainly for debugging the library.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Shell;

require 'vendor/autoload.php';

$shell = new Shell();
$shell->run();

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

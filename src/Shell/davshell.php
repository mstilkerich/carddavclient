<?php

/**
 * Simple CardDAV Shell, mainly for debugging the library.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Shell;

require 'vendor/autoload.php';

$accountdata = [];
include 'accounts.php';

$shell = new Shell($accountdata);
$shell->run();

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

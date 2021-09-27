<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

include_once __DIR__ . '/../autoload.php';

$classLoader = new \Composer\Autoload\ClassLoader();
$classLoader->addPsr4("MStilkerich\\Tests\\CardDavClient\\Interop\\", __DIR__, true);
$classLoader->register(true); // true -> Prepend classloader to other ones

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

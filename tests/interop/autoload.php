<?php

include_once __DIR__ . '/../autoload.php';

$classLoader = new \Composer\Autoload\ClassLoader();
$classLoader->addPsr4("MStilkerich\\Tests\\CardDavClient\\Interop\\", __DIR__, true);
$classLoader->register(true); // true -> Prepend classloader to other ones

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

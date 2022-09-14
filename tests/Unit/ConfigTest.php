<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\Config;
use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use Psr\Log\{AbstractLogger, NullLogger};

final class ConfigTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
    }

    public function testInitSetsLoggersCorrectly(): void
    {
        $l1 = $this->createStub(AbstractLogger::class);
        $l2 = $this->createStub(AbstractLogger::class);
        Config::init($l1, $l2);
        $this->assertSame($l1, Config::$logger);
        $this->assertSame($l2, Config::$httplogger);

        // test init without logger params sets default null loggers
        Config::init();
        $this->assertInstanceOf(NullLogger::class, Config::$logger);
        $this->assertInstanceOf(NullLogger::class, Config::$httplogger);
    }

    public function testInitSetsOptionsCorrectly(): void
    {
        Config::init(null, null, [ 'guzzle_logformat' => Config::GUZZLE_LOGFMT_SHORT]);
        $this->assertSame(Config::GUZZLE_LOGFMT_SHORT, Config::$options['guzzle_logformat']);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient;

use Psr\Log\{AbstractLogger,LogLevel,LoggerInterface};
use PHPUnit\Framework\TestCase;

class TestLogger extends AbstractLogger
{
    /**
     * @var int[] Assigns each log level a numerical severity value.
     */
    private const LOGLEVELS = [
        LogLevel::DEBUG     => 1,
        LogLevel::INFO      => 2,
        LogLevel::NOTICE    => 3,
        LogLevel::WARNING   => 4,
        LogLevel::ERROR     => 5,
        LogLevel::CRITICAL  => 6,
        LogLevel::ALERT     => 7,
        LogLevel::EMERGENCY => 8
    ];

    /**
     * @var string[] Assigns each short name to each log level.
     */
    private const LOGLEVELS_SHORT = [
        LogLevel::DEBUG     => "DBG",
        LogLevel::INFO      => "NFO",
        LogLevel::NOTICE    => "NTC",
        LogLevel::WARNING   => "WRN",
        LogLevel::ERROR     => "ERR",
        LogLevel::CRITICAL  => "CRT",
        LogLevel::ALERT     => "ALT",
        LogLevel::EMERGENCY => "EMG"
    ];

    /** @var resource $logh File handle to the log file */
    private $logh;

    /** @var string[][] In-Memory buffer of log messages to assert log messages */
    private $logBuffer = [];

    public function __construct(string $logFileName = 'test.log')
    {
        $logfile = "testreports/{$GLOBALS['TEST_TESTRUN']}/$logFileName";
        $logh = fopen($logfile, 'w');

        if ($logh === false) {
            throw new \Exception("could not open log file: $logfile");
        }

        $this->logh = $logh;
    }

    /**
     * At the time of destruction, there may be no unchecked log messages of warning or higher level.
     *
     * Tests should call reset() when done (in tearDown()), this is just a fallback to detect if a test did not and
     * there were errors. When the error is raised from the destructor, the relation to the test function that triggered
     * the leftover log messages is lost and PHPUnit may report the issue for an unrelated test function within the same
     * test case.
     */
    public function __destruct()
    {
        $this->reset();
        fclose($this->logh);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = array()): void
    {
        TestCase::assertIsString($level);
        TestCase::assertNotNull(self::LOGLEVELS[$level]);

        $levelNumeric = self::LOGLEVELS[$level];
        $levelShort = self::LOGLEVELS_SHORT[$level];

        // interpolation of context placeholders is not implemented
        fprintf($this->logh, "[%s]: %s\n", date('Y-m-d H:i:s'), "[$levelNumeric $levelShort] $message");

        // only warnings or more critical messages are interesting for testing
        if (self::LOGLEVELS[$level] >= self::LOGLEVELS[LogLevel::WARNING]) {
            $this->logBuffer[] = [ $level, $message, 'UNCHECKED' ];
        }
    }

    /**
     * Resets the in-memory buffer of critical log messages.
     */
    public function reset(): void
    {
        $buffer = $this->logBuffer;

        // reset before doing the assertions - if there is a failure, it won't affect the following tests
        $this->logBuffer = [];

        foreach ($buffer as $recMsg) {
            [ $level, $msg, $checked ] = $recMsg;
            TestCase::assertSame('CHECKED', $checked, "Unchecked log message of level $level: $msg");
        }
    }

    /**
     * Checks the in-memory buffer if a log message of the given log level was emitted.
     */
    public function expectMessage(string $expLevel, string $expMsg): void
    {
        $found = false;

        foreach ($this->logBuffer as &$recMsg) {
            [ $level, $msg ] = $recMsg;
            if (($level == $expLevel) && strpos($msg, $expMsg) !== false && $recMsg[2] == "UNCHECKED") {
                $recMsg[2] = 'CHECKED';
                $found = true;
                break;
            }
        }
        unset($recMsg);

        TestCase::assertTrue($found, "The expected log entry containing '$expMsg' with level $expLevel was not found");
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php

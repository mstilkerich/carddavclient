<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient;

use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\{Config,WebDavCollection};

final class TestInfrastructure
{
    /** @var ?TestLogger Logger object used to store log messages produced during the tests */
    private static $logger;

    public static function init(LoggerInterface $httpLogger = null): void
    {
        if (!isset(self::$logger)) {
            self::$logger = new TestLogger();
        }

        Config::init(self::$logger, $httpLogger);
    }

    public static function logger(): TestLogger
    {
        $logger = self::$logger;
        TestCase::assertNotNull($logger, "Call init() before asking for logger");
        return $logger;
    }

    /**
     * Create a minimal VCard for test purposes.
     */
    public static function createVCard(): VCard
    {
        $randnum = rand();
        $vcard =  new VCard([
            'FN'  => "CardDavClient Test$randnum",
            'N'   => ["Test$randnum", 'CardDavClient', '', '', ''],
        ]);

        return $vcard;
    }

    /**
     * CardDAV servers change the VCards, so this comparison function must be tolerant when comparing data stored on a
     * CardDAV server to data retrieved back from the server.
     *
     * - Google:
     *   - Omits TYPE attribute in results from addressbook-query
     *   - Changes case of the type attribute (work -> WORK)
     *   - Overrides the UID in new cards with a server-side-assigned UID
     */
    public static function compareVCards(VCard $vcardExpected, VCard $vcardRoundcube, bool $isNew): void
    {
        // clone to make sure we don't modify the passed in object when deleting properties that should not be compared
        $vcardExpected = clone $vcardExpected;
        $vcardRoundcube = clone $vcardRoundcube;

        // These attributes are dynamically created / updated and therefore cannot be statically compared
        $noCompare = [ 'REV', 'PRODID', 'VERSION' ]; // different VERSION may imply differences in other properties

        if ($isNew) {
            // new VCard will have UID assigned by carddavclient lib on store
            $noCompare[] = 'UID';
        }

        foreach ($noCompare as $property) {
            unset($vcardExpected->{$property});
            unset($vcardRoundcube->{$property});
        }

        /** @var VObject\Property[] */
        $propsExp = $vcardExpected->children();
        $propsExp = self::groupNodesByName($propsExp);
        /** @var VObject\Property[] */
        $propsRC = $vcardRoundcube->children();
        $propsRC = self::groupNodesByName($propsRC);

        // compare
        foreach ($propsExp as $name => $props) {
            TestCase::assertArrayHasKey($name, $propsRC, "Expected property $name missing from test vcard");
            self::compareNodeList("Property $name", $props, $propsRC[$name]);

            for ($i = 0; $i < count($props); ++$i) {
                TestCase::assertEqualsIgnoringCase(
                    $props[$i]->group,
                    $propsRC[$name][$i]->group,
                    "Property group name differs"
                );
                /** @psalm-var VObject\Parameter[] */
                $paramExp = $props[$i]->parameters();
                $paramExp = self::groupNodesByName($paramExp);
                /** @psalm-var VObject\Parameter[] */
                $paramRC = $propsRC[$name][$i]->parameters();
                $paramRC = self::groupNodesByName($paramRC);
                foreach ($paramExp as $pname => $params) {
                    self::compareNodeList("Parameter $name/$pname", $params, $paramRC[$pname]);
                    unset($paramRC[$pname]);
                }
                TestCase::assertEmpty($paramRC, "Prop $name has extra params: " . implode(", ", array_keys($paramRC)));
            }
            unset($propsRC[$name]);
        }

        TestCase::assertEmpty($propsRC, "VCard has extra properties: " . implode(", ", array_keys($propsRC)));
    }

    /**
     * Groups a list of VObject\Node by node name.
     *
     * @template T of VObject\Property|VObject\Parameter
     *
     * @param T[] $nodes
     * @return array<string, list<T>> Array with node names as keys, and arrays of nodes by that name as values.
     */
    private static function groupNodesByName(array $nodes): array
    {
        $res = [];
        foreach ($nodes as $n) {
            $res[$n->name][] = $n;
        }

        return $res;
    }

    /**
     * Compares to lists of VObject nodes with the same name.
     *
     * This can be two lists of property instances (e.g. EMAIL, TEL) or two lists of parameters (e.g. TYPE).
     *
     * @param string $dbgid Some string to identify property/parameter for error messages
     * @param VObject\Property[]|VObject\Parameter[] $exp Expected list of nodes
     * @param VObject\Property[]|VObject\Parameter[] $rc  List of nodes in the VCard produces by rcmcarddav
     */
    private static function compareNodeList(string $dbgid, array $exp, array $rc): void
    {
        TestCase::assertCount(count($exp), $rc, "Different amount of $dbgid");

        for ($i = 0; $i < count($exp); ++$i) {
            TestCase::assertEquals($exp[$i]->getValue(), $rc[$i]->getValue(), "Nodes $dbgid differ");
        }
    }

    public static function normalizeUri(WebDavCollection $coll, string $uri): string
    {
        return \Sabre\Uri\normalize(\Sabre\Uri\resolve($coll->getUri(), $uri));
    }

    public static function getUriPath(string $uri): string
    {
        $uricomp = \Sabre\Uri\parse($uri);
        return $uricomp["path"] ?? "/";
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

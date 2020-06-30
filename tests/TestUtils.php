<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient;

use PHPUnit\Framework\Assert;
use MStilkerich\CardDavClient\{Account,AddressbookCollection,WebDavCollection};
use Sabre\VObject\Component\VCard;

final class TestUtils
{
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
     * Compare relevant attributes of two VCards.
     *
     * Intended to compare VCards stored on the server with those received from it.
     * The server may change certain properties, so we don't compare for exact identity.
     */
    public static function compareVCard(VCard $exp, VCard $rcv): void
    {
        $compareAttr = [ 'N', 'FN' ];
        foreach ($compareAttr as $compareAttr) {
            $expV = $exp->{$compareAttr}->getParts();
            $rcvV = $rcv->{$compareAttr}->getParts();
            Assert::assertEquals($expV, $rcvV, "Equals: Property $compareAttr");
        }
    }

    public static function normalizeUri(WebDavCollection $coll, string $uri): string
    {
        return \Sabre\Uri\resolve($coll->getUri(), $uri);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

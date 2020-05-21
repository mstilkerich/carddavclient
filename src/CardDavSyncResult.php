<?php

/**
 * Class CardDavSyncResult
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

class CardDavSyncResult
{
    /** @var string */
    public $syncToken;
    /** @var bool */
    public $syncAgain = false;
    /** @var array */
    public $deletedObjects = [];
    /** @var array */
    public $changedObjects = [];

    public function __construct(string $syncToken)
    {
        $this->syncToken = $syncToken;
    }

    public function addVcfForChangedObj(string $uri, string $etag, string $vcf): bool
    {
        foreach ($this->changedObjects as &$obj) {
            if (CardDavClient::compareUrlPaths($obj["uri"], $uri)) {
                $obj["vcf"] = $vcf;
                $obj["etag"] = $etag;
                return true;
            }
        }
        return false;
    }

    public function createVCards(): bool
    {
        $ret = true;

        foreach ($this->changedObjects as &$obj) {
            if (isset($obj["vcf"])) {
                try {
                    $obj["vcard"] = \Sabre\VObject\Reader::read($obj["vcf"]);
                } catch (\Exception $e) {
                    echo "Could not parse VCF for " . $obj["uri"] . ": " . $e->getMessage() . "\n";
                    $ret = false;
                }
            } else {
                echo "No VCF for address object " . $obj["uri"] . " available\n";
                $ret = false;
            }
        }

        return $ret;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

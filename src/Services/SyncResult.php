<?php

/**
 * Class SyncResult
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Services;

use MStilkerich\CardDavClient\{CardDavClient, Config};

class SyncResult
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

    public function createVCards(): bool
    {
        $ret = true;

        foreach ($this->changedObjects as &$obj) {
            if (!isset($obj["vcard"])) {
                if (isset($obj["vcf"])) {
                    try {
                        $obj["vcard"] = \Sabre\VObject\Reader::read($obj["vcf"]);
                    } catch (\Exception $e) {
                        Config::$logger->error("Could not parse VCF for " . $obj["uri"], [ 'exception' => $e ]);
                        $ret = false;
                    }
                } else {
                    Config::$logger->warning("No VCF for address object " . $obj["uri"] . " available");
                    $ret = false;
                }
            }
        }

        return $ret;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

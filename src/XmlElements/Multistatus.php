<?php

/**
 * Class to represent XML DAV:multistatus elements as PHP objects.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

class Multistatus implements \Sabre\Xml\XmlDeserializable
{
    /** @var ?string */
    public $synctoken;

    /** @var array */
    public $responses = [];

    public static function xmlDeserialize(\Sabre\Xml\Reader $reader): Multistatus
    {
        $multistatus = new self();
        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child["value"] instanceof Response) {
                    $multistatus->responses[] = $child["value"];
                } elseif ($child["name"] === "{DAV:}sync-token") {
                    $multistatus->synctoken = $child["value"];
                }
            }
        }
        return $multistatus;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

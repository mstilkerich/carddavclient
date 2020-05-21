<?php

/**
 * Class to represent XML DAV:prop elements as PHP objects.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

class Prop implements \Sabre\Xml\XmlDeserializable
{
    /** @var array */
    public $props = [];

    public static function xmlDeserialize(\Sabre\Xml\Reader $reader)
    {
        $prop = new self();
        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            foreach ($children as $child) {
                $prop->props[$child["name"]] = $child["value"];
            }
        }
        return $prop;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

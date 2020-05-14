<?php

/**
 * Objects of this class represent an addressbook collection on a WebDAV
 * server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

class AddressbookCollection extends WebDavCollection
{
    public function getName(): string
    {
        return $this->props["DAV:displayname"] ?? basename($this->uri);
    }

    public function __toString(): string
    {
        $desc  = $this->getName() . " (" . $this->uri . ")";
        return $desc;
    }

    public function getDetails(): string
    {
        $desc  = "Addressbook " . $this->getName() . "\n";
        $desc .= "    URI: " . $this->uri . "\n";
        foreach ($this->props as $propName => $propVal) {
            $desc .= "    $propName: ";

            if (is_array($propVal)) {
                $desc .= implode(", ", $propVal);
            } else {
                $desc .= $propVal;
            }

            $desc .= "\n";
        }

        return $desc;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

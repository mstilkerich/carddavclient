<?php

/**
 * Objects of this class represent an addressbook collection on a WebDAV
 * server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class AddressbookCollection extends WebDavCollection
{
    private const PROPNAMES = [
        XmlEN::DISPNAME,
        XmlEN::GETCTAG,
        XmlEN::SUPPORTED_ADDRDATA,
        XmlEN::ABOOK_DESC,
        XmlEN::MAX_RESSIZE,
        XmlEN::SUPPORTED_REPORT_SET
    ];

    public function getName(): string
    {
        return $this->props[XmlEN::DISPNAME] ?? basename($this->uri);
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
                if (isset($propVal[0]) && is_array($propVal[0])) {
                    $propVal = array_map(
                        function (array $subarray): string {
                            return implode(" ", $subarray);
                        },
                        $propVal
                    );
                }
                $desc .= implode(", ", $propVal);
            } else {
                $desc .= $propVal;
            }

            $desc .= "\n";
        }

        return $desc;
    }

    public function supportsSyncCollection(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_SYNCCOLL);
    }

    public function supportsMultiGet(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_MULTIGET);
    }

    public function getCTag(): ?string
    {
        return $this->props[XmlEN::GETCTAG] ?? null;
    }

    protected function getNeededCollectionPropertyNames(): array
    {
        $parentPropNames = parent::getNeededCollectionPropertyNames();
        $propNames = array_merge($parentPropNames, self::PROPNAMES);
        return array_unique($propNames);
    }

    protected function supportsReport(string $reportElement): bool
    {
        return in_array($reportElement, $this->props[XmlEN::SUPPORTED_REPORT_SET], true);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

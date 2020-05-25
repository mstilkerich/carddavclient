<?php

/**
 * Objects of this class represent an addressbook collection on a WebDAV
 * server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

class AddressbookCollection extends WebDavCollection
{
    private const PROPNAMES = [
        "{" . CardDavClient::NSCS . "}getctag",
        "{" . CardDavClient::NSCARDDAV . "}supported-address-data",
        "{" . CardDavClient::NSCARDDAV . "}addressbook-description",
        "{" . CardDavClient::NSCARDDAV . "}max-resource-size"
    ];

    public function getName(): string
    {
        return $this->props["{DAV:}displayname"] ?? basename($this->uri);
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
        return $this->supportsReport("{DAV:}sync-collection");
    }

    public function supportsMultiGet(): bool
    {
        return $this->supportsReport("{" . CardDavClient::NSCARDDAV . "}addressbook-multiget");
    }

    public function getCTag(): ?string
    {
        return $this->props["{http://calendarserver.org/ns/}getctag"] ?? null;
    }

    protected function getNeededCollectionPropertyNames(): array
    {
        $parentPropNames = parent::getNeededCollectionPropertyNames();
        $propNames = array_merge($parentPropNames, self::PROPNAMES);
        return array_unique($propNames);
    }

    protected function supportsReport(string $reportElement): bool
    {
        return in_array($reportElement, $this->props["{DAV:}supported-report-set"], true);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

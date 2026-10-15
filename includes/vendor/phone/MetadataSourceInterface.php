<?php

declare(strict_types=1);

namespace EasyFormly\Vendor\Phone;

interface MetadataSourceInterface
{
    /**
     * Gets phone metadata for a region.
     */
    public function getMetadataForRegion(string $regionCode): PhoneMetadata;

    /**
     * Gets phone metadata for a non-geographical region.
     */
    public function getMetadataForNonGeographicalRegion(int $countryCallingCode): PhoneMetadata;
}

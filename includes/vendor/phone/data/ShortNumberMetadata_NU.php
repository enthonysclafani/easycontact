<?php

/**
 * libphonenumber-for-php-lite data file
 * This file has been @generated from libphonenumber data
 * Do not modify!
 * @internal
 */

declare(strict_types=1);

namespace EasyContact\Vendor\Phone\data;

use EasyContact\Vendor\Phone\PhoneMetadata;
use EasyContact\Vendor\Phone\PhoneNumberDesc;

/**
 * @internal
 */
class ShortNumberMetadata_NU extends PhoneMetadata
{
    protected const ID = 'NU';
    protected const COUNTRY_CODE = 0;

    protected ?string $internationalPrefix = '';

    public function __construct()
    {
        $this->generalDesc = (new PhoneNumberDesc())
            ->setNationalNumberPattern('[019]\d\d')
            ->setPossibleLength([3]);
        $this->premiumRate = PhoneNumberDesc::empty();
        $this->tollFree = (new PhoneNumberDesc())
            ->setNationalNumberPattern('999')
            ->setExampleNumber('999');
        $this->emergency = (new PhoneNumberDesc())
            ->setNationalNumberPattern('999')
            ->setExampleNumber('999');
        $this->short_code = (new PhoneNumberDesc())
            ->setNationalNumberPattern('01[05]|101|999')
            ->setExampleNumber('010');
        $this->standard_rate = PhoneNumberDesc::empty();
        $this->carrierSpecific = (new PhoneNumberDesc())
            ->setNationalNumberPattern('010')
            ->setExampleNumber('010');
        $this->smsServices = PhoneNumberDesc::empty();
    }
}

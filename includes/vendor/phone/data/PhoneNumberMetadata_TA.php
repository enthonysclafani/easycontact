<?php

/**
 * libphonenumber-for-php-lite data file
 * This file has been @generated from libphonenumber data
 * Do not modify!
 * @internal
 */

declare(strict_types=1);

namespace EasyFormly\Vendor\Phone\data;

use EasyFormly\Vendor\Phone\PhoneMetadata;
use EasyFormly\Vendor\Phone\PhoneNumberDesc;

/**
 * @internal
 */
class PhoneNumberMetadata_TA extends PhoneMetadata
{
    protected const ID = 'TA';
    protected const COUNTRY_CODE = 290;
    protected const LEADING_DIGITS = '8';

    protected ?string $internationalPrefix = '00';

    public function __construct()
    {
        $this->generalDesc = (new PhoneNumberDesc())
            ->setNationalNumberPattern('8\d{3}')
            ->setPossibleLength([4]);
        $this->mobile = PhoneNumberDesc::empty();
        $this->premiumRate = PhoneNumberDesc::empty();
        $this->fixedLine = (new PhoneNumberDesc())
            ->setNationalNumberPattern('8\d{3}')
            ->setExampleNumber('8999');
        $this->tollFree = PhoneNumberDesc::empty();
        $this->sharedCost = PhoneNumberDesc::empty();
        $this->personalNumber = PhoneNumberDesc::empty();
        $this->voip = PhoneNumberDesc::empty();
        $this->pager = PhoneNumberDesc::empty();
        $this->uan = PhoneNumberDesc::empty();
        $this->voicemail = PhoneNumberDesc::empty();
        $this->noInternationalDialling = PhoneNumberDesc::empty();
    }
}

<?php

namespace App\Domain\Billing;

use App\Models\SystemConfiguration;

/**
 * The supplier block every invoice and receipt prints (02 §21.3), read
 * from global `system_configurations` rows. Seller details are the
 * business's own, so company or location overrides do not apply.
 */
final readonly class SellerDetails
{
    public const LEGAL_NAME_KEY = 'seller.legal_name';

    public const ADDRESS_KEY = 'seller.address';

    public const VAT_NUMBER_KEY = 'seller.vat_number';

    public const COMPANY_NUMBER_KEY = 'seller.company_number';

    /**
     * @param  list<string>  $addressLines
     */
    public function __construct(
        public ?string $legalName,
        public array $addressLines,
        public ?string $vatNumber,
        public ?string $companyNumber,
    ) {}

    public static function fromConfiguration(): self
    {
        $values = SystemConfiguration::query()
            ->where('scope', 'global')
            ->whereIn('config_key', [self::LEGAL_NAME_KEY, self::ADDRESS_KEY, self::VAT_NUMBER_KEY, self::COMPANY_NUMBER_KEY])
            ->pluck('value_text', 'config_key');

        $text = static function (string $key) use ($values): ?string {
            $value = $values->get($key);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $address = $text(self::ADDRESS_KEY);

        return new self(
            $text(self::LEGAL_NAME_KEY),
            $address === null ? [] : array_values(array_filter(array_map('trim', preg_split('/\R/', $address) ?: []), fn (string $line) => $line !== '')),
            $text(self::VAT_NUMBER_KEY),
            $text(self::COMPANY_NUMBER_KEY),
        );
    }
}

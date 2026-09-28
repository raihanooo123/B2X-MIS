<?php

namespace App\Domain\Storefront;

use App\Domain\Billing\SellerDetails;
use App\Models\SystemConfiguration;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 05.15 §3.1 — the business's own name, logo, colour and contact details,
 * read from global `system_configurations` rows, with the legal block
 * from the `seller.*` keys (SellerDetails). The platform is white-label:
 * nothing here names a business in code, only the product credit.
 *
 * One query per request for the brand keys, one for the seller keys.
 */
final readonly class Branding
{
    public const NAME = 'brand.name';

    public const TAGLINE = 'brand.tagline';

    public const LOGO_PATH = 'brand.logo_path';

    public const PRIMARY_COLOUR = 'brand.primary_colour';

    public const SUPPORT_EMAIL = 'brand.support_email';

    public const SUPPORT_PHONE = 'brand.support_phone';

    public const SHOW_POWERED_BY = 'brand.show_powered_by';

    /** Text keys, in the order the settings page shows them. */
    public const TEXT_KEYS = [self::NAME, self::TAGLINE, self::LOGO_PATH, self::PRIMARY_COLOUR, self::SUPPORT_EMAIL, self::SUPPORT_PHONE];

    /** The disk logos are uploaded to and served from. */
    public const LOGO_DISK = 'public';

    public function __construct(
        public string $name,
        public ?string $tagline,
        public ?string $logoPath,
        public ?string $primaryColour,
        public ?string $supportEmail,
        public ?string $supportPhone,
        public bool $showPoweredBy,
        public SellerDetails $seller,
    ) {}

    public static function current(): self
    {
        $rows = SystemConfiguration::query()
            ->where('scope', 'global')
            ->whereIn('config_key', [...self::TEXT_KEYS, self::SHOW_POWERED_BY])
            ->get(['config_key', 'value_text', 'value_int'])
            ->keyBy('config_key');

        $text = static function (string $key) use ($rows): ?string {
            $value = $rows->get($key)?->getAttribute('value_text');

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $colour = $text(self::PRIMARY_COLOUR);
        $poweredBy = $rows->get(self::SHOW_POWERED_BY)?->getAttribute('value_int');

        return new self(
            name: $text(self::NAME) ?? (string) config('app.name'),
            tagline: $text(self::TAGLINE),
            logoPath: $text(self::LOGO_PATH),
            primaryColour: $colour !== null && Colour::isHex($colour) ? strtolower($colour) : null,
            supportEmail: $text(self::SUPPORT_EMAIL),
            supportPhone: $text(self::SUPPORT_PHONE),
            // Shown unless switched off (05.15 §3.1).
            showPoweredBy: $poweredBy === null || (int) $poweredBy !== 0,
            seller: SellerDetails::fromConfiguration(),
        );
    }

    /**
     * The shape every storefront page receives (HandleInertiaRequests).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tagline' => $this->tagline,
            'logo_url' => $this->logoUrl(),
            // HSL channels for the `--primary` token, with a readable
            // foreground: the page never has to parse a colour.
            'primary_hsl' => $this->primaryColour === null ? null : Colour::hslChannels($this->primaryColour),
            'primary_foreground_hsl' => $this->primaryColour === null ? null : Colour::hslChannels(Colour::readableOn($this->primaryColour)),
            'support_email' => $this->supportEmail,
            'support_phone' => $this->supportPhone,
            'show_powered_by' => $this->showPoweredBy,
            'legal' => [
                'name' => $this->seller->legalName,
                'address' => $this->seller->addressLines,
                'company_number' => $this->seller->companyNumber,
                'vat_number' => $this->seller->vatNumber,
            ],
        ];
    }

    private function logoUrl(): ?string
    {
        if ($this->logoPath === null) {
            return null;
        }

        try {
            return Storage::disk(self::LOGO_DISK)->url($this->logoPath);
        } catch (Throwable) {
            return null;
        }
    }
}

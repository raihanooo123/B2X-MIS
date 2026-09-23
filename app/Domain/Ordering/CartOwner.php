<?php

namespace App\Domain\Ordering;

use InvalidArgumentException;

/**
 * Whose cart a request is acting on — exactly one of three identities
 * (02 §14.3's nullable `company_id`/`user_id` plus `session_token`):
 *
 *   - company: a trade buyer. The cart is shared by every user on the
 *     account (05.1 §10: "two users on one company account editing the
 *     same cart — last write wins"), so it is keyed by company alone;
 *     `userId` is recorded on creation as the user who started it.
 *   - user:    an authenticated consumer with no company.
 *   - guest:   neither yet; `sessionToken` is the only identity (02 §14.3).
 *
 * A guest identity never reaches an owned cart: guest lookups also
 * require `company_id IS NULL AND user_id IS NULL`, so a session token
 * that was once attached to an adopted cart (see
 * CartService::mergeGuestCart()) cannot be used to read it back.
 */
final readonly class CartOwner
{
    private function __construct(
        public ?int $companyId,
        public ?int $userId,
        public ?string $sessionToken,
    ) {}

    public static function company(int $companyId, int $userId): self
    {
        return new self($companyId, $userId, null);
    }

    public static function user(int $userId): self
    {
        return new self(null, $userId, null);
    }

    public static function guest(string $sessionToken): self
    {
        if ($sessionToken === '') {
            throw new InvalidArgumentException('A guest cart owner needs a non-empty session token.');
        }

        return new self(null, null, $sessionToken);
    }

    public function isGuest(): bool
    {
        return $this->sessionToken !== null;
    }
}

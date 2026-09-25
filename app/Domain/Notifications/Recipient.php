<?php

namespace App\Domain\Notifications;

use App\Models\User;

/**
 * Who a message goes to, resolved when it is queued (05.12 §7.1). The
 * address is recorded on the log row as it was then, so a later change of
 * email never re-routes a message already sent.
 */
final readonly class Recipient
{
    public string $email;

    public function __construct(
        string $email,
        public ?int $userId = null,
        public ?int $companyId = null,
        public ?string $name = null,
    ) {
        $this->email = mb_strtolower(trim($email));
    }

    public static function user(User $user, ?int $companyId = null): self
    {
        return new self($user->email, $user->id, $companyId, $user->first_name);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §17.2 — one 2FA recovery code. `code_hash` is Argon2id; the
 * plaintext is shown to the user once, at issue, and never stored.
 * Spent by setting `used_at`. Managed only through
 * App\Domain\Identity\RecoveryCodes.
 *
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
class UserTwoFactorRecoveryCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}

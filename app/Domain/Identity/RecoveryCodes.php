<?php

namespace App\Domain\Identity;

use App\Models\User;
use App\Models\UserTwoFactorRecoveryCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 2FA recovery codes (05.13 §12.2, 02 §17.2): a set of single-use codes,
 * shown once and stored only as Argon2id hashes.
 *
 * Format `xxxxx-xxxxx` from an alphabet without look-alikes (no 0/o,
 * 1/l/i), so a code copied onto paper can be typed back reliably: 10
 * characters of 32 symbols is 50 bits per code.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    /** Warn the user at this many unused codes or fewer. */
    public const LOW_WATERMARK = 2;

    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * @return list<string> plaintext codes, to show once
     */
    public static function generate(): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $chars = '';
            for ($c = 0; $c < 10; $c++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($chars, 0, 5).'-'.substr($chars, 5);
        }

        return $codes;
    }

    /**
     * Replaces the user's whole set — a set is never topped up (02 §17.2).
     *
     * @param  list<string>  $plaintext
     */
    public static function replace(User $user, array $plaintext): void
    {
        DB::transaction(function () use ($user, $plaintext) {
            UserTwoFactorRecoveryCode::query()->where('user_id', $user->id)->delete();

            foreach ($plaintext as $code) {
                UserTwoFactorRecoveryCode::create([
                    'user_id' => $user->id,
                    'code_hash' => Hash::make(self::normalise($code)),
                ]);
            }
        });
    }

    /**
     * Spends a code if it matches one of the user's unused codes. The
     * unused rows are locked, so two concurrent sign-ins cannot both
     * spend the same code.
     */
    public static function consume(User $user, string $code): bool
    {
        $code = self::normalise($code);
        if ($code === '') {
            return false;
        }

        return DB::transaction(function () use ($user, $code) {
            $unused = UserTwoFactorRecoveryCode::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->get();

            foreach ($unused as $row) {
                if (Hash::check($code, $row->code_hash)) {
                    $row->update(['used_at' => now()]);

                    return true;
                }
            }

            return false;
        });
    }

    public static function remaining(User $user): int
    {
        return UserTwoFactorRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count();
    }

    /** Lower-case, spaces dropped, hyphen restored — tolerant of how a code is typed back. */
    private static function normalise(string $code): string
    {
        $compact = preg_replace('/[^a-z0-9]/', '', strtolower($code)) ?? '';

        return strlen($compact) === 10 ? substr($compact, 0, 5).'-'.substr($compact, 5) : '';
    }
}

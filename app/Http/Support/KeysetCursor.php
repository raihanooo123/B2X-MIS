<?php

namespace App\Http\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * 06 §5.1, §18: an opaque keyset cursor for 05.16 "Load more" lists.
 * Encrypted, and bound to the list, the user, the acting company and the
 * filters and sort it was issued for — a cursor from another list, user
 * or filter set is refused rather than silently restarting page one. The
 * position is the trailing sort-key tuple, `id` last.
 */
final class KeysetCursor
{
    /**
     * @param  array<string, scalar|null>  $filters
     * @param  array<string, int|string|null>  $position
     */
    public static function encode(string $list, int $userId, ?int $companyId, array $filters, array $position): string
    {
        return Crypt::encryptString((string) json_encode([
            'l' => $list,
            'u' => $userId,
            'c' => $companyId,
            'f' => self::fingerprint($filters),
            'p' => $position,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return array<string, int|string|null>|null the position, or null for the first page
     *
     * @throws ValidationException
     */
    public static function decode(?string $cursor, string $list, int $userId, ?int $companyId, array $filters): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($data) && ($data['l'] ?? null) === $list && ($data['u'] ?? null) === $userId
                && ($data['c'] ?? null) === $companyId && ($data['f'] ?? null) === self::fingerprint($filters)
                && is_array($data['p'] ?? null) && is_int($data['p']['id'] ?? null)) {
                /** @var array<string, int|string|null> $position */
                $position = $data['p'];

                return $position;
            }
        } catch (DecryptException|JsonException) {
            // Refused below.
        }

        throw ValidationException::withMessages(['cursor' => 'This list has changed. Reload it from the start.']);
    }

    /** @param array<string, scalar|null> $filters */
    private static function fingerprint(array $filters): string
    {
        ksort($filters);

        return hash('sha256', (string) json_encode($filters));
    }
}

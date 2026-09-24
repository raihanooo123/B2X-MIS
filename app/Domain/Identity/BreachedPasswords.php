<?php

namespace App\Domain\Identity;

use Illuminate\Support\Facades\Log;

/**
 * 07 §6.1's breached-password check, offline (05.13 §5.4, §19 Q17 —
 * decided 2026-09-24): Have I Been Pwned's SHA-1 list, held on this
 * server as range files and refreshed quarterly by
 * `auth:refresh-breached-passwords`. A check makes no network call, so
 * nothing derived from a password ever leaves the platform.
 *
 * Layout mirrors HIBP's range API, one file per 5-hex-digit SHA-1 prefix:
 *
 *   {directory}/{PREFIX}.txt  — lines "SUFFIX:COUNT", SUFFIX the other 35
 *                               hex digits, upper case
 *
 * If the list is absent the check cannot be made. It then **fails open**
 * — the password is accepted — and logs at `critical`, because refusing
 * every new password would stop registration, resets and invitations
 * outright. A missing list is an operational fault to alert on (07 §9.3),
 * not a reason to lock buyers out.
 */
final class BreachedPasswords
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? (string) config('auth.breached_passwords.path'), '/');
    }

    public function isBreached(string $password): bool
    {
        $sha1 = strtoupper(sha1($password));
        $file = $this->directory.'/'.substr($sha1, 0, 5).'.txt';

        if (! is_file($file)) {
            Log::critical('Breached-password list unavailable; password accepted unchecked.', [
                'directory' => $this->directory,
                'directory_exists' => is_dir($this->directory),
            ]);

            return false;
        }

        $suffix = substr($sha1, 5);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (strncmp($line, $suffix, 35) === 0) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    public function directory(): string
    {
        return $this->directory;
    }
}

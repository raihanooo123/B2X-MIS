<?php

namespace App\Domain\Identity;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Sign-in lockout, 05.13 §6.2 (decided 2026-09-24, window 05.13 §19 Q18):
 *
 *   5 consecutive failures → 1 minute, 10 → 5 minutes, 15 and every one
 *   after → 30 minutes; counted per IP and per identifier independently,
 *   a lock on either refusing the attempt.
 *
 * Counters live only in the cache (Redis), never the database. Each
 * failure slides the counter's 15-minute window forward, so a counter
 * lapses 15 minutes after the last failure. A successful sign-in clears
 * the identifier's counter, not the IP's — clearing the IP counter would
 * let an attacker holding one valid account reset it between guesses at
 * others.
 *
 * Unknown identifiers are counted exactly like real ones, so a lockout
 * says nothing about whether an account exists.
 */
final class LoginThrottle
{
    public const WINDOW_SECONDS = 900;

    /** Failures → lock seconds. At 15 and above every failure re-locks. */
    private const LADDER = [5 => 60, 10 => 300, 15 => 1800];

    private readonly Repository $cache;

    public function __construct(?Repository $cache = null)
    {
        $this->cache = $cache ?? Cache::store();
    }

    /** Seconds until the attempt may proceed; 0 when not locked. */
    public function lockedFor(string $ip, string $identifier): int
    {
        return max($this->remaining($this->lockKey('ip', $ip)), $this->remaining($this->lockKey('id', $identifier)));
    }

    /**
     * Records one failure on both counters and applies any lock the
     * ladder calls for. Returns the lock now in force, in seconds.
     */
    public function recordFailure(string $ip, string $identifier): int
    {
        $this->bump('ip', $ip);
        $this->bump('id', $identifier);

        return $this->lockedFor($ip, $identifier);
    }

    public function clearIdentifier(string $identifier): void
    {
        $this->cache->forget($this->countKey('id', $identifier));
        $this->cache->forget($this->lockKey('id', $identifier));
    }

    public static function lockSecondsFor(int $failures): int
    {
        if ($failures >= 15) {
            return self::LADDER[15];
        }

        return self::LADDER[$failures] ?? 0;
    }

    private function bump(string $kind, string $value): void
    {
        $key = $this->countKey($kind, $value);
        $failures = (int) $this->cache->get($key, 0) + 1;
        $this->cache->put($key, $failures, self::WINDOW_SECONDS);

        $lock = self::lockSecondsFor($failures);
        if ($lock > 0) {
            $this->cache->put($this->lockKey($kind, $value), now()->getTimestamp() + $lock, $lock);
        }
    }

    private function remaining(string $lockKey): int
    {
        $until = $this->cache->get($lockKey);

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    private function countKey(string $kind, string $value): string
    {
        return "auth:login:failures:{$kind}:".$this->normalise($kind, $value);
    }

    private function lockKey(string $kind, string $value): string
    {
        return "auth:login:lock:{$kind}:".$this->normalise($kind, $value);
    }

    /** Hashed so an email never appears in a cache key; identifiers are case-folded. */
    private function normalise(string $kind, string $value): string
    {
        return hash('sha256', $kind === 'id' ? mb_strtolower(trim($value)) : $value);
    }
}

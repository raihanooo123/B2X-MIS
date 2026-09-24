<?php

/*
 * 07 §6.1: passwords are hashed with Argon2id. Laravel's default driver
 * is bcrypt, and with no config/hashing.php that default applied to every
 * password — 05.13 §19 Q15, resolved 2026-09-24.
 *
 * Existing bcrypt hashes still verify (`verify` below is false, so the
 * hasher does not insist every stored hash uses the configured driver);
 * SignIn rehashes each one to Argon2id on that user's next successful
 * sign-in (Hash::needsRehash), since a stored hash cannot be converted
 * without the plaintext.
 *
 * Argon2id parameters are PHP's defaults (64 MiB, 4 passes, 1 lane),
 * which meet OWASP's Argon2id floor. phpunit.xml lowers them for test
 * speed only.
 */

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => false,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => false,
    ],

    // SignIn rehashes itself, writing `password_hash` (02 §4.2). The
    // framework's automatic rehash assumes a `password` column.
    'rehash_on_login' => false,

];

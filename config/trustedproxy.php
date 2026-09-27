<?php

/*
 * Proxies whose X-Forwarded-* headers are trusted. Laravel's TrustProxies
 * middleware reads `trustedproxy.proxies` when nothing else configures it.
 * Without the production load balancer listed here, Request::ip() is the
 * balancer's address, and 02 §25.1's terms acceptance records the wrong IP
 * (02 §25.10).
 *
 * TRUSTED_PROXIES is a comma-separated list of addresses or CIDR ranges.
 * The default is an empty list: nothing trusted. It is an array, not
 * null, on purpose — given null, the middleware trusts every caller ('*')
 * on Laravel Cloud and on *.on-forge.com / *.on-vapor.com hosts, and a
 * client could then set its own recorded IP with an X-Forwarded-For header.
 */
return [
    'proxies' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        fn (string $proxy): bool => $proxy !== '',
    )),
];

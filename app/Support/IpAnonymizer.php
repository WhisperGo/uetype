<?php

namespace App\Support;

/**
 * Masks the host part of an IP address before it is stored.
 *
 * A full address is personal data under GDPR and UU PDP: it identifies a household or
 * device and survives in backups, so a database leak turns monitoring logs into a
 * deanonymisation tool. Masking keeps what the logs are actually used for -- spotting
 * repeated abuse from one network -- while dropping the part that points at one person.
 *
 * IPv4 loses the final octet (192.168.1.77 -> 192.168.1.0) and IPv6 keeps only its /48
 * routing prefix, matching the convention used by mainstream analytics tools.
 */
class IpAnonymizer
{
    public static function mask(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $blocks = explode(':', $ip);

            return implode(':', array_slice($blocks, 0, 3)).'::';
        }

        // Not a recognisable address (spoofed header, proxy artefact): store nothing
        // rather than an arbitrary string that might still identify someone.
        return null;
    }
}

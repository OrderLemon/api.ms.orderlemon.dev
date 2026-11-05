<?php

declare(strict_types=1);

namespace Plugins\Imports\Website;

// ULID (26 characters, Crockford base32): 48-bit millisecond time + 80 random bits.
// notifications.ms requires one as the event_id of every event.
final class Ulid
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $time = (int) floor(microtime(true) * 1000);
        $encodedTime = '';
        for ($i = 0; $i < 10; $i++) {
            $encodedTime = self::ALPHABET[$time % 32] . $encodedTime;
            $time = intdiv($time, 32);
        }

        // 256 is a multiple of 32, so "byte % 32" is uniform.
        $random = '';
        foreach (str_split(random_bytes(16)) as $byte) {
            $random .= self::ALPHABET[ord($byte) % 32];
        }

        return $encodedTime . $random;
    }
}

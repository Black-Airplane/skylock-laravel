<?php

namespace BlackAirplane\SkyLockReporter;

/**
 * Orders Debian and Ubuntu package versions the way dpkg does
 * (`dpkg --compare-versions`), so the reporter can tell whether a version
 * in the security pocket is newer than the one installed without running
 * a command.
 *
 * A version is `[epoch:]upstream[-revision]`. Epochs compare as numbers;
 * then the upstream version, then the revision, each in turns of non-digits
 * and digits. Non-digits compare by character, with letters before
 * everything else and `~` before even the end of the string, so `1.0~rc1`
 * comes before `1.0`. Digits compare as numbers, so `1.01` is `1.1`.
 */
final class DebianVersion
{
    /**
     * @return int -1 when $a comes before $b, 0 when they're the same version, 1 when after
     */
    public static function compare(string $a, string $b): int
    {
        [$epochA, $upstreamA, $revisionA] = self::parts($a);
        [$epochB, $upstreamB, $revisionB] = self::parts($b);

        if ($epochA !== $epochB) {
            return $epochA <=> $epochB;
        }

        return self::order($upstreamA, $upstreamB) ?: self::order($revisionA, $revisionB);
    }

    /**
     * The epoch runs to the first colon and the revision from the last
     * hyphen, as dpkg reads them. No revision reads as an empty one, which
     * dpkg treats as `0`.
     *
     * @return array{int, string, string}
     */
    private static function parts(string $version): array
    {
        $version = trim($version);
        $epoch = 0;
        $colon = strpos($version, ':');
        if ($colon !== false) {
            $epoch = (int) substr($version, 0, $colon);
            $version = substr($version, $colon + 1);
        }

        $hyphen = strrpos($version, '-');

        return $hyphen === false ? [$epoch, $version, ''] : [$epoch, substr($version, 0, $hyphen), substr($version, $hyphen + 1)];
    }

    /**
     * dpkg's verrevcmp(): non-digits by weight, then digits as a number.
     */
    private static function order(string $a, string $b): int
    {
        $i = 0;
        $j = 0;
        $lengthA = strlen($a);
        $lengthB = strlen($b);

        while ($i < $lengthA || $j < $lengthB) {
            while (($i < $lengthA && ! self::digit($a[$i])) || ($j < $lengthB && ! self::digit($b[$j]))) {
                $weightA = self::weight($a[$i] ?? '');
                $weightB = self::weight($b[$j] ?? '');
                if ($weightA !== $weightB) {
                    return $weightA <=> $weightB;
                }
                $i++;
                $j++;
            }

            while ($i < $lengthA && $a[$i] === '0') {
                $i++;
            }
            while ($j < $lengthB && $b[$j] === '0') {
                $j++;
            }

            $firstDifference = 0;
            while ($i < $lengthA && $j < $lengthB && self::digit($a[$i]) && self::digit($b[$j])) {
                if ($firstDifference === 0) {
                    $firstDifference = ord($a[$i]) <=> ord($b[$j]);
                }
                $i++;
                $j++;
            }

            // The longer run of digits, leading zeros gone, is the bigger number.
            if ($i < $lengthA && self::digit($a[$i])) {
                return 1;
            }
            if ($j < $lengthB && self::digit($b[$j])) {
                return -1;
            }
            if ($firstDifference !== 0) {
                return $firstDifference;
            }
        }

        return 0;
    }

    /**
     * A character's weight among non-digits: `~` lowest, then the end of the
     * string (and a digit, where the other side has a non-digit), then
     * letters, then everything else. ASCII only, whatever the locale.
     */
    private static function weight(string $character): int
    {
        if ($character === '' || self::digit($character)) {
            return 0;
        }
        if ($character === '~') {
            return -1;
        }

        $code = ord($character);

        return ($code >= 65 && $code <= 90) || ($code >= 97 && $code <= 122) ? $code : $code + 256;
    }

    private static function digit(string $character): bool
    {
        return $character !== '' && ord($character) >= 48 && ord($character) <= 57;
    }
}

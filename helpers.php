<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $value, bool $doubleEncode = true): string
    {
        return $value === null ? 'null' : htmlspecialchars($value, ENT_QUOTES, 'UTF-8', $doubleEncode);
    }
}

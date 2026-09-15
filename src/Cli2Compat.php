<?php

declare(strict_types=1);

namespace Phore\Cli\Types;

if (! function_exists(__NAMESPACE__ . '\\startsWith')) {
    function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }
}

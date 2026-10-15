<?php
declare(strict_types=1);

namespace Glow;

/** Reads KEY=value lines from .env into getenv(). Real environment variables win. */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            if (getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}

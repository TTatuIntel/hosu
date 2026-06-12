<?php
/**
 * Environment variable loader.
 * Reads .env file and populates $_ENV / getenv().
 * Values in .env always override any empty server defaults.
 */
function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        // Strip surrounding quotes
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

loadEnv(__DIR__ . '/.env');

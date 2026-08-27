<?php
/**
 * Custodia — configuration.
 * Reads a small set of environment variables with sane local-dev defaults,
 * so a fresh XAMPP/WAMP install works out of the box against the bundled
 * schema.sql without editing PHP source. Override via real env vars (or
 * your web server's SetEnv directives) in production.
 *
 * custodia_config() is the only way to read config — never `require` this
 * file directly for its return value. This file is included via
 * require_once from several places (db.php, auth.php, ...), and PHP's
 * require_once only returns the array on the FIRST inclusion anywhere in
 * the request; every later require_once/include call for the same
 * resolved path just returns `true`. custodia_config() sidesteps that by
 * caching the array itself instead of relying on the include return value.
 */

if (!function_exists('custodia_env')) {
    function custodia_env(string $key, string $default): string
    {
        $value = getenv($key);
        return $value !== false && $value !== '' ? $value : $default;
    }
}

if (!function_exists('custodia_config')) {
    function custodia_config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = [
                'db' => [
                    'host'     => custodia_env('CUSTODIA_DB_HOST', '127.0.0.1'),
                    'port'     => custodia_env('CUSTODIA_DB_PORT', '3306'),
                    'name'     => custodia_env('CUSTODIA_DB_NAME', 'custodia'),
                    'user'     => custodia_env('CUSTODIA_DB_USER', 'custodia'),
                    'password' => custodia_env('CUSTODIA_DB_PASSWORD', 'custodia'),
                ],
                // Local filesystem path used to store uploaded document versions —
                // the same stand-in-for-S3 approach the earlier Node version used.
                'storage_path' => custodia_env('CUSTODIA_STORAGE_PATH', __DIR__ . '/../storage'),
                'session_name' => 'custodia_session',
                // Demo-account password shown in the README/seed output.
                'demo_password' => 'ChangeMe123!',
            ];
        }
        return $config;
    }
}

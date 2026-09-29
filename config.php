<?php

declare(strict_types=1);

session_name('common_ground_session');
session_start();

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : trim($value);
}

function db(): ?PDO
{
    static $connection = false;
    static $attempted = false;
    global $database_error;

    if ($attempted) {
        return $connection ?: null;
    }
    $attempted = true;

    $host = env_value('DB_HOST', 'localhost');
    $name = env_value('DB_NAME', 'common_ground');
    if ($host === '' && $name === '') {
        return null;
    }
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        $database_error = 'The PHP MySQL driver is not installed. The site is showing its demo catalog.';
        return null;
    }
    if ($host === '' || $name === '') {
        $database_error = 'Set both DB_HOST and DB_NAME to connect to MySQL.';
        return null;
    }

    try {
        $connection = new PDO(
            'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
            env_value('DB_USER', 'root'),
            '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
        error_log('MySQL connection failed: ' . $exception->getMessage());
        $database_error = 'Could not connect to MySQL. Check the database settings and schema.';
    }

    return $connection ?: null;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function set_flash(string $message, string $kind = 'info'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your form expired. Go back, refresh the page, and try again.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function oauth_provider(string $provider): ?array
{
    $providers = [
        'google' => [
            'client_id' => env_value('GOOGLE_CLIENT_ID'), 'client_secret' => env_value('GOOGLE_CLIENT_SECRET'),
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
            'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo', 'scope' => 'openid email profile',
        ],
        'facebook' => [
            'client_id' => env_value('FACEBOOK_CLIENT_ID'), 'client_secret' => env_value('FACEBOOK_CLIENT_SECRET'),
            'authorize' => 'https://www.facebook.com/v19.0/dialog/oauth', 'token' => 'https://graph.facebook.com/v19.0/oauth/access_token',
            'userinfo' => 'https://graph.facebook.com/me?fields=id,name,email', 'scope' => 'email,public_profile',
        ],
        'linkedin' => [
            'client_id' => env_value('LINKEDIN_CLIENT_ID'), 'client_secret' => env_value('LINKEDIN_CLIENT_SECRET'),
            'authorize' => 'https://www.linkedin.com/oauth/v2/authorization', 'token' => 'https://www.linkedin.com/oauth/v2/accessToken',
            'userinfo' => 'https://api.linkedin.com/v2/userinfo', 'scope' => 'openid profile email',
        ],
    ];
    return $providers[$provider] ?? null;
}

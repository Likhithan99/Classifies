<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config.php';
$email = filter_var(env_value('ADMIN_EMAIL'), FILTER_VALIDATE_EMAIL);
$password = env_value('ADMIN_PASSWORD');
$name = env_value('ADMIN_NAME', 'Market Administrator');

if (!$email || strlen($password) < 12) {
    fwrite(STDERR, "Set ADMIN_EMAIL and ADMIN_PASSWORD (at least 12 characters) before running this script.\n");
    exit(1);
}

try {
    $database = db();
    if (!$database) {
        throw new RuntimeException('MySQL is unavailable. Check the DB_* settings and PHP MySQL PDO driver.');
    }
    $statement = $database->prepare('SELECT id FROM users WHERE email = :email');
    $statement->execute(['email' => $email]);
    if ($statement->fetchColumn()) {
        fwrite(STDERR, "An account already uses this email. No changes were made.\n");
        exit(1);
    }
    $insert = $database->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, \'admin\')');
    $insert->execute(['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Admin account created for {$email}.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

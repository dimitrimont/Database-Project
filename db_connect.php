<?php
// Connects to MySQL and leaves the connection in $dbc.
// The settings come from config.php (copy config.example.php; it is never committed) or, failing
// that, from the environment: DB_HOST, DB_NAME, DB_USER, DB_PASSWORD (see docker-compose.yml).

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$setting = function (string $key, string $default = '') use ($config): string {
    return $config[$key] ?? (getenv($key) !== false ? getenv($key) : $default);
};

try {
    $dbc = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
                $setting('DB_HOST', 'localhost'), $setting('DB_NAME', 'CSC355FA25Football')),
        $setting('DB_USER'),
        $setting('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // The details (user name, server address) go to the server's log, not to the visitor.
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('The database is not available right now.');
}

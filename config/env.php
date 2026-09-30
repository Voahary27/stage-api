<?php


require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
 

if (file_exists(__DIR__ . '/../.env')) {
    
    $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
    $dotenv->load();
}


function env(string $key, $default = null) {
    // 1. Cherche dans $_ENV (si variables_order contient E)
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    // 2. Cherche dans $_SERVER (toujours rempli par PHP)
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    // 3. Cherche via getenv() (méthode universelle)
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    // 4. Retourne la valeur par défaut
    return $default;
}


$requiredEnvVars = [
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
    'JWT_SECRET'
];

foreach ($requiredEnvVars as $var) {
    if (env($var) === null) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            "success" => false,
            "message" => "Configuration manquante : la variable '$var' n'est pas définie"
        ]);
        exit();
    }
}
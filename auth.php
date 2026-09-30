<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/jwt.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function requireAuth() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(["success" => false, "message" => "Token manquant"]);
        exit();
    }

    try {
        $decoded = JWT::decode($matches[1], new Key(JWT_SECRET, JWT_ALGO));
        return $decoded->data;
    } catch (\Firebase\JWT\ExpiredException $e) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(["success" => false, "message" => "Token expiré"]);
        exit();
    } catch (\Exception $e) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(["success" => false, "message" => "Token invalide"]);
        exit();
    }
}

function requireRole(string $role) {
    $user = requireAuth();
    if ($user->role !== $role) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(["success" => false, "message" => "Accès refusé"]);
        exit();
    }
    return $user;
}
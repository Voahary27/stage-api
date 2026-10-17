<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();
$stmt = $db->query("SELECT service_id, service_nom FROM SERVICE ORDER BY service_id");
$services = $stmt->fetchAll();

echo json_encode([
    "success"  => true,
    "services" => $services
]);
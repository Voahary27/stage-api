<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$admin = requireRole('admin');
$db = (new Database())->getConnection();

// SG
$stmt = $db->prepare(
    "SELECT utilisateur_matricule, utilisateur_nom, utilisateur_prenom
     FROM UTILISATEUR
     WHERE utilisateur_role = 'sg'
     ORDER BY utilisateur_nom ASC"
);
$stmt->execute();
$sg = $stmt->fetchAll();

// Admins
$stmt = $db->prepare(
    "SELECT utilisateur_matricule, utilisateur_nom, utilisateur_prenom
     FROM UTILISATEUR
     WHERE utilisateur_role = 'admin'
     ORDER BY utilisateur_nom ASC"
);
$stmt->execute();
$admins = $stmt->fetchAll();

echo json_encode([
    "success" => true,
    "sg"      => $sg,
    "admins"  => $admins
]);
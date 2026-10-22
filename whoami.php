<?php

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$user = requireAuth();
$db = (new Database())->getConnection();

$stmt = $db->prepare(
    "SELECT utilisateur_matricule, utilisateur_nom, utilisateur_prenom, utilisateur_role
     FROM UTILISATEUR
     WHERE utilisateur_matricule = :matricule"
);
$stmt->execute([':matricule' => $user->matricule]);
$profil = $stmt->fetch();

if (!$profil) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Utilisateur introuvable"]);
    exit();
}

echo json_encode([
    "success" => true,
    "profil"  => $profil
]);

<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$user = requireAuth();
$db = (new Database())->getConnection();

$data = json_decode(file_get_contents("php://input"), true);

$nom        = trim($data['nom']        ?? '');
$prenom     = trim($data['prenom']     ?? '');
$email      = trim($data['email']      ?? '');
$contact    = trim($data['contact']    ?? '');
$fonction   = trim($data['fonction']   ?? '');
$service    = $data['service']         ?? null;   // INT
$direction  = trim($data['direction']  ?? '');
$corps      = trim($data['corps']      ?? '');

// Validation
if (!$nom || !$prenom) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Nom et prénom obligatoires"]);
    exit();
}

if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email invalide"]);
    exit();
}

try {
    $db->prepare(
        "UPDATE UTILISATEUR
         SET utilisateur_nom          = :nom,
             utilisateur_prenom       = :prenom,
             utilisateur_email        = :email,
             utilisateur_contact      = :contact,
             utilisateur_fonction     = :fonction,
             utilisateur_service      = :service,
             utilisateur_direction    = :direction,
             utilisateur_corps        = :corps
         WHERE utilisateur_matricule = :matricule"
    )->execute([
        ':nom'        => $nom,
        ':prenom'     => $prenom,
        ':email'      => $email      ?: null,
        ':contact'    => $contact    ?: null,
        ':fonction'   => $fonction   ?: null,
        ':service'    => $service    ?: null,
        ':direction'  => $direction  ?: null,
        ':corps'      => $corps      ?: null,
        ':matricule'  => $user->matricule
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Profil mis à jour"
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("user_update.php : " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erreur serveur"]);
}
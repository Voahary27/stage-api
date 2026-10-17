<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config/database.php';

$data = json_decode(file_get_contents("php://input"), true);

$matricule   = trim($data['matricule']    ?? '');
$nom         = trim($data['nom']          ?? '');
$prenom      = trim($data['prenom']       ?? '');
$email       = trim($data['email']        ?? '');
$contact     = trim($data['contact']      ?? '');
$fonction    = trim($data['fonction']     ?? '');
$service     = $data['service']           ?? null;
$direction   = trim($data['direction']    ?? '');
$corps       = trim($data['corps']        ?? '');
$dateService = trim($data['date_service'] ?? '');
$password    = $data['password']          ?? '';

if (!$matricule || !$nom || !$prenom || !$password || !$dateService) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Les champs obligatoires ne sont pas remplis"
    ]);
    exit();
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Le mot de passe doit contenir au moins 6 caractères"
    ]);
    exit();
}

try {
    $db = (new Database())->getConnection();

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM UTILISATEUR WHERE utilisateur_matricule = :matricule"
    );
    $stmt->execute([':matricule' => $matricule]);

    if ((int) $stmt->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Ce matricule est déjà utilisé"
        ]);
        exit();
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = "INSERT INTO UTILISATEUR
              (utilisateur_matricule, utilisateur_nom, utilisateur_prenom,
               utilisateur_email, utilisateur_contact, utilisateur_fonction,
               utilisateur_date_prise_service, utilisateur_mdp,
               utilisateur_service, utilisateur_direction, utilisateur_corps,
               utilisateur_role)
            VALUES
              (:matricule, :nom, :prenom,
               :email, :contact, :fonction,
               :date_service, :mdp,
               :service, :direction, :corps,
               'employe')";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':matricule'    => $matricule,
        ':nom'          => $nom,
        ':prenom'       => $prenom,
        ':email'        => $email,
        ':contact'      => $contact,
        ':fonction'     => $fonction,
        ':date_service' => $dateService,
        ':mdp'          => $hash,
        ':service'      => $service,
        ':direction'    => $direction,
        ':corps'        => $corps
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Inscription réussie"
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("register.php : " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "Erreur serveur lors de l'inscription"
    ]);
}
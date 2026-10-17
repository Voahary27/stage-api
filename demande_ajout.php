<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$user = requireAuth();
$db   = (new Database())->getConnection();

$data = json_decode(file_get_contents("php://input"), true);

$type  = $data['type']       ?? null;
$motif = $data['motif']      ?? null;
$debut = $data['date_debut'] ?? null;
$fin   = $data['date_fin']   ?? null;
$lieu  = $data['lieu']       ?? 'Antananarivo';

if (!$type || !$debut) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Champs obligatoires manquants"]);
    exit();
}

if (!in_array($type, ['conge', 'permission'], true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Type de demande invalide"]);
    exit();
}

if ($type === 'conge') {
    if (!$fin) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "La date de fin est obligatoire pour un congé"]);
        exit();
    }

    if (strtotime($fin) < strtotime($debut)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "La date de fin doit être après la date de début"]);
        exit();
    }
}

if ($type === 'permission') {
    $fin = null;
}

try {
    $stmt = $db->prepare(
        "SELECT utilisateur_role FROM UTILISATEUR WHERE utilisateur_matricule = :matricule"
    );
    $stmt->execute([':matricule' => $user->matricule]);
    $role = $stmt->fetchColumn();

    $niveauInitial = match($role) {
        'chef'  => 1,
        'sg'    => 2,
        'admin' => 3,
        default => 0
    };

    $statusInitial = ($niveauInitial === 3) ? 'acceptee' : 'en_attente';

    $sql = "INSERT INTO DEMANDE
              (demande_type, demande_motif, demande_date_debut, demande_date_fin,
               demande_lieu_jouissance, utilisateur_matricule,
               demande_niveau_validation, demande_status)
            VALUES (:type, :motif, :debut, :fin, :lieu, :matricule, :niveau, :status)
            RETURNING demande_id";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':type'      => $type,
        ':motif'     => $motif,
        ':debut'     => $debut,
        ':fin'       => $fin,
        ':lieu'      => $lieu,
        ':matricule' => $user->matricule,
        ':niveau'    => $niveauInitial,
        ':status'    => $statusInitial
    ]);

    $demandeId = (int) $stmt->fetchColumn();

    echo json_encode([
        "success"    => true,
        "message"    => "Demande envoyée",
        "demande_id" => $demandeId,
        "type"       => $type,
        "niveau"     => $niveauInitial
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("demande_ajout.php : " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erreur serveur"]);
}
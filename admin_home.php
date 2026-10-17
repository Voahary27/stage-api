<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$admin = requireRole('admin');
$db = (new Database())->getConnection();
$matricule = $admin->matricule;

$sql = "SELECT
            u.utilisateur_matricule,
            u.utilisateur_nom,
            u.utilisateur_prenom,
            u.utilisateur_fonction,
            u.utilisateur_role,
            u.utilisateur_service,
            s.service_nom
        FROM UTILISATEUR u
        LEFT JOIN SERVICE s ON s.service_id = u.utilisateur_service
        WHERE u.utilisateur_matricule = :matricule";

$stmt = $db->prepare($sql);
$stmt->execute([':matricule' => $matricule]);
$profil = $stmt->fetch();

if (!$profil) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Administrateur introuvable"
    ]);
    exit();
}

$kpis = [];

$sql = "SELECT COUNT(*) AS en_attente
        FROM DEMANDE
        WHERE demande_status = 'en_attente'
          AND demande_niveau_validation = 2";
$stmt = $db->prepare($sql);
$stmt->execute();
$kpis['en_attente'] = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) AS nbr_utilisateurs FROM UTILISATEUR";
$stmt = $db->prepare($sql);
$stmt->execute();
$kpis['nbr_utilisateurs'] = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*)
        FROM DEMANDE
        WHERE DATE_TRUNC('month', demande_date_creation)
            = DATE_TRUNC('month', CURRENT_DATE)";
$stmt = $db->prepare($sql);
$stmt->execute();
$kpis['ce_mois'] = (int) $stmt->fetchColumn();

echo json_encode([
    "success" => true,
    "profil"  => $profil,
    "kpis"    => $kpis
]);
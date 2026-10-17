<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$user = requireAuth();
$db   = (new Database())->getConnection();

$stmt = $db->prepare(
    "SELECT utilisateur_role, utilisateur_service
     FROM UTILISATEUR
     WHERE utilisateur_matricule = :matricule"
);
$stmt->execute([':matricule' => $user->matricule]);
$me = $stmt->fetch();

if (!$me || !in_array($me['utilisateur_role'], ['chef', 'sg', 'admin'], true)) {
    echo json_encode(["success" => true, "demandes" => []]);
    exit();
}

$role    = $me['utilisateur_role'];
$service = (int) $me['utilisateur_service'];

$niveauAttendu = match($role) {
    'chef'  => 0,
    'sg'    => 1,
    'admin' => 2,
    default => -1
};

$sql = "SELECT
            d.demande_id,
            d.demande_type,
            d.demande_motif,
            d.demande_date_debut,
            d.demande_date_fin,
            d.demande_date_creation,
            d.demande_status,
            d.demande_niveau_validation,
            d.demande_lieu_jouissance,
            d.utilisateur_matricule,
            u.utilisateur_nom,
            u.utilisateur_prenom,
            u.utilisateur_service,
            s.service_nom
        FROM DEMANDE d
        INNER JOIN UTILISATEUR u ON u.utilisateur_matricule = d.utilisateur_matricule
        LEFT JOIN SERVICE s ON s.service_id = u.utilisateur_service
        WHERE d.demande_status = 'en_attente'
          AND d.demande_niveau_validation = :niveau";

$params = [':niveau' => $niveauAttendu];

if ($role === 'chef') {
    $sql .= " AND u.utilisateur_service = :service";
    $params[':service'] = $service;
}

$sql .= " ORDER BY d.demande_date_creation ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$demandes = $stmt->fetchAll();

foreach ($demandes as &$d) {
    $d['demande_id'] = (int) $d['demande_id'];
    $d['demande_niveau_validation'] = (int) $d['demande_niveau_validation'];

    if ($d['demande_type'] === 'conge' && $d['demande_date_fin']) {
        $debut = new DateTime($d['demande_date_debut']);
        $fin   = new DateTime($d['demande_date_fin']);
        $d['nb_jours'] = $debut->diff($fin)->days + 1;
    } else {
        $d['nb_jours'] = 0;
    }
}
unset($d);

echo json_encode([
    "success"  => true,
    "demandes" => $demandes
]);
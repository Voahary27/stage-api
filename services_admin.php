<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$admin = requireRole('admin');
$db = (new Database())->getConnection();

$sql = "SELECT
            s.service_id,
            s.service_nom,
            u.utilisateur_matricule AS chef_matricule,
            u.utilisateur_nom      AS chef_nom,
            u.utilisateur_prenom   AS chef_prenom,
            (SELECT COUNT(*) FROM UTILISATEUR
             WHERE utilisateur_service = s.service_id) AS nb_employes
        FROM SERVICE s
        LEFT JOIN UTILISATEUR u
            ON u.utilisateur_service = s.service_id
           AND u.utilisateur_role = 'chef'
        ORDER BY s.service_id ASC";

$stmt = $db->prepare($sql);
$stmt->execute();
$services = $stmt->fetchAll();

foreach ($services as &$s) {
    $s['service_id']  = (int) $s['service_id'];
    $s['nb_employes'] = (int) $s['nb_employes'];
}
unset($s);

echo json_encode([
    "success"  => true,
    "services" => $services
]);
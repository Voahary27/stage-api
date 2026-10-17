<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$user = requireAuth();
$db = (new Database())->getConnection();

$sql = "SELECT solde_annuel_annee, solde_annuel_valeur
        FROM SOLDE_ANNUEL
        WHERE utilisateur_matricule = :matricule
        ORDER BY solde_annuel_annee ASC";

$stmt = $db->prepare($sql);
$stmt->execute([':matricule' => $user->matricule]);
$soldes = $stmt->fetchAll();

foreach ($soldes as &$s) {
    $s['solde_annuel_annee']  = (int) $s['solde_annuel_annee'];
    $s['solde_annuel_valeur'] = (int) $s['solde_annuel_valeur'];
}
unset($s);

$total = array_sum(array_column($soldes, 'solde_annuel_valeur'));

echo json_encode([
    "success" => true,
    "soldes"  => $soldes,
    "total"   => $total
]);
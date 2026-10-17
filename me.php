<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$user = requireAuth();
$db = (new Database())->getConnection();
$matricule = $user->matricule;

$sql = "SELECT
            u.utilisateur_matricule,
            u.utilisateur_nom,
            u.utilisateur_prenom,
            u.utilisateur_email,
            u.utilisateur_contact,
            u.utilisateur_fonction,
            u.utilisateur_date_prise_service,
            u.utilisateur_service,
            u.utilisateur_direction,
            u.utilisateur_corps,
            u.utilisateur_role,
            s.service_nom
        FROM UTILISATEUR u
        LEFT JOIN SERVICE s ON s.service_id = u.utilisateur_service
        WHERE u.utilisateur_matricule = :matricule";

$requete = $db->prepare($sql);
$requete->execute([":matricule" => $matricule]);
$userInfo = $requete->fetch();

$sql = "SELECT
            COALESCE(SUM(solde_annuel_valeur), 0) AS solde_restant,
            COALESCE(SUM(30 - solde_annuel_valeur), 0) AS jours_pris
        FROM SOLDE_ANNUEL
        WHERE utilisateur_matricule = :matricule";

$requete = $db->prepare($sql);
$requete->execute([":matricule" => $matricule]);
$soldeInfo = $requete->fetch();

$sql = "SELECT COUNT(*) FROM DEMANDE
        WHERE utilisateur_matricule = :matricule
          AND demande_status = 'en_attente'";
$stmt = $db->prepare($sql);
$stmt->execute([':matricule' => $matricule]);
$enAttentes = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) FROM DEMANDE
        WHERE utilisateur_matricule = :matricule
          AND demande_status = 'acceptee'";
$stmt = $db->prepare($sql);
$stmt->execute([':matricule' => $matricule]);
$acceptees = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) FROM DEMANDE
        WHERE utilisateur_matricule = :matricule
          AND demande_status = 'refusee'";
$stmt = $db->prepare($sql);
$stmt->execute([':matricule' => $matricule]);
$refusees = (int) $stmt->fetchColumn();

$sql = "SELECT
            demande_id,
            demande_type,
            demande_motif,
            demande_date_debut,
            demande_date_fin,
            demande_date_creation,
            demande_status,
            demande_niveau_validation,
            demande_lieu_jouissance,
            demande_matricule_decision,
            demande_date_decision,
            demande_motif_refus
        FROM DEMANDE
        WHERE utilisateur_matricule = :matricule
        ORDER BY demande_date_creation DESC
        LIMIT 20";

$stmt = $db->prepare($sql);
$stmt->execute([':matricule' => $matricule]);
$demandes = $stmt->fetchAll();

echo json_encode([
    "success" => true,
    "profil"  => $userInfo,
    "kpis"    => [
        "solde_restant" => (int) $soldeInfo['solde_restant'],
        "jours_pris"    => (int) $soldeInfo['jours_pris'],
        "en_attentes"   => $enAttentes,
        "acceptees"     => $acceptees,
        "refusees"      => $refusees
    ],
    "demandes" => $demandes
]);
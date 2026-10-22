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

// demandes avec details 
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
            d.demande_matricule_decision,
            d.demande_date_decision,
            d.demande_motif_refus,
            v.utilisateur_nom    AS validateur_nom,
            v.utilisateur_prenom AS validateur_prenom
        FROM DEMANDE d
        LEFT JOIN UTILISATEUR v ON v.utilisateur_matricule = d.demande_matricule_decision
        WHERE d.utilisateur_matricule = :matricule
        ORDER BY d.demande_date_creation DESC
        LIMIT 20";

$stmt = $db->prepare($sql);
$stmt->execute([':matricule' => $matricule]);
$demandes = $stmt->fetchAll();


foreach ($demandes as &$d) {
    $d['nb_jours']     = 0;
    $d['ventilation']  = [];
    $d['titre_annees'] = '';

    if ($d['demande_type'] === 'conge' && $d['demande_date_fin']) {
        $debut = new DateTime($d['demande_date_debut']);
        $fin   = new DateTime($d['demande_date_fin']);
        $d['nb_jours'] = $debut->diff($fin)->days + 1;

        if ($d['demande_status'] === 'acceptee') {
            $stmtDet = $db->prepare(
                "SELECT solde_annuel_annee, jours_deduit
                 FROM DEMANDE_DETAIL
                 WHERE demande_id = :id
                 ORDER BY solde_annuel_annee ASC"
            );
            $stmtDet->execute([':id' => $d['demande_id']]);
            foreach ($stmtDet->fetchAll() as $det) {
                $d['ventilation'][] = [
                    'annee' => (int) $det['solde_annuel_annee'],
                    'jours' => (int) $det['jours_deduit']
                ];
            }
        } elseif ($d['demande_status'] === 'en_attente') {
            $stmtSolde = $db->prepare(
                "SELECT solde_annuel_annee, solde_annuel_valeur
                 FROM SOLDE_ANNUEL
                 WHERE utilisateur_matricule = :m
                   AND solde_annuel_valeur > 0
                 ORDER BY solde_annuel_annee ASC"
            );
            $stmtSolde->execute([':m' => $matricule]);
            $soldes = $stmtSolde->fetchAll();

            $reste = $d['nb_jours'];
            foreach ($soldes as $s) {
                if ($reste <= 0) break;
                $annee  = (int) $s['solde_annuel_annee'];
                $dispo  = (int) $s['solde_annuel_valeur'];
                $deduit = min($reste, $dispo);
                $reste -= $deduit;
                $d['ventilation'][] = ['annee' => $annee, 'jours' => $deduit];
            }
        }

        if (!empty($d['ventilation'])) {
            $d['titre_annees'] = implode(', ', array_column($d['ventilation'], 'annee'));
        }
    }

    $d['demande_id'] = (int) $d['demande_id'];
}
unset($d);

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
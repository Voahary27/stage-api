<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$admin = requireRole('admin');
$db = (new Database())->getConnection();

$sql = "SELECT
            d.demande_id,
            d.demande_type,
            d.demande_motif,
            d.demande_date_debut,
            d.demande_date_fin,
            d.demande_date_creation,
            d.demande_date_decision,
            d.demande_status,
            d.demande_niveau_validation,
            d.demande_lieu_jouissance,
            d.demande_motif_refus,
            d.demande_matricule_decision,
            d.utilisateur_matricule,
            u.utilisateur_nom,
            u.utilisateur_prenom,
            u.utilisateur_service,
            u.utilisateur_fonction,
            u.utilisateur_corps,
            u.utilisateur_direction,
            s.service_nom,
            v.utilisateur_nom    AS validateur_nom,
            v.utilisateur_prenom AS validateur_prenom
        FROM DEMANDE d
        INNER JOIN UTILISATEUR u ON u.utilisateur_matricule = d.utilisateur_matricule
        LEFT JOIN UTILISATEUR v ON v.utilisateur_matricule = d.demande_matricule_decision
        LEFT JOIN SERVICE s ON s.service_id = u.utilisateur_service
        ORDER BY d.demande_date_creation DESC";

$stmt = $db->prepare($sql);
$stmt->execute();
$demandes = $stmt->fetchAll();

$result = [];

foreach ($demandes as $d) {
// ═══ Cast des IDs AVANT les requêtes ═══
    $d['demande_id']                = (int) $d['demande_id'];
    $d['demande_niveau_validation'] = (int) $d['demande_niveau_validation'];

    // ═══ Initialisation ═══
    $d['nb_jours']     = 0;
    $d['ventilation']  = [];
    $d['titre_annees'] = '';
    $d['validations']  = [];

    // ═══ Calcul nb_jours + ventilation (congé) ═══
    if ($d['demande_type'] === 'conge' && $d['demande_date_fin']) {

        $debut = new DateTime($d['demande_date_debut']);
        $fin   = new DateTime($d['demande_date_fin']);
        $d['nb_jours'] = $debut->diff($fin)->days + 1;

        // Si ACCEPTÉE → ventilation figée (DEMANDE_DETAIL)
        if ($d['demande_status'] === 'acceptee') {
            $sqlDetail = "SELECT solde_annuel_annee, jours_deduit
                          FROM DEMANDE_DETAIL
                          WHERE demande_id = :id
                          ORDER BY solde_annuel_annee ASC";
            $stmtDetail = $db->prepare($sqlDetail);
            $stmtDetail->execute([':id' => $d['demande_id']]);

            foreach ($stmtDetail->fetchAll() as $det) {
                $d['ventilation'][] = [
                    'annee' => (int) $det['solde_annuel_annee'],
                    'jours' => (int) $det['jours_deduit']
                ];
            }
        }
        // Si EN ATTENTE → ventilation simulée (FIFO)
        elseif ($d['demande_status'] === 'en_attente') {
            $sqlSolde = "SELECT solde_annuel_annee, solde_annuel_valeur
                         FROM SOLDE_ANNUEL
                         WHERE utilisateur_matricule = :matricule
                           AND solde_annuel_valeur > 0
                         ORDER BY solde_annuel_annee ASC";
            $stmtSolde = $db->prepare($sqlSolde);
            $stmtSolde->execute([':matricule' => $d['utilisateur_matricule']]);
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
        // Si REFUSÉE → pas de ventilation

        if (!empty($d['ventilation'])) {
            $d['titre_annees'] = implode(', ', array_column($d['ventilation'], 'annee'));
        }
    }

    // ═══ NOUVEAU : récupérer les validations ═══
    $stmtVal = $db->prepare(
        "SELECT
            dv.validation_matricule,
            dv.validation_role,
            dv.validation_decision,
            dv.validation_date,
            u.utilisateur_nom,
            u.utilisateur_prenom
         FROM DEMANDE_VALIDATION dv
         INNER JOIN UTILISATEUR u ON u.utilisateur_matricule = dv.validation_matricule
         WHERE dv.demande_id = :id
         ORDER BY dv.validation_date ASC"
    );
    $stmtVal->execute([':id' => $d['demande_id']]);
    $d['validations'] = $stmtVal->fetchAll();

    // ═══ Ajout au résultat ═══
    $result[] = $d;
   
}

echo json_encode([
    "success"  => true,
    "demandes" => $result
]);
<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$validator = requireAuth();
$db        = (new Database())->getConnection();

$data = json_decode(file_get_contents("php://input"), true);

$demandeId  = (int)($data['demande_id'] ?? 0);
$decision   = $data['decision'] ?? null;
$motifRefus = $data['motif_refus'] ?? null;

if (!$demandeId || !in_array($decision, ['acceptee', 'refusee'], true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Paramètres invalides"]);
    exit();
}

if ($decision === 'refusee' && empty(trim($motifRefus))) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Le motif de refus est obligatoire"]);
    exit();
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM DEMANDE WHERE demande_id = :id FOR UPDATE");
    $stmt->execute([':id' => $demandeId]);
    $demande = $stmt->fetch();

    if (!$demande) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Demande introuvable"]);
        exit();
    }

    if ($demande['demande_status'] !== 'en_attente') {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Cette demande a déjà été traitée"]);
        exit();
    }

    $stmt = $db->prepare("SELECT utilisateur_role FROM UTILISATEUR WHERE utilisateur_matricule = :matricule");
    $stmt->execute([':matricule' => $validator->matricule]);
    $role = $stmt->fetchColumn();

    $niveauActuel = (int) $demande['demande_niveau_validation'];

    $niveauAttendu = match($role) {
        'chef'  => 0,
        'sg'    => 1,
        'admin' => 2,
        default => -1
    };

    if ($niveauAttendu === -1 || $niveauActuel !== $niveauAttendu) {
        $db->rollBack();
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Vous n'êtes pas habilité à valider cette demande à ce niveau"
        ]);
        exit();
    }

    // ─── REFUS 
    if ($decision === 'refusee') {
        $db->prepare(
            "UPDATE DEMANDE
             SET demande_status              = 'refusee',
                 demande_matricule_decision  = :validateur,
                 demande_date_decision       = CURRENT_TIMESTAMP,
                 demande_motif_refus         = :motif
             WHERE demande_id = :id"
        )->execute([
            ':validateur' => $validator->matricule,
            ':motif'      => trim($motifRefus),
            ':id'         => $demandeId
        ]);

        $db->commit();
        echo json_encode(["success" => true, "message" => "Demande refusée"]);
        exit();
    }

    // ─── ACCEPTATION 
    $nouveauNiveau = $niveauActuel + 1;
    $devientAcceptee = ($nouveauNiveau === 3);

    if ($devientAcceptee && $demande['demande_type'] === 'conge') {
        $matricule = $demande['utilisateur_matricule'];

        $debut   = new DateTime($demande['demande_date_debut']);
        $fin     = new DateTime($demande['demande_date_fin']);
        $nbJours = $debut->diff($fin)->days + 1;

        $stmt = $db->prepare(
            "SELECT solde_annuel_annee, solde_annuel_valeur
             FROM SOLDE_ANNUEL
             WHERE utilisateur_matricule = :matricule
               AND solde_annuel_valeur > 0
             ORDER BY solde_annuel_annee ASC
             FOR UPDATE"
        );
        $stmt->execute([':matricule' => $matricule]);
        $soldes = $stmt->fetchAll();

        $totalDispo = 0;
        foreach ($soldes as $s) {
            $totalDispo += (int) $s['solde_annuel_valeur'];
        }

        if ($totalDispo < $nbJours) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Solde insuffisant pour valider cette demande",
                "detail"  => "Demandé : $nbJours j, disponible : $totalDispo j"
            ]);
            exit();
        }

        $reste = $nbJours;
        foreach ($soldes as $s) {
            if ($reste <= 0) break;

            $annee  = (int) $s['solde_annuel_annee'];
            $dispo  = (int) $s['solde_annuel_valeur'];
            $deduit = min($reste, $dispo);
            $reste -= $deduit;

            $db->prepare(
                "INSERT INTO DEMANDE_DETAIL (demande_id, solde_annuel_annee, jours_deduit)
                 VALUES (:id, :annee, :jours)"
            )->execute([
                ':id'    => $demandeId,
                ':annee' => $annee,
                ':jours' => $deduit
            ]);

            $db->prepare(
                "UPDATE SOLDE_ANNUEL
                 SET solde_annuel_valeur = solde_annuel_valeur - :deduit
                 WHERE utilisateur_matricule = :matricule
                   AND solde_annuel_annee = :annee"
            )->execute([
                ':deduit'    => $deduit,
                ':matricule' => $matricule,
                ':annee'     => $annee
            ]);
        }
    }

    $nouveauStatus = $devientAcceptee ? 'acceptee' : 'en_attente';

    $db->prepare(
        "UPDATE DEMANDE
         SET demande_niveau_validation   = :niveau,
             demande_status              = :status,
             demande_matricule_decision  = :validateur,
             demande_date_decision       = CURRENT_TIMESTAMP
         WHERE demande_id = :id"
    )->execute([
        ':niveau'     => $nouveauNiveau,
        ':status'     => $nouveauStatus,
        ':validateur' => $validator->matricule,
        ':id'         => $demandeId
    ]);

    $db->commit();
    echo json_encode([
        "success" => true,
        "message" => $devientAcceptee ? "Demande acceptée définitivement" : "Demande validée, transmise au niveau suivant"
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log("demande_valider.php : " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erreur serveur"]);
}
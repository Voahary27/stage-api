<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$admin = requireRole('admin');
$db = (new Database())->getConnection();

$data = json_decode(file_get_contents("php://input"), true);
$demandeId = (int)($data['demande_id'] ?? 0);

if (!$demandeId) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Demande ID manquant"]);
    exit();
}

try {
    $db->beginTransaction();

    // Récupérer la demande
    $stmt = $db->prepare(
        "SELECT * FROM DEMANDE WHERE demande_id = :id FOR UPDATE"
    );
    $stmt->execute([':id' => $demandeId]);
    $demande = $stmt->fetch();

    if (!$demande) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Demande introuvable"]);
        exit();
    }

    // Vérifier que le statut est 'acceptee'
    if ($demande['demande_status'] !== 'acceptee') {
        $db->rollBack();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Seules les demandes acceptées peuvent être annulées"
        ]);
        exit();
    }

    // Rendre le solde (reverse FIFO) — uniquement pour un congé
    if ($demande['demande_type'] === 'conge') {
        $matricule = $demande['utilisateur_matricule'];

        // Récupérer la ventilation
        $stmt = $db->prepare(
            "SELECT solde_annuel_annee, jours_deduit
             FROM DEMANDE_DETAIL
             WHERE demande_id = :id
             ORDER BY solde_annuel_annee ASC"
        );
        $stmt->execute([':id' => $demandeId]);
        $details = $stmt->fetchAll();

        // Rendre chaque année
        foreach ($details as $det) {
            $annee  = (int) $det['solde_annuel_annee'];
            $jours  = (int) $det['jours_deduit'];

            // Vérifier que la ligne existe dans SOLDE_ANNUEL
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM SOLDE_ANNUEL
                 WHERE utilisateur_matricule = :m AND solde_annuel_annee = :a"
            );
            $stmt->execute([':m' => $matricule, ':a' => $annee]);

            if ((int) $stmt->fetchColumn() > 0) {
                // Rendre le solde
                $db->prepare(
                    "UPDATE SOLDE_ANNUEL
                     SET solde_annuel_valeur = solde_annuel_valeur + :jours
                     WHERE utilisateur_matricule = :m AND solde_annuel_annee = :a"
                )->execute([':jours' => $jours, ':m' => $matricule, ':a' => $annee]);
            } else {
                // Recréer la ligne (cas rare : la ligne a été supprimée)
                $db->prepare(
                    "INSERT INTO SOLDE_ANNUEL (solde_annuel_annee, solde_annuel_valeur, utilisateur_matricule)
                     VALUES (:a, :jours, :m)"
                )->execute([':a' => $annee, ':jours' => $jours, ':m' => $matricule]);
            }
        }
    }

    // Supprimer les détails
    $db->prepare("DELETE FROM DEMANDE_DETAIL WHERE demande_id = :id")
       ->execute([':id' => $demandeId]);

    // Supprimer la demande
    $db->prepare("DELETE FROM DEMANDE WHERE demande_id = :id")
       ->execute([':id' => $demandeId]);

    $db->commit();

    echo json_encode([
        "success" => true,
        "message" => "Demande annulée et solde rendu"
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log("demande_annuler.php : " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erreur serveur"]);
}
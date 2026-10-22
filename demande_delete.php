<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$user = requireAuth();
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
     $stmt = $db->prepare(
        "SELECT demande_id, demande_status, utilisateur_matricule
         FROM DEMANDE
         WHERE demande_id = :id
         FOR UPDATE"
    );
    $stmt->execute([':id' => $demandeId]);
    $demande = $stmt->fetch();

     if (!$demande) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Demande introuvable"]);
        exit();
    }

    // verification
    if ($demande['utilisateur_matricule'] !== $user->matricule) {
        $db->rollBack();
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Vous ne pouvez supprimer que vos propres demandes"
        ]);
        exit();
    }

    // Vérifier que le statut est 'en_attente'
    if ($demande['demande_status'] !== 'en_attente') {
        $db->rollBack();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Seules les demandes en attente peuvent être supprimées"
        ]);
        exit();
    }

     // Supprimer les détails (par sécurité, même si normalement vide)
    $db->prepare("DELETE FROM DEMANDE_DETAIL WHERE demande_id = :id")
       ->execute([':id' => $demandeId]);

    // Supprimer la demande
    $db->prepare("DELETE FROM DEMANDE WHERE demande_id = :id")
       ->execute([':id' => $demandeId]);

    $db->commit();

    echo json_encode([
        "success" => true,
        "message" => "Demande supprimée"
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log("demande_delete.php : " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erreur serveur"]);
}

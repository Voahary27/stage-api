<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

// ═══════════════════════════════════════════════════════════════
// CRUD des services (admin uniquement)
// ───────────────────────────────────────────────────────────────
//  Actions :
//    • add    → ajouter un service
//    • update → modifier le nom
//    • delete → supprimer (bloqué si employés)
// ═══════════════════════════════════════════════════════════════

$admin = requireRole('admin');
$db = (new Database())->getConnection();

$data   = json_decode(file_get_contents("php://input"), true);
$action = $data['action'] ?? null;

if (!$action || !in_array($action, ['add', 'update', 'delete'], true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Action invalide"]);
    exit();
}

try {

    // ═══════════════════════════════════════════════════════════
    // ACTION : ADD
    // ═══════════════════════════════════════════════════════════
    if ($action === 'add') {
        $nom = trim($data['service_nom'] ?? '');

        if (!$nom) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Le nom est obligatoire"]);
            exit();
        }

        // Vérifier doublon
        $stmt = $db->prepare("SELECT COUNT(*) FROM SERVICE WHERE LOWER(service_nom) = LOWER(:nom)");
        $stmt->execute([':nom' => $nom]);

        if ((int) $stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "Ce service existe déjà"]);
            exit();
        }

        // Générer le prochain service_id
        $stmt = $db->query("SELECT COALESCE(MAX(service_id), 0) + 1 FROM SERVICE");
        $nextId = (int) $stmt->fetchColumn();

        $db->prepare("INSERT INTO SERVICE (service_id, service_nom) VALUES (:id, :nom)")
           ->execute([':id' => $nextId, ':nom' => $nom]);

        echo json_encode(["success" => true, "message" => "Service ajouté", "service_id" => $nextId]);
        exit();
    }

    // ═══════════════════════════════════════════════════════════
    // ACTION : UPDATE
    // ═══════════════════════════════════════════════════════════
    if ($action === 'update') {
        $id  = (int)($data['service_id'] ?? 0);
        $nom = trim($data['service_nom'] ?? '');

        if (!$id || !$nom) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Paramètres invalides"]);
            exit();
        }

        // Vérifier doublon (autre que lui-même)
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM SERVICE
             WHERE LOWER(service_nom) = LOWER(:nom) AND service_id != :id"
        );
        $stmt->execute([':nom' => $nom, ':id' => $id]);

        if ((int) $stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "Ce nom de service existe déjà"]);
            exit();
        }

        $stmt = $db->prepare("UPDATE SERVICE SET service_nom = :nom WHERE service_id = :id");
        $stmt->execute([':nom' => $nom, ':id' => $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Service introuvable"]);
            exit();
        }

        echo json_encode(["success" => true, "message" => "Service modifié"]);
        exit();
    }

    // ═══════════════════════════════════════════════════════════
    // ACTION : DELETE
    // ═══════════════════════════════════════════════════════════
    if ($action === 'delete') {
        $id = (int)($data['service_id'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "ID manquant"]);
            exit();
        }

        // ⚠️ Vérifier qu'il n'y a plus d'employés dans ce service
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM UTILISATEUR WHERE utilisateur_service = :id"
        );
        $stmt->execute([':id' => $id]);
        $nbEmployes = (int) $stmt->fetchColumn();

        if ($nbEmployes > 0) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "Impossible de supprimer : ce service contient encore $nbEmployes employé(s)"
            ]);
            exit();
        }

        $stmt = $db->prepare("DELETE FROM SERVICE WHERE service_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Service introuvable"]);
            exit();
        }

        echo json_encode(["success" => true, "message" => "Service supprimé"]);
        exit();
    }

} catch (Throwable $e) {
    http_response_code(500);
    error_log("services_crud.php : " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erreur serveur"]);
}
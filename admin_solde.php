<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$admin = requireRole('admin');
$db = (new Database())->getConnection();

$data = json_decode(file_get_contents("php://input"), true);

$action    = $data['action'] ?? null;
$matricule = $data['matricule'] ?? null;
$annee     = (int)($data['annee'] ?? 0);
$valeur    = (int)($data['valeur'] ?? 0);

if (!$action || !$matricule || !$annee) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Paramètres manquants"]);
    exit();
}

if (!in_array($action, ['add', 'update', 'delete'], true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Action invalide"]);
    exit();
}

try {
    // Vérifier que l'utilisateur existe
    $stmt = $db->prepare("SELECT COUNT(*) FROM UTILISATEUR WHERE utilisateur_matricule = :m");
    $stmt->execute([':m' => $matricule]);

    if ((int) $stmt->fetchColumn() === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Utilisateur introuvable"]);
        exit();
    }


if ($action === 'add') {
        if ($valeur < 0 || $valeur > 60) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Valeur invalide (0 à 60)"]);
            exit();
        }
$stmt = $db->prepare(
            "SELECT COUNT(*) FROM SOLDE_ANNUEL
             WHERE utilisateur_matricule = :m AND solde_annuel_annee = :a"
        );
        $stmt->execute([':m' => $matricule, ':a' => $annee]);

        if ((int) $stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "Cet utilisateur a déjà un solde pour l'année $annee"
            ]);
            exit();
        }

        $db->prepare(
            "INSERT INTO SOLDE_ANNUEL (solde_annuel_annee, solde_annuel_valeur, utilisateur_matricule)
             VALUES (:a, :v, :m)"
        )->execute([':a' => $annee, ':v' => $valeur, ':m' => $matricule]);

        echo json_encode(["success" => true, "message" => "Solde $annee ajouté"]);
        exit();
    }

    // update
    if ($action === 'update') {
        if ($valeur < 0 || $valeur > 60) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Valeur invalide (0 à 60)"]);
            exit();
        }

        $stmt = $db->prepare(
            "UPDATE SOLDE_ANNUEL SET solde_annuel_valeur = :v
             WHERE utilisateur_matricule = :m AND solde_annuel_annee = :a"
        );
        $stmt->execute([':v' => $valeur, ':m' => $matricule, ':a' => $annee]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Solde introuvable"]);
            exit();
        }

        echo json_encode(["success" => true, "message" => "Solde $annee modifié"]);
        exit();
    }

    // Delete
    if ($action === 'delete') {
        // Vérifier que l'année n'est pas utilisée dans DEMANDE_DETAIL
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM DEMANDE_DETAIL dd
             INNER JOIN DEMANDE d ON d.demande_id = dd.demande_id
             WHERE d.utilisateur_matricule = :m AND dd.solde_annuel_annee = :a"
        );
        $stmt->execute([':m' => $matricule, ':a' => $annee]);

        if ((int) $stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "Impossible de supprimer : cette année est utilisée dans des demandes validées"
            ]);
            exit();
        }

        $stmt = $db->prepare(
            "DELETE FROM SOLDE_ANNUEL
             WHERE utilisateur_matricule = :m AND solde_annuel_annee = :a"
        );
        $stmt->execute([':m' => $matricule, ':a' => $annee]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Solde introuvable"]);
            exit();
        }

        echo json_encode(["success" => true, "message" => "Solde $annee supprimé"]);
        exit();
    }

} catch (Throwable $e) {
    http_response_code(500);
    error_log("admin_solde.php : " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erreur serveur"]);
}
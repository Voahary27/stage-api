<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';


$admin = requireRole('admin');
$db = (new Database())->getConnection();

$action = $_GET['action'] ?? null;

// Récupérer le matricule (GET pour detail, POST body pour role/delete)
$matricule = $_GET['matricule'] ?? null;
$data = [];

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents("php://input"), true) ?: [];
    $matricule = $matricule ?: ($data['matricule'] ?? null);
}

if (!$action) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Action manquante"]);
    exit();
}

try {

    // ═══════════════════════════════════════════════════════════
    // ACTION : LIST
    // ═══════════════════════════════════════════════════════════
    if ($action === 'list') {
        $sql = "SELECT
                    u.utilisateur_matricule,
                    u.utilisateur_nom,
                    u.utilisateur_prenom,
                    u.utilisateur_role,
                    u.utilisateur_service,
                    s.service_nom,
                    (SELECT COUNT(*) FROM SOLDE_ANNUEL
                     WHERE utilisateur_matricule = u.utilisateur_matricule) AS nb_soldes
                FROM UTILISATEUR u
                LEFT JOIN SERVICE s ON s.service_id = u.utilisateur_service
                WHERE u.utilisateur_role != 'admin'
                ORDER BY u.utilisateur_nom ASC, u.utilisateur_prenom ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute();
        $utilisateurs = $stmt->fetchAll();

        $actifs   = [];
        $nouveaux = [];

        foreach ($utilisateurs as $u) {
            $u['nb_soldes'] = (int) $u['nb_soldes'];
            if ($u['nb_soldes'] > 0) {
                $actifs[] = $u;
            } else {
                $nouveaux[] = $u;
            }
        }

        echo json_encode([
            "success"  => true,
            "actifs"   => $actifs,
            "nouveaux" => $nouveaux,
            "total"    => count($actifs) + count($nouveaux)
        ]);
        exit();
    }

    // ═══════════════════════════════════════════════════════════
    // ACTION : DETAIL
    // ═══════════════════════════════════════════════════════════
    if ($action === 'detail') {
        if (!$matricule) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Matricule manquant"]);
            exit();
        }

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

        $stmt = $db->prepare($sql);
        $stmt->execute([':matricule' => $matricule]);
        $profil = $stmt->fetch();

        if (!$profil) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Utilisateur introuvable"]);
            exit();
        }

        $sql = "SELECT solde_annuel_annee, solde_annuel_valeur
                FROM SOLDE_ANNUEL
                WHERE utilisateur_matricule = :matricule
                ORDER BY solde_annuel_annee ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute([':matricule' => $matricule]);
        $soldes = $stmt->fetchAll();

        foreach ($soldes as &$s) {
            $s['solde_annuel_annee']  = (int) $s['solde_annuel_annee'];
            $s['solde_annuel_valeur'] = (int) $s['solde_annuel_valeur'];
        }
        unset($s);

        echo json_encode([
            "success" => true,
            "profil"  => $profil,
            "soldes"  => $soldes,
            "total"   => array_sum(array_column($soldes, 'solde_annuel_valeur'))
        ]);
        exit();
    }

    // ═══════════════════════════════════════════════════════════
    // ACTION : ROLE
    // ═══════════════════════════════════════════════════════════
    if ($action === 'role') {
        $nouveauRole = $data['role'] ?? null;

        if (!$matricule || !in_array($nouveauRole, ['employe', 'chef', 'sg'], true)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Paramètres invalides"]);
            exit();
        }

    if ($matricule === $admin->matricule) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Vous ne pouvez pas modifier votre propre rôle"
        ]);
        exit();
    }


        // Vérifier que l'utilisateur existe
        $stmt = $db->prepare(
            "SELECT utilisateur_role FROM UTILISATEUR WHERE utilisateur_matricule = :m"
        );
        $stmt->execute([':m' => $matricule]);
        $actuel = $stmt->fetch();

        if (!$actuel) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Utilisateur introuvable"]);
            exit();
        }

        

        $db->prepare(
            "UPDATE UTILISATEUR SET utilisateur_role = :role
             WHERE utilisateur_matricule = :m"
        )->execute([':role' => $nouveauRole, ':m' => $matricule]);

        echo json_encode([
            "success" => true,
            "message" => "Rôle mis à jour"
        ]);
        exit();
    }

    // ═══════════════════════════════════════════════════════════
    // ACTION : DELETE
    // ═══════════════════════════════════════════════════════════
    if ($action === 'delete') {
        if (!$matricule) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Matricule manquant"]);
            exit();
        }

        if ($matricule === $admin->matricule) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Vous ne pouvez pas supprimer votre propre compte"
            ]);
            exit();
        }

        $db->beginTransaction();

        // Vérifier l'utilisateur
        $stmt = $db->prepare(
            "SELECT utilisateur_role FROM UTILISATEUR WHERE utilisateur_matricule = :m"
        );
        $stmt->execute([':m' => $matricule]);
        $user = $stmt->fetch();

        if (!$user) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Utilisateur introuvable"]);
            exit();
        }

        if ($user['utilisateur_role'] === 'admin') {
            $db->rollBack();
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "message" => "Impossible de supprimer un administrateur"
            ]);
            exit();
        }

        // Vérifier qu'il n'a pas validé des demandes
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM DEMANDE WHERE demande_matricule_decision = :m"
        );
        $stmt->execute([':m' => $matricule]);

        if ((int) $stmt->fetchColumn() > 0) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "Impossible de supprimer : cet utilisateur a validé des demandes d'autres personnes"
            ]);
            exit();
        }

        // Supprimer les demandes
        $db->prepare(
            "DELETE FROM DEMANDE_DETAIL
             WHERE demande_id IN (
                 SELECT demande_id FROM DEMANDE
                 WHERE utilisateur_matricule = :m
             )"
        )->execute([':m' => $matricule]);

        $db->prepare("DELETE FROM DEMANDE WHERE utilisateur_matricule = :m")
           ->execute([':m' => $matricule]);

        // Supprimer les soldes
        $db->prepare("DELETE FROM SOLDE_ANNUEL WHERE utilisateur_matricule = :m")
           ->execute([':m' => $matricule]);

        // Supprimer l'utilisateur
        $db->prepare("DELETE FROM UTILISATEUR WHERE utilisateur_matricule = :m")
           ->execute([':m' => $matricule]);

        $db->commit();

        echo json_encode([
            "success" => true,
            "message" => "Utilisateur et toutes ses données supprimés"
        ]);
        exit();
    }

    // ═══════════════════════════════════════════════════════════
    // ACTION INCONNUE
    // ═══════════════════════════════════════════════════════════
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Action inconnue : $action"
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log("admin_user.php : " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "Erreur serveur"
    ]);
}
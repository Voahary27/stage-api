<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/jwt.php';

use Firebase\JWT\JWT;

$database = new Database();
$db = $database->getConnection();

$data = json_decode(file_get_contents("php://input"), true);

$matricule     = isset($data["matricule"]) ? trim($data["matricule"]) : null;
$passwordInput = isset($data["password"])  ? trim($data["password"])  : null;

if (empty($matricule) || empty($passwordInput)) {
    echo json_encode([
        "success" => false,
        "message" => "Veuillez remplir le matricule et le mot de passe"
    ]);
    exit();
}

try {
    $sql = "SELECT
                utilisateur_matricule AS matricule,
                utilisateur_mdp       AS mdp,
                utilisateur_role      AS role
            FROM UTILISATEUR
            WHERE utilisateur_matricule = :matricule
            LIMIT 1";

    $requete = $db->prepare($sql);
    $requete->execute([":matricule" => $matricule]);
    $user = $requete->fetch();

    if ($user) {
        $passwordDB = $user["mdp"];

        if (password_verify($passwordInput, $passwordDB) || $passwordInput === $passwordDB) {

            $payload = [
                'iat' => time(),
                'exp' => time() + JWT_EXPIRATION,
                'data' => [
                    'matricule' => $user['matricule'],
                    'role'      => $user['role']
                ]
            ];

            $token = JWT::encode($payload, JWT_SECRET, JWT_ALGO);

            echo json_encode([
                "success"   => true,
                "message"   => "Connexion réussie",
                "token"     => $token,
                "matricule" => $user["matricule"],
                "role"      => $user["role"]
            ]);

        } else {
            echo json_encode([
                "success" => false,
                "message" => "Mot de passe incorrect"
            ]);
        }
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Matricule non trouvé"
        ]);
    }

} catch (PDOException $e) {
    error_log("PDOException: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "Erreur serveur lors de la connexion"
    ]);
}
?>
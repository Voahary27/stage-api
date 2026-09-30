

<?php


// En-têtes CORS & JSON pour Angular
require_once __DIR__ . '/config/env.php';

$allowedOrigins = array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', '')));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif (env('APP_ENV') === 'development') {
    header("Access-Control-Allow-Origin: *");
}

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/jwt.php';


use Firebase\JWT\JWT;

//  connexion BD
$database = new Database();
$db = $database->getConnection();

// Décoder le fichier JSON envoyé par Angular
$data = json_decode(file_get_contents("php://input"), true);

$matricule = isset($data["matricule"]) ? trim($data["matricule"]) : null;
$passwordInput = isset($data["password"]) ? trim($data["password"]) : null;

// 1. Vérification des champs vides
if (empty($matricule) || empty($passwordInput)) {
    echo json_encode([
        "success" => false,
        "message" => "Veuillez remplir le matricule et le mot de passe"
    ]);
    exit();
}

try {
    // 2. Requête SQL unique avec UNION ALL pour chercher dans ADMINISTRATEUR et UTILISATEUR
    $sql = "
        SELECT admin_matricule AS matricule, admin_mdp AS mdp, 'administrateur' AS role 
        FROM ADMINISTRATEUR 
        WHERE admin_matricule = :matricule
        UNION ALL
        SELECT utilisateur_matricule AS matricule,utilisateur_mdp AS mdp, 'utilisateur' AS role 
        FROM UTILISATEUR 
        WHERE utilisateur_matricule = :matricule
        LIMIT 1
    ";

    $requete = $db->prepare($sql);
    $requete->execute([":matricule" => $matricule]);
    $user = $requete->fetch();

    // 3. Vérification de l'existence du matricule
    if ($user) {
        $passwordDB = $user["mdp"];

        
        if (password_verify($passwordInput, $passwordDB) || $passwordInput === $passwordDB) {
            
            // Génération du Payload puis JWT
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
                "success" => true,
                "message" => "Connexion réussie",
                "token"   => $token,
                "matricule" => $user["matricule"],
                "role" => $user["role"]  
            ]);
        } else {
            // Message si le compte existe mais le mot de passe est faux
            echo json_encode([
                "success" => false,
                "message" => "Mot de passe incorrect"
            ]);
        }
    } else {
        // Message si le matricule n'existe dans aucune des deux tables
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
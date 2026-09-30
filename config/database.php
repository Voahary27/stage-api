<?php
require_once __DIR__ . '/env.php';

class Database {
    private $host ;
    private $port  ;
    private $db_name;
    private $username ;        
    private $password  ;     
    public $conn;

     public function __construct() {
        $this->host     = env('DB_HOST');
        $this->port     = env('DB_PORT');
        $this->db_name  = env('DB_NAME');
        $this->username = env('DB_USER');
        $this->password = env('DB_PASSWORD');
    }

    public function getConnection(){
        $this->conn = null;

        try {
           $dsn = "pgsql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name; 
           $this->conn = new PDO($dsn, $this->username, $this->password);
           $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
           $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }catch(PDOException $exception) {
            echo json_encode(["message" => "Erreur de connexion a la BD : " . $exception->getMessage()]);
            exit();
        }

        return $this->conn;
    }
}
?>
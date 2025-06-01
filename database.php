<?php
class Database {
    private $host = 'localhost';
    private $db_name = 'ethio_djibouti_railway';
    private $username = 'root';
    private $password = '';
    private $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }
}

// Session configuration
session_start();
date_default_timezone_set('Africa/Addis_Ababa');

// Application constants
define('APP_NAME', 'Ethio-Djibouti Railway TTMS');
define('APP_VERSION', '1.0');
define('BASE_URL', 'http://localhost/ethio-djibouti-railway-ttms/');
?>

<?php
require_once 'config/database.php';

class Auth {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function login($username, $password) {
        $query = "SELECT id, username, email, password_hash, full_name, role, is_active 
                  FROM users WHERE username = :username AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email'];
                
                $this->logActivity($user['id'], 'login', 'users', $user['id']);
                return true;
            }
        }
        return false;
    }
    
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->logActivity($_SESSION['user_id'], 'logout', 'users', $_SESSION['user_id']);
        }
        session_destroy();
        return true;
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function hasRole($required_role) {
        if (!$this->isLoggedIn()) return false;
        
        $roles = ['operator' => 1, 'scheduler' => 2, 'administrator' => 3];
        $user_level = $roles[$_SESSION['role']] ?? 0;
        $required_level = $roles[$required_role] ?? 0;
        
        return $user_level >= $required_level;
    }
    
    public function createUser($username, $email, $password, $full_name, $role) {
        if (!$this->hasRole('administrator')) {
            throw new Exception('Insufficient permissions');
        }
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (username, email, password_hash, full_name, role) 
                  VALUES (:username, :email, :password_hash, :full_name, :role)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':role', $role);
        
        if ($stmt->execute()) {
            $user_id = $this->conn->lastInsertId();
            $this->logActivity($_SESSION['user_id'], 'create_user', 'users', $user_id);
            return $user_id;
        }
        return false;
    }
    
    private function logActivity($user_id, $action, $table_name, $record_id, $old_values = null, $new_values = null) {
        $query = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                  VALUES (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':table_name', $table_name);
        $stmt->bindParam(':record_id', $record_id);
        $stmt->bindParam(':old_values', json_encode($old_values));
        $stmt->bindParam(':new_values', json_encode($new_values));
        $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT']);
        
        $stmt->execute();
    }
}
?>

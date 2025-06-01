<?php
require_once 'config/enhanced_config.php';

class EnhancedAuth {
    private $db;
    private $maxLoginAttempts;
    private $lockoutDuration;
    
    public function __construct() {
        $this->db = EnhancedDatabase::getInstance()->getConnection();
        $this->maxLoginAttempts = Config::get('security.max_login_attempts');
        $this->lockoutDuration = Config::get('security.lockout_duration');
    }
    
    public function login($username, $password, $rememberMe = false) {
        try {
            // Check if account is locked
            if ($this->isAccountLocked($username)) {
                throw new Exception('Account is temporarily locked due to multiple failed login attempts');
            }
            
            // Get user data
            $query = "SELECT id, username, email, password_hash, full_name, role, department, 
                             failed_login_attempts, account_locked_until, is_active 
                      FROM users WHERE username = :username";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->rowCount() !== 1) {
                $this->recordFailedLogin($username);
                throw new Exception('Invalid username or password');
            }
            
            $user = $stmt->fetch();
            
            if (!$user['is_active']) {
                throw new Exception('Account is deactivated');
            }
            
            if (!password_verify($password, $user['password_hash'])) {
                $this->recordFailedLogin($username);
                throw new Exception('Invalid username or password');
            }
            
            // Successful login
            $this->resetFailedLoginAttempts($username);
            $this->updateLastLogin($user['id']);
            $this->createSession($user);
            
            if ($rememberMe) {
                $this->createRememberToken($user['id']);
            }
            
            $this->logActivity($user['id'], 'login', 'users', $user['id'], null, [
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            return [
                'success' => true,
                'user' => $user,
                'message' => 'Login successful'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function logout() {
        if ($this->isLoggedIn()) {
            $this->logActivity(
                SessionManager::get('user_id'), 
                'logout', 
                'users', 
                SessionManager::get('user_id')
            );
            
            $this->removeRememberToken();
        }
        
        SessionManager::destroy();
        
        return [
            'success' => true,
            'message' => 'Logged out successfully'
        ];
    }
    
    public function isLoggedIn() {
        return SessionManager::has('user_id') && SessionManager::has('username');
    }
    
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $query = "SELECT id, username, email, full_name, role, department, last_login 
                  FROM users WHERE id = :id AND is_active = 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', SessionManager::get('user_id'));
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    public function hasRole($requiredRole) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $roles = [
            'viewer' => 1,
            'operator' => 2,
            'scheduler' => 3,
            'administrator' => 4
        ];
        
        $userLevel = $roles[SessionManager::get('role')] ?? 0;
        $requiredLevel = $roles[$requiredRole] ?? 0;
        
        return $userLevel >= $requiredLevel;
    }
    
    public function hasPermission($permission) {
        $role = SessionManager::get('role');
        
        $permissions = [
            'viewer' => [
                'view_schedules', 'view_trains', 'view_reports'
            ],
            'operator' => [
                'view_schedules', 'view_trains', 'view_reports',
                'update_train_status', 'create_notifications'
            ],
            'scheduler' => [
                'view_schedules', 'view_trains', 'view_reports',
                'update_train_status', 'create_notifications',
                'create_schedules', 'edit_schedules', 'cancel_trains',
                'resolve_conflicts'
            ],
            'administrator' => [
                'view_schedules', 'view_trains', 'view_reports',
                'update_train_status', 'create_notifications',
                'create_schedules', 'edit_schedules', 'cancel_trains',
                'resolve_conflicts', 'delete_schedules', 'manage_users',
                'system_config', 'view_audit_logs'
            ]
        ];
        
        return in_array($permission, $permissions[$role] ?? []);
    }
    
    public function createUser($userData) {
        if (!$this->hasPermission('manage_users')) {
            throw new Exception('Insufficient permissions');
        }
        
        $this->validateUserData($userData);
        
        try {
            $this->db->beginTransaction();
            
            $passwordHash = password_hash($userData['password'], PASSWORD_DEFAULT);
            
            $query = "INSERT INTO users (username, email, password_hash, full_name, role, department, phone) 
                      VALUES (:username, :email, :password_hash, :full_name, :role, :department, :phone)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':username', $userData['username']);
            $stmt->bindParam(':email', $userData['email']);
            $stmt->bindParam(':password_hash', $passwordHash);
            $stmt->bindParam(':full_name', $userData['full_name']);
            $stmt->bindParam(':role', $userData['role']);
            $stmt->bindParam(':department', $userData['department']);
            $stmt->bindParam(':phone', $userData['phone']);
            
            $stmt->execute();
            $userId = $this->db->lastInsertId();
            
            $this->logActivity(
                SessionManager::get('user_id'),
                'create_user',
                'users',
                $userId,
                null,
                $userData
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'user_id' => $userId,
                'message' => 'User created successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function updateUser($userId, $userData) {
        if (!$this->hasPermission('manage_users') && SessionManager::get('user_id') != $userId) {
            throw new Exception('Insufficient permissions');
        }
        
        try {
            $this->db->beginTransaction();
            
            // Get current user data for audit
            $currentUser = $this->getUserById($userId);
            
            $fields = [];
            $params = [':id' => $userId];
            
            foreach ($userData as $field => $value) {
                if (in_array($field, ['email', 'full_name', 'role', 'department', 'phone', 'is_active'])) {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $value;
                }
            }
            
            if (isset($userData['password']) && !empty($userData['password'])) {
                $fields[] = "password_hash = :password_hash";
                $params[':password_hash'] = password_hash($userData['password'], PASSWORD_DEFAULT);
            }
            
            if (empty($fields)) {
                throw new Exception('No valid fields to update');
            }
            
            $query = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($query);
            
            if ($stmt->execute($params)) {
                $this->logActivity(
                    SessionManager::get('user_id'),
                    'update_user',
                    'users',
                    $userId,
                    $currentUser,
                    $userData
                );
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => 'User updated successfully'
                ];
            }
            
            throw new Exception('Failed to update user');
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function changePassword($currentPassword, $newPassword) {
        $userId = SessionManager::get('user_id');
        
        if (!$userId) {
            throw new Exception('User not logged in');
        }
        
        // Verify current password
        $query = "SELECT password_hash FROM users WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new Exception('Current password is incorrect');
        }
        
        // Validate new password
        if (strlen($newPassword) < Config::get('security.password_min_length')) {
            throw new Exception('New password must be at least ' . Config::get('security.password_min_length') . ' characters long');
        }
        
        // Update password
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $query = "UPDATE users SET password_hash = :password_hash WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':password_hash', $newPasswordHash);
        $stmt->bindParam(':id', $userId);
        
        if ($stmt->execute()) {
            $this->logActivity($userId, 'change_password', 'users', $userId);
            
            return [
                'success' => true,
                'message' => 'Password changed successfully'
            ];
        }
        
        throw new Exception('Failed to change password');
    }
    
    private function isAccountLocked($username) {
        $query = "SELECT account_locked_until FROM users WHERE username = :username";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            return false;
        }
        
        $user = $stmt->fetch();
        
        if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
            return true;
        }
        
        return false;
    }
    
    private function recordFailedLogin($username) {
        $query = "UPDATE users SET 
                  failed_login_attempts = failed_login_attempts + 1,
                  account_locked_until = CASE 
                    WHEN failed_login_attempts + 1 >= :max_attempts 
                    THEN DATE_ADD(NOW(), INTERVAL :lockout_duration SECOND)
                    ELSE account_locked_until
                  END
                  WHERE username = :username";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':max_attempts', $this->maxLoginAttempts);
        $stmt->bindParam(':lockout_duration', $this->lockoutDuration);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
    }
    
    private function resetFailedLoginAttempts($username) {
        $query = "UPDATE users SET failed_login_attempts = 0, account_locked_until = NULL WHERE username = :username";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
    }
    
    private function updateLastLogin($userId) {
        $query = "UPDATE users SET last_login = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->execute();
    }
    
    private function createSession($user) {
        SessionManager::regenerateId();
        SessionManager::set('user_id', $user['id']);
        SessionManager::set('username', $user['username']);
        SessionManager::set('full_name', $user['full_name']);
        SessionManager::set('role', $user['role']);
        SessionManager::set('email', $user['email']);
        SessionManager::set('department', $user['department']);
        SessionManager::set('login_time', time());
    }
    
    private function createRememberToken($userId) {
        $token = Utils::generateRandomString(64);
        $hashedToken = hash('sha256', $token);
        
        // Store hashed token in database (you'd need to create a remember_tokens table)
        // For now, we'll use a cookie
        setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', true, true); // 30 days
    }
    
    private function removeRememberToken() {
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }
    }
    
    private function validateUserData($userData) {
        $required = ['username', 'email', 'password', 'full_name', 'role'];
        
        foreach ($required as $field) {
            if (empty($userData[$field])) {
                throw new Exception("Field '$field' is required");
            }
        }
        
        if (!Utils::validateEmail($userData['email'])) {
            throw new Exception('Invalid email format');
        }
        
        if (strlen($userData['password']) < Config::get('security.password_min_length')) {
            throw new Exception('Password must be at least ' . Config::get('security.password_min_length') . ' characters long');
        }
        
        $validRoles = ['viewer', 'operator', 'scheduler', 'administrator'];
        if (!in_array($userData['role'], $validRoles)) {
            throw new Exception('Invalid role specified');
        }
        
        // Check for duplicate username/email
        $query = "SELECT id FROM users WHERE username = :username OR email = :email";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':username', $userData['username']);
        $stmt->bindParam(':email', $userData['email']);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            throw new Exception('Username or email already exists');
        }
    }
    
    private function getUserById($userId) {
        $query = "SELECT * FROM users WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    private function logActivity($userId, $action, $tableName, $recordId, $oldValues = null, $newValues = null) {
        $query = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, session_id) 
                  VALUES (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent, :session_id)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':table_name', $tableName);
        $stmt->bindParam(':record_id', $recordId);
        $stmt->bindParam(':old_values', json_encode($oldValues));
        $stmt->bindParam(':new_values', json_encode($newValues));
        $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->bindParam(':session_id', session_id());
        
        $stmt->execute();
    }
}
?>

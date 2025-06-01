<?php
// Enhanced Configuration for Ethio-Djibouti Railway TTMS

class EnhancedDatabase {
    private $host = 'localhost';
    private $db_name = 'ethio_djibouti_railway_enhanced';
    private $username = 'root';
    private $password = '';
    private $conn;
    private static $instance = null;

    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                )
            );
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }

    public function commit() {
        return $this->conn->commit();
    }

    public function rollback() {
        return $this->conn->rollback();
    }
}

// Enhanced Session Management
class SessionManager {
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', 1);
            ini_set('session.use_strict_mode', 1);
            session_start();
        }
    }

    public static function regenerateId() {
        session_regenerate_id(true);
    }

    public static function destroy() {
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }

    public static function set($key, $value) {
        $_SESSION[$key] = $value;
    }

    public static function get($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }

    public static function has($key) {
        return isset($_SESSION[$key]);
    }

    public static function remove($key) {
        unset($_SESSION[$key]);
    }
}

// Application Configuration
class Config {
    private static $config = [
        'app' => [
            'name' => 'Ethio-Djibouti Railway TTMS',
            'version' => '2.0',
            'timezone' => 'Africa/Addis_Ababa',
            'debug' => true,
            'maintenance_mode' => false
        ],
        'security' => [
            'password_min_length' => 8,
            'session_timeout' => 3600, // 1 hour
            'max_login_attempts' => 5,
            'lockout_duration' => 900, // 15 minutes
            'csrf_token_name' => 'csrf_token'
        ],
        'railway' => [
            'total_distance_km' => 756,
            'default_speed_limit' => 80,
            'minimum_headway_minutes' => 5,
            'buffer_time_minutes' => 3,
            'real_time_update_interval' => 30
        ],
        'ui' => [
            'items_per_page' => 25,
            'chart_refresh_interval' => 30000, // 30 seconds
            'notification_timeout' => 5000 // 5 seconds
        ]
    ];

    public static function get($key, $default = null) {
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }

    public static function set($key, $value) {
        $keys = explode('.', $key);
        $config = &self::$config;
        
        foreach ($keys as $k) {
            if (!isset($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }
        
        $config = $value;
    }
}

// Initialize
SessionManager::start();
date_default_timezone_set(Config::get('app.timezone'));

// CSRF Protection
class CSRFProtection {
    public static function generateToken() {
        if (!SessionManager::has('csrf_token')) {
            SessionManager::set('csrf_token', bin2hex(random_bytes(32)));
        }
        return SessionManager::get('csrf_token');
    }

    public static function validateToken($token) {
        return hash_equals(SessionManager::get('csrf_token', ''), $token);
    }

    public static function getTokenField() {
        $token = self::generateToken();
        return '<input type="hidden" name="' . Config::get('security.csrf_token_name') . '" value="' . htmlspecialchars($token) . '">';
    }
}

// Error Handler
class ErrorHandler {
    public static function register() {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        $error = [
            'type' => 'Error',
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'severity' => $severity
        ];
        
        self::logError($error);
        
        if (Config::get('app.debug')) {
            self::displayError($error);
        }
        
        return true;
    }

    public static function handleException($exception) {
        $error = [
            'type' => 'Exception',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];
        
        self::logError($error);
        
        if (Config::get('app.debug')) {
            self::displayError($error);
        }
    }

    public static function handleShutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            self::logError($error);
        }
    }

    private static function logError($error) {
        $logMessage = sprintf(
            "[%s] %s: %s in %s on line %d",
            date('Y-m-d H:i:s'),
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line']
        );
        
        error_log($logMessage, 3, 'logs/error.log');
    }

    private static function displayError($error) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        
        echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; margin: 10px; border: 1px solid #f5c6cb; border-radius: 4px;'>";
        echo "<strong>{$error['type']}:</strong> {$error['message']}<br>";
        echo "<small>File: {$error['file']} on line {$error['line']}</small>";
        echo "</div>";
    }
}

// Initialize error handling
ErrorHandler::register();

// Utility Functions
class Utils {
    public static function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }

    public static function formatTime($time) {
        return date('H:i', strtotime($time));
    }

    public static function formatDateTime($datetime) {
        return date('Y-m-d H:i:s', strtotime($datetime));
    }

    public static function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }

    public static function timeToMinutes($time) {
        $parts = explode(':', $time);
        return ($parts[0] * 60) + $parts[1];
    }

    public static function minutesToTime($minutes) {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }
}
?>

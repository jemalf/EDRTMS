<?php
require_once 'config/database.php';
require_once 'classes/Auth.php';

class TrainSchedule {
    private $conn;
    private $auth;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }
    
    public function createTimetable($name, $effective_date, $expiry_date) {
        if (!$this->auth->hasRole('scheduler')) {
            throw new Exception('Insufficient permissions');
        }
        
        $query = "INSERT INTO timetables (timetable_name, effective_date, expiry_date, created_by) 
                  VALUES (:name, :effective_date, :expiry_date, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':effective_date', $effective_date);
        $stmt->bindParam(':expiry_date', $expiry_date);
        $stmt->bindParam(':created_by', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }
    
    public function addTrainSchedule($timetable_id, $train_id, $route_id, $departure_time, $arrival_time, $operating_days = '1111111', $track_assignment = null, $is_temporary = false) {
        if (!$this->auth->hasRole('scheduler')) {
            throw new Exception('Insufficient permissions');
        }
        
        // Check for conflicts before adding
        $conflicts = $this->detectConflicts($train_id, $route_id, $departure_time, $arrival_time, $track_assignment);
        if (!empty($conflicts)) {
            throw new Exception('Schedule conflicts detected: ' . implode(', ', $conflicts));
        }
        
        $query = "INSERT INTO train_schedules (timetable_id, train_id, route_id, departure_time, arrival_time, operating_days, track_assignment, is_temporary) 
                  VALUES (:timetable_id, :train_id, :route_id, :departure_time, :arrival_time, :operating_days, :track_assignment, :is_temporary)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':timetable_id', $timetable_id);
        $stmt->bindParam(':train_id', $train_id);
        $stmt->bindParam(':route_id', $route_id);
        $stmt->bindParam(':departure_time', $departure_time);
        $stmt->bindParam(':arrival_time', $arrival_time);
        $stmt->bindParam(':operating_days', $operating_days);
        $stmt->bindParam(':track_assignment', $track_assignment);
        $stmt->bindParam(':is_temporary', $is_temporary);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }
    
    public function updateSchedule($schedule_id, $data) {
        if (!$this->auth->hasRole('scheduler')) {
            throw new Exception('Insufficient permissions');
        }
        
        // Get current data for audit log
        $current = $this->getScheduleById($schedule_id);
        
        $fields = [];
        $params = [':id' => $schedule_id];
        
        foreach ($data as $field => $value) {
            if (in_array($field, ['departure_time', 'arrival_time', 'operating_days', 'track_assignment'])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }
        
        if (empty($fields)) {
            throw new Exception('No valid fields to update');
        }
        
        $query = "UPDATE train_schedules SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        
        if ($stmt->execute($params)) {
            $this->logActivity('update_schedule', 'train_schedules', $schedule_id, $current, $data);
            return true;
        }
        return false;
    }
    
    public function deleteSchedule($schedule_id) {
        if (!$this->auth->hasRole('administrator')) {
            throw new Exception('Insufficient permissions');
        }
        
        $current = $this->getScheduleById($schedule_id);
        
        $query = "DELETE FROM train_schedules WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $schedule_id);
        
        if ($stmt->execute()) {
            $this->logActivity('delete_schedule', 'train_schedules', $schedule_id, $current);
            return true;
        }
        return false;
    }
    
    public function getScheduleById($schedule_id) {
        $query = "SELECT ts.*, t.train_number, t.train_name, tt.type_name, tt.color_code,
                         r.route_name, s1.station_name as origin, s2.station_name as destination
                  FROM train_schedules ts
                  JOIN trains t ON ts.train_id = t.id
                  JOIN train_types tt ON t.train_type_id = tt.id
                  JOIN routes r ON ts.route_id = r.id
                  JOIN stations s1 ON r.origin_station_id = s1.id
                  JOIN stations s2 ON r.destination_station_id = s2.id
                  WHERE ts.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $schedule_id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getActiveSchedules($timetable_id = null) {
        $where_clause = $timetable_id ? "WHERE ts.timetable_id = :timetable_id" : "";
        
        $query = "SELECT ts.*, t.train_number, t.train_name, tt.type_name, tt.color_code,
                         r.route_name, s1.station_name as origin, s2.station_name as destination,
                         tp.status as current_status, tp.delay_minutes
                  FROM train_schedules ts
                  JOIN trains t ON ts.train_id = t.id
                  JOIN train_types tt ON t.train_type_id = tt.id
                  JOIN routes r ON ts.route_id = r.id
                  JOIN stations s1 ON r.origin_station_id = s1.id
                  JOIN stations s2 ON r.destination_station_id = s2.id
                  LEFT JOIN train_positions tp ON ts.id = tp.schedule_id
                  $where_clause
                  ORDER BY ts.departure_time";
        
        $stmt = $this->conn->prepare($query);
        if ($timetable_id) {
            $stmt->bindParam(':timetable_id', $timetable_id);
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function detectConflicts($train_id, $route_id, $departure_time, $arrival_time, $track_assignment) {
        $conflicts = [];
        
        // Check for track conflicts
        if ($track_assignment) {
            $query = "SELECT ts.id, t.train_number 
                      FROM train_schedules ts
                      JOIN trains t ON ts.train_id = t.id
                      WHERE ts.track_assignment = :track_assignment
                      AND ((ts.departure_time <= :arrival_time AND ts.arrival_time >= :departure_time))";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':track_assignment', $track_assignment);
            $stmt->bindParam(':departure_time', $departure_time);
            $stmt->bindParam(':arrival_time', $arrival_time);
            $stmt->execute();
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $conflicts[] = "Track conflict with train " . $row['train_number'];
            }
        }
        
        return $conflicts;
    }
    
    public function updateTrainPosition($train_id, $schedule_id, $current_station_id, $status, $delay_minutes = 0) {
        if (!$this->auth->hasRole('operator')) {
            throw new Exception('Insufficient permissions');
        }
        
        $query = "INSERT INTO train_positions (train_id, schedule_id, current_station_id, status, delay_minutes)
                  VALUES (:train_id, :schedule_id, :current_station_id, :status, :delay_minutes)
                  ON DUPLICATE KEY UPDATE
                  current_station_id = VALUES(current_station_id),
                  status = VALUES(status),
                  delay_minutes = VALUES(delay_minutes),
                  last_updated = CURRENT_TIMESTAMP";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':train_id', $train_id);
        $stmt->bindParam(':schedule_id', $schedule_id);
        $stmt->bindParam(':current_station_id', $current_station_id);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':delay_minutes', $delay_minutes);
        
        return $stmt->execute();
    }
    
    private function logActivity($action, $table_name, $record_id, $old_values = null, $new_values = null) {
        $query = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                  VALUES (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
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

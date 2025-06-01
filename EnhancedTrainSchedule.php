<?php
require_once 'config/enhanced_config.php';
require_once 'classes/EnhancedAuth.php';

class EnhancedTrainSchedule {
    private $db;
    private $auth;
    
    public function __construct() {
        $this->db = EnhancedDatabase::getInstance()->getConnection();
        $this->auth = new EnhancedAuth();
    }
    
    public function createTimetable($data) {
        if (!$this->auth->hasPermission('create_schedules')) {
            throw new Exception('Insufficient permissions to create timetables');
        }
        
        $this->validateTimetableData($data);
        
        try {
            $this->db->beginTransaction();
            
            $query = "INSERT INTO timetables (timetable_name, version, effective_date, expiry_date, notes, created_by) 
                      VALUES (:name, :version, :effective_date, :expiry_date, :notes, :created_by)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':name', $data['name']);
            $stmt->bindParam(':version', $data['version']);
            $stmt->bindParam(':effective_date', $data['effective_date']);
            $stmt->bindParam(':expiry_date', $data['expiry_date']);
            $stmt->bindParam(':notes', $data['notes']);
            $stmt->bindParam(':created_by', SessionManager::get('user_id'));
            
            $stmt->execute();
            $timetableId = $this->db->lastInsertId();
            
            $this->logActivity('create_timetable', 'timetables', $timetableId, null, $data);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'timetable_id' => $timetableId,
                'message' => 'Timetable created successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function addTrainSchedule($data) {
        if (!$this->auth->hasPermission('create_schedules')) {
            throw new Exception('Insufficient permissions to add train schedules');
        }
        
        $this->validateScheduleData($data);
        
        try {
            $this->db->beginTransaction();
            
            // Check for conflicts
            $conflicts = $this->detectScheduleConflicts($data);
            if (!empty($conflicts)) {
                throw new Exception('Schedule conflicts detected: ' . implode(', ', $conflicts));
            }
            
            $query = "INSERT INTO train_schedules 
                      (timetable_id, train_id, route_id, schedule_date, departure_time, arrival_time, 
                       operating_days, track_assignment, platform_assignment, crew_assignment, 
                       priority_level, is_temporary) 
                      VALUES 
                      (:timetable_id, :train_id, :route_id, :schedule_date, :departure_time, :arrival_time,
                       :operating_days, :track_assignment, :platform_assignment, :crew_assignment,
                       :priority_level, :is_temporary)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':timetable_id', $data['timetable_id']);
            $stmt->bindParam(':train_id', $data['train_id']);
            $stmt->bindParam(':route_id', $data['route_id']);
            $stmt->bindParam(':schedule_date', $data['schedule_date']);
            $stmt->bindParam(':departure_time', $data['departure_time']);
            $stmt->bindParam(':arrival_time', $data['arrival_time']);
            $stmt->bindParam(':operating_days', $data['operating_days']);
            $stmt->bindParam(':track_assignment', $data['track_assignment']);
            $stmt->bindParam(':platform_assignment', $data['platform_assignment']);
            $stmt->bindParam(':crew_assignment', $data['crew_assignment']);
            $stmt->bindParam(':priority_level', $data['priority_level']);
            $stmt->bindParam(':is_temporary', $data['is_temporary']);
            
            $stmt->execute();
            $scheduleId = $this->db->lastInsertId();
            
            // Add schedule stops if provided
            if (isset($data['stops']) && is_array($data['stops'])) {
                $this->addScheduleStops($scheduleId, $data['stops']);
            }
            
            $this->logActivity('add_schedule', 'train_schedules', $scheduleId, null, $data);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'schedule_id' => $scheduleId,
                'message' => 'Train schedule added successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function updateSchedule($scheduleId, $data) {
        if (!$this->auth->hasPermission('edit_schedules')) {
            throw new Exception('Insufficient permissions to update schedules');
        }
        
        try {
            $this->db->beginTransaction();
            
            // Get current schedule for audit
            $currentSchedule = $this->getScheduleById($scheduleId);
            if (!$currentSchedule) {
                throw new Exception('Schedule not found');
            }
            
            // Check for conflicts with updated data
            $conflicts = $this->detectScheduleConflicts($data, $scheduleId);
            if (!empty($conflicts)) {
                throw new Exception('Schedule conflicts detected: ' . implode(', ', $conflicts));
            }
            
            $fields = [];
            $params = [':id' => $scheduleId];
            
            $allowedFields = [
                'departure_time', 'arrival_time', 'operating_days', 'track_assignment',
                'platform_assignment', 'crew_assignment', 'priority_level'
            ];
            
            foreach ($data as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $value;
                }
            }
            
            if (empty($fields)) {
                throw new Exception('No valid fields to update');
            }
            
            $fields[] = "updated_at = NOW()";
            
            $query = "UPDATE train_schedules SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($query);
            
            if ($stmt->execute($params)) {
                $this->logActivity('update_schedule', 'train_schedules', $scheduleId, $currentSchedule, $data);
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => 'Schedule updated successfully'
                ];
            }
            
            throw new Exception('Failed to update schedule');
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function cancelTrain($scheduleId, $reason) {
        if (!$this->auth->hasPermission('cancel_trains')) {
            throw new Exception('Insufficient permissions to cancel trains');
        }
        
        try {
            $this->db->beginTransaction();
            
            $currentSchedule = $this->getScheduleById($scheduleId);
            if (!$currentSchedule) {
                throw new Exception('Schedule not found');
            }
            
            $query = "UPDATE train_schedules SET 
                      is_cancelled = 1, 
                      cancellation_reason = :reason, 
                      cancelled_by = :cancelled_by, 
                      cancelled_at = NOW(),
                      updated_at = NOW()
                      WHERE id = :id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':reason', $reason);
            $stmt->bindParam(':cancelled_by', SessionManager::get('user_id'));
            $stmt->bindParam(':id', $scheduleId);
            
            if ($stmt->execute()) {
                $this->logActivity('cancel_train', 'train_schedules', $scheduleId, $currentSchedule, [
                    'reason' => $reason,
                    'cancelled_by' => SessionManager::get('user_id')
                ]);
                
                // Create notification for relevant users
                $this->createCancellationNotification($scheduleId, $reason);
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => 'Train cancelled successfully'
                ];
            }
            
            throw new Exception('Failed to cancel train');
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function deleteSchedule($scheduleId) {
        if (!$this->auth->hasPermission('delete_schedules')) {
            throw new Exception('Insufficient permissions to delete schedules');
        }
        
        try {
            $this->db->beginTransaction();
            
            $currentSchedule = $this->getScheduleById($scheduleId);
            if (!$currentSchedule) {
                throw new Exception('Schedule not found');
            }
            
            // Delete related stops first
            $query = "DELETE FROM schedule_stops WHERE schedule_id = :schedule_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':schedule_id', $scheduleId);
            $stmt->execute();
            
            // Delete train positions
            $query = "DELETE FROM train_positions WHERE schedule_id = :schedule_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':schedule_id', $scheduleId);
            $stmt->execute();
            
            // Delete the schedule
            $query = "DELETE FROM train_schedules WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $scheduleId);
            
            if ($stmt->execute()) {
                $this->logActivity('delete_schedule', 'train_schedules', $scheduleId, $currentSchedule);
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => 'Schedule deleted successfully'
                ];
            }
            
            throw new Exception('Failed to delete schedule');
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function updateTrainPosition($data) {
        if (!$this->auth->hasPermission('update_train_status')) {
            throw new Exception('Insufficient permissions to update train positions');
        }
        
        try {
            $query = "INSERT INTO train_positions 
                      (train_id, schedule_id, current_station_id, next_station_id, latitude, longitude, 
                       speed_kmh, heading_degrees, status, delay_minutes, estimated_arrival, 
                       distance_to_next_station, fuel_level_percent)
                      VALUES 
                      (:train_id, :schedule_id, :current_station_id, :next_station_id, :latitude, :longitude,
                       :speed_kmh, :heading_degrees, :status, :delay_minutes, :estimated_arrival,
                       :distance_to_next_station, :fuel_level_percent)
                      ON DUPLICATE KEY UPDATE
                      current_station_id = VALUES(current_station_id),
                      next_station_id = VALUES(next_station_id),
                      latitude = VALUES(latitude),
                      longitude = VALUES(longitude),
                      speed_kmh = VALUES(speed_kmh),
                      heading_degrees = VALUES(heading_degrees),
                      status = VALUES(status),
                      delay_minutes = VALUES(delay_minutes),
                      estimated_arrival = VALUES(estimated_arrival),
                      distance_to_next_station = VALUES(distance_to_next_station),
                      fuel_level_percent = VALUES(fuel_level_percent),
                      last_updated = CURRENT_TIMESTAMP";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':train_id', $data['train_id']);
            $stmt->bindParam(':schedule_id', $data['schedule_id']);
            $stmt->bindParam(':current_station_id', $data['current_station_id']);
            $stmt->bindParam(':next_station_id', $data['next_station_id']);
            $stmt->bindParam(':latitude', $data['latitude']);
            $stmt->bindParam(':longitude', $data['longitude']);
            $stmt->bindParam(':speed_kmh', $data['speed_kmh']);
            $stmt->bindParam(':heading_degrees', $data['heading_degrees']);
            $stmt->bindParam(':status', $data['status']);
            $stmt->bindParam(':delay_minutes', $data['delay_minutes']);
            $stmt->bindParam(':estimated_arrival', $data['estimated_arrival']);
            $stmt->bindParam(':distance_to_next_station', $data['distance_to_next_station']);
            $stmt->bindParam(':fuel_level_percent', $data['fuel_level_percent']);
            
            if ($stmt->execute()) {
                $this->logActivity('update_train_position', 'train_positions', $data['train_id'], null, $data);
                
                // Check for delay alerts
                if ($data['delay_minutes'] > Config::get('railway.max_delay_threshold_minutes', 30)) {
                    $this->createDelayAlert($data['train_id'], $data['delay_minutes']);
                }
                
                return [
                    'success' => true,
                    'message' => 'Train position updated successfully'
                ];
            }
            
            throw new Exception('Failed to update train position');
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    public function getActiveSchedules($filters = []) {
        $whereConditions = ["ts.is_cancelled = 0"];
        $params = [];
        
        if (isset($filters['timetable_id'])) {
            $whereConditions[] = "ts.timetable_id = :timetable_id";
            $params[':timetable_id'] = $filters['timetable_id'];
        }
        
        if (isset($filters['date'])) {
            $whereConditions[] = "ts.schedule_date = :schedule_date";
            $params[':schedule_date'] = $filters['date'];
        }
        
        if (isset($filters['train_type'])) {
            $whereConditions[] = "tt.type_code = :train_type";
            $params[':train_type'] = $filters['train_type'];
        }
        
        if (isset($filters['status'])) {
            $whereConditions[] = "tp.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $query = "SELECT ts.*, t.train_number, t.train_name, tt.type_name, tt.color_code, tt.line_style,
                         r.route_name, r.route_code, s1.station_name as origin, s2.station_name as destination,
                         s1.station_code as origin_code, s2.station_code as destination_code,
                         tp.status as current_status, tp.delay_minutes, tp.latitude, tp.longitude,
                         tp.speed_kmh, tp.estimated_arrival, tp.last_updated as position_updated,
                         tmt.timetable_name, tmt.version as timetable_version
                  FROM train_schedules ts
                  JOIN trains t ON ts.train_id = t.id
                  JOIN train_types tt ON t.train_type_id = tt.id
                  JOIN routes r ON ts.route_id = r.id
                  JOIN stations s1 ON r.origin_station_id = s1.id
                  JOIN stations s2 ON r.destination_station_id = s2.id
                  JOIN timetables tmt ON ts.timetable_id = tmt.id
                  LEFT JOIN train_positions tp ON ts.id = tp.schedule_id
                  WHERE $whereClause
                  ORDER BY ts.departure_time, ts.priority_level";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    public function getScheduleById($scheduleId) {
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
                  WHERE ts.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $scheduleId);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    public function detectScheduleConflicts($scheduleData, $excludeScheduleId = null) {
        $conflicts = [];
        
        // Track conflict detection
        $query = "SELECT ts.id, t.train_number, ts.departure_time, ts.arrival_time, ts.track_assignment
                  FROM train_schedules ts
                  JOIN trains t ON ts.train_id = t.id
                  WHERE ts.track_assignment = :track_assignment
                  AND ts.schedule_date = :schedule_date
                  AND ts.is_cancelled = 0
                  AND ((ts.departure_time <= :arrival_time AND ts.arrival_time >= :departure_time))";
        
        if ($excludeScheduleId) {
            $query .= " AND ts.id != :exclude_id";
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':track_assignment', $scheduleData['track_assignment']);
        $stmt->bindParam(':schedule_date', $scheduleData['schedule_date']);
        $stmt->bindParam(':departure_time', $scheduleData['departure_time']);
        $stmt->bindParam(':arrival_time', $scheduleData['arrival_time']);
        
        if ($excludeScheduleId) {
            $stmt->bindParam(':exclude_id', $excludeScheduleId);
        }
        
        $stmt->execute();
        
        while ($row = $stmt->fetch()) {
            $conflicts[] = "Track conflict with train " . $row['train_number'] . " on track " . $row['track_assignment'];
            
            // Log the conflict
            $this->logConflict([
                'type' => 'track_overlap',
                'schedule_id_1' => $scheduleData['schedule_id'] ?? null,
                'schedule_id_2' => $row['id'],
                'conflict_time_start' => max($scheduleData['departure_time'], $row['departure_time']),
                'conflict_time_end' => min($scheduleData['arrival_time'], $row['arrival_time']),
                'track_id' => $scheduleData['track_assignment'],
                'description' => "Track overlap detected between trains"
            ]);
        }
        
        return $conflicts;
    }
    
    private function addScheduleStops($scheduleId, $stops) {
        foreach ($stops as $stop) {
            $query = "INSERT INTO schedule_stops 
                      (schedule_id, station_id, arrival_time, departure_time, platform, track, 
                       stop_duration, stop_type, sequence_order, distance_from_origin, 
                       is_mandatory, passenger_operations, freight_operations)
                      VALUES 
                      (:schedule_id, :station_id, :arrival_time, :departure_time, :platform, :track,
                       :stop_duration, :stop_type, :sequence_order, :distance_from_origin,
                       :is_mandatory, :passenger_operations, :freight_operations)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':schedule_id', $scheduleId);
            $stmt->bindParam(':station_id', $stop['station_id']);
            $stmt->bindParam(':arrival_time', $stop['arrival_time']);
            $stmt->bindParam(':departure_time', $stop['departure_time']);
            $stmt->bindParam(':platform', $stop['platform']);
            $stmt->bindParam(':track', $stop['track']);
            $stmt->bindParam(':stop_duration', $stop['stop_duration']);
            $stmt->bindParam(':stop_type', $stop['stop_type']);
            $stmt->bindParam(':sequence_order', $stop['sequence_order']);
            $stmt->bindParam(':distance_from_origin', $stop['distance_from_origin']);
            $stmt->bindParam(':is_mandatory', $stop['is_mandatory']);
            $stmt->bindParam(':passenger_operations', $stop['passenger_operations']);
            $stmt->bindParam(':freight_operations', $stop['freight_operations']);
            
            $stmt->execute();
        }
    }
    
    private function logConflict($conflictData) {
        $query = "INSERT INTO conflicts 
                  (conflict_type, severity, schedule_id_1, schedule_id_2, conflict_time_start, 
                   conflict_time_end, track_id, platform_id, description, detected_by_system)
                  VALUES 
                  (:conflict_type, :severity, :schedule_id_1, :schedule_id_2, :conflict_time_start,
                   :conflict_time_end, :track_id, :platform_id, :description, :detected_by_system)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':conflict_type', $conflictData['type']);
        $stmt->bindParam(':severity', $conflictData['severity'] ?? 'medium');
        $stmt->bindParam(':schedule_id_1', $conflictData['schedule_id_1']);
        $stmt->bindParam(':schedule_id_2', $conflictData['schedule_id_2']);
        $stmt->bindParam(':conflict_time_start', $conflictData['conflict_time_start']);
        $stmt->bindParam(':conflict_time_end', $conflictData['conflict_time_end']);
        $stmt->bindParam(':track_id', $conflictData['track_id']);
        $stmt->bindParam(':platform_id', $conflictData['platform_id'] ?? null);
        $stmt->bindParam(':description', $conflictData['description']);
        $stmt->bindParam(':detected_by_system', true);
        
        $stmt->execute();
    }
    
    private function createCancellationNotification($scheduleId, $reason) {
        $schedule = $this->getScheduleById($scheduleId);
        
        $message = "Train {$schedule['train_number']} scheduled for {$schedule['departure_time']} has been cancelled. Reason: {$reason}";
        
        // Notify all operators and schedulers
        $query = "SELECT id FROM users WHERE role IN ('operator', 'scheduler', 'administrator') AND is_active = 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        while ($user = $stmt->fetch()) {
            $this->createNotification($user['id'], 'Train Cancellation', $message, 'warning', 'high');
        }
    }
    
    private function createDelayAlert($trainId, $delayMinutes) {
        $query = "SELECT t.train_number, ts.departure_time 
                  FROM trains t 
                  JOIN train_schedules ts ON t.id = ts.train_id 
                  WHERE t.id = :train_id 
                  ORDER BY ts.departure_time DESC 
                  LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':train_id', $trainId);
        $stmt->execute();
        
        $train = $stmt->fetch();
        
        if ($train) {
            $message = "Train {$train['train_number']} is delayed by {$delayMinutes} minutes";
            
            // Notify operators and schedulers
            $query = "SELECT id FROM users WHERE role IN ('operator', 'scheduler', 'administrator') AND is_active = 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            while ($user = $stmt->fetch()) {
                $this->createNotification($user['id'], 'Train Delay Alert', $message, 'warning', 'normal');
            }
        }
    }
    
    private function createNotification($userId, $title, $message, $type = 'info', $priority = 'normal') {
        $query = "INSERT INTO notifications (user_id, title, message, type, priority) 
                  VALUES (:user_id, :title, :message, :type, :priority)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':priority', $priority);
        
        $stmt->execute();
    }
    
    private function validateTimetableData($data) {
        $required = ['name', 'version', 'effective_date', 'expiry_date'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field '$field' is required");
            }
        }
        
        if (strtotime($data['effective_date']) >= strtotime($data['expiry_date'])) {
            throw new Exception('Effective date must be before expiry date');
        }
    }
    
    private function validateScheduleData($data) {
        $required = ['timetable_id', 'train_id', 'route_id', 'departure_time', 'arrival_time'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field '$field' is required");
            }
        }
        
        if (strtotime($data['departure_time']) >= strtotime($data['arrival_time'])) {
            throw new Exception('Departure time must be before arrival time');
        }
    }
    
    private function logActivity($action, $tableName, $recordId, $oldValues = null, $newValues = null) {
        $query = "INSERT INTO audit_log (user_id, action, module, table_name, record_id, old_values, new_values, ip_address, user_agent, session_id) 
                  VALUES (:user_id, :action, :module, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent, :session_id)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', SessionManager::get('user_id'));
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':module', 'train_schedule');
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

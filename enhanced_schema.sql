-- Enhanced Ethio-Djibouti Railway Train Timetable Management System Database Schema

-- Users and Authentication
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('administrator', 'scheduler', 'operator', 'viewer') NOT NULL,
    department VARCHAR(50),
    phone VARCHAR(20),
    last_login TIMESTAMP NULL,
    failed_login_attempts INT DEFAULT 0,
    account_locked_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

-- Stations with enhanced details
CREATE TABLE stations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    station_code VARCHAR(10) UNIQUE NOT NULL,
    station_name VARCHAR(100) NOT NULL,
    station_name_local VARCHAR(100),
    country VARCHAR(50),
    region VARCHAR(50),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    elevation INT,
    distance_from_origin DECIMAL(8,2),
    platforms INT DEFAULT 1,
    tracks INT DEFAULT 2,
    facilities JSON,
    operational_hours JSON,
    station_type ENUM('terminal', 'junction', 'intermediate', 'depot') DEFAULT 'intermediate',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Train Types with operational parameters
CREATE TABLE train_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type_name VARCHAR(50) NOT NULL,
    type_code VARCHAR(10) UNIQUE NOT NULL,
    description TEXT,
    color_code VARCHAR(7) DEFAULT '#000000',
    line_style ENUM('solid', 'dashed', 'dotted') DEFAULT 'solid',
    priority_level INT DEFAULT 1,
    max_speed INT,
    acceleration_rate DECIMAL(5,2),
    deceleration_rate DECIMAL(5,2),
    minimum_headway INT DEFAULT 5, -- minutes
    buffer_time INT DEFAULT 2, -- minutes
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Enhanced Trains table
CREATE TABLE trains (
    id INT PRIMARY KEY AUTO_INCREMENT,
    train_number VARCHAR(20) UNIQUE NOT NULL,
    train_name VARCHAR(100),
    train_type_id INT,
    capacity_passengers INT,
    capacity_freight DECIMAL(10,2),
    length_meters DECIMAL(6,2),
    weight_tons DECIMAL(8,2),
    max_speed INT,
    current_location_station_id INT,
    maintenance_due_date DATE,
    status ENUM('active', 'maintenance', 'retired', 'out_of_service') DEFAULT 'active',
    gps_device_id VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (train_type_id) REFERENCES train_types(id),
    FOREIGN KEY (current_location_station_id) REFERENCES stations(id)
);

-- Routes with detailed path information
CREATE TABLE routes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    route_code VARCHAR(20) UNIQUE NOT NULL,
    route_name VARCHAR(100) NOT NULL,
    origin_station_id INT,
    destination_station_id INT,
    total_distance DECIMAL(8,2),
    estimated_duration TIME,
    route_type ENUM('passenger', 'freight', 'mixed') DEFAULT 'mixed',
    difficulty_level ENUM('easy', 'moderate', 'difficult') DEFAULT 'moderate',
    operational_status ENUM('active', 'maintenance', 'closed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (origin_station_id) REFERENCES stations(id),
    FOREIGN KEY (destination_station_id) REFERENCES stations(id)
);

-- Route segments for detailed path tracking
CREATE TABLE route_segments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    route_id INT,
    from_station_id INT,
    to_station_id INT,
    segment_order INT,
    distance_km DECIMAL(6,2),
    estimated_time_minutes INT,
    track_type ENUM('single', 'double', 'electrified') DEFAULT 'single',
    gradient_percent DECIMAL(4,2),
    speed_limit INT,
    FOREIGN KEY (route_id) REFERENCES routes(id),
    FOREIGN KEY (from_station_id) REFERENCES stations(id),
    FOREIGN KEY (to_station_id) REFERENCES stations(id)
);

-- Enhanced Timetables
CREATE TABLE timetables (
    id INT PRIMARY KEY AUTO_INCREMENT,
    timetable_name VARCHAR(100) NOT NULL,
    version VARCHAR(20) NOT NULL,
    effective_date DATE,
    expiry_date DATE,
    status ENUM('draft', 'review', 'approved', 'active', 'archived') DEFAULT 'draft',
    created_by INT,
    reviewed_by INT,
    approved_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Enhanced Train Schedules
CREATE TABLE train_schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    timetable_id INT,
    train_id INT,
    route_id INT,
    schedule_date DATE,
    departure_time TIME,
    arrival_time TIME,
    operating_days VARCHAR(7) DEFAULT '1111111', -- MTWTFSS
    track_assignment VARCHAR(10),
    platform_assignment VARCHAR(10),
    crew_assignment VARCHAR(100),
    priority_level INT DEFAULT 5,
    is_temporary BOOLEAN DEFAULT FALSE,
    is_cancelled BOOLEAN DEFAULT FALSE,
    cancellation_reason TEXT,
    cancelled_by INT,
    cancelled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (timetable_id) REFERENCES timetables(id),
    FOREIGN KEY (train_id) REFERENCES trains(id),
    FOREIGN KEY (route_id) REFERENCES routes(id),
    FOREIGN KEY (cancelled_by) REFERENCES users(id)
);

-- Detailed Schedule Stops
CREATE TABLE schedule_stops (
    id INT PRIMARY KEY AUTO_INCREMENT,
    schedule_id INT,
    station_id INT,
    arrival_time TIME,
    departure_time TIME,
    platform VARCHAR(10),
    track VARCHAR(10),
    stop_duration INT, -- in minutes
    stop_type ENUM('scheduled', 'technical', 'operational') DEFAULT 'scheduled',
    sequence_order INT,
    distance_from_origin DECIMAL(8,2),
    is_mandatory BOOLEAN DEFAULT TRUE,
    passenger_operations BOOLEAN DEFAULT TRUE,
    freight_operations BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (schedule_id) REFERENCES train_schedules(id),
    FOREIGN KEY (station_id) REFERENCES stations(id)
);

-- Real-time Train Positions with GPS tracking
CREATE TABLE train_positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    train_id INT,
    schedule_id INT,
    current_station_id INT,
    next_station_id INT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    speed_kmh DECIMAL(5,2),
    heading_degrees INT,
    status ENUM('on_time', 'delayed', 'early', 'stopped', 'cancelled', 'emergency') DEFAULT 'on_time',
    delay_minutes INT DEFAULT 0,
    estimated_arrival TIME,
    distance_to_next_station DECIMAL(6,2),
    fuel_level_percent DECIMAL(5,2),
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (train_id) REFERENCES trains(id),
    FOREIGN KEY (schedule_id) REFERENCES train_schedules(id),
    FOREIGN KEY (current_station_id) REFERENCES stations(id),
    FOREIGN KEY (next_station_id) REFERENCES stations(id)
);

-- Enhanced Conflicts Detection
CREATE TABLE conflicts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    conflict_type ENUM('track_overlap', 'platform_overlap', 'timing_conflict', 'crew_conflict', 'maintenance_window') NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    schedule_id_1 INT,
    schedule_id_2 INT,
    conflict_time_start TIME,
    conflict_time_end TIME,
    station_id INT,
    track_id VARCHAR(10),
    platform_id VARCHAR(10),
    description TEXT,
    status ENUM('detected', 'acknowledged', 'resolving', 'resolved', 'ignored') DEFAULT 'detected',
    resolution_method ENUM('manual', 'automatic', 'rescheduled') NULL,
    resolution_notes TEXT,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    detected_by_system BOOLEAN DEFAULT TRUE,
    acknowledged_by INT,
    resolved_by INT,
    FOREIGN KEY (schedule_id_1) REFERENCES train_schedules(id),
    FOREIGN KEY (schedule_id_2) REFERENCES train_schedules(id),
    FOREIGN KEY (station_id) REFERENCES stations(id),
    FOREIGN KEY (acknowledged_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);

-- System Configuration
CREATE TABLE system_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT,
    description TEXT,
    data_type ENUM('string', 'integer', 'float', 'boolean', 'json') DEFAULT 'string',
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- Enhanced Audit Log
CREATE TABLE audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100),
    module VARCHAR(50),
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    session_id VARCHAR(100),
    severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Notifications System
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    type ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    is_read BOOLEAN DEFAULT FALSE,
    action_url VARCHAR(500),
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Reports and Analytics
CREATE TABLE reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_name VARCHAR(100) NOT NULL,
    report_type ENUM('schedule_performance', 'conflict_analysis', 'delay_report', 'utilization', 'custom') NOT NULL,
    parameters JSON,
    generated_by INT,
    file_path VARCHAR(500),
    status ENUM('generating', 'completed', 'failed') DEFAULT 'generating',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id)
);

-- Insert enhanced sample data
INSERT INTO train_types (type_name, type_code, description, color_code, line_style, priority_level, max_speed, minimum_headway, buffer_time) VALUES
('Express Passenger', 'EXP', 'High-speed passenger service', '#2563eb', 'solid', 1, 120, 10, 3),
('Local Passenger', 'PASS', 'Regular passenger train service', '#3b82f6', 'solid', 2, 80, 8, 2),
('Freight Heavy', 'FRGT', 'Heavy freight cargo transport', '#dc2626', 'dashed', 4, 60, 15, 5),
('Freight Light', 'FRTL', 'Light freight cargo transport', '#ef4444', 'dashed', 3, 70, 12, 4),
('Diesel Locomotive', 'DIES', 'Diesel powered locomotive', '#16a34a', 'solid', 3, 100, 10, 3),
('Rescue Locomotive', 'RESC', 'Emergency rescue locomotive', '#ea580c', 'dotted', 1, 80, 5, 2),
('Maintenance Railcar', 'MAINT', 'Track maintenance vehicle', '#7c3aed', 'dotted', 5, 40, 20, 10),
('Inspection Railcar', 'INSP', 'Track inspection vehicle', '#8b5cf6', 'dotted', 2, 60, 15, 5);

INSERT INTO stations (station_code, station_name, station_name_local, country, region, latitude, longitude, elevation, distance_from_origin, platforms, tracks, station_type, facilities, operational_hours) VALUES
('ADD', 'Addis Ababa Central', 'አዲስ አበባ ማዕከላዊ', 'Ethiopia', 'Addis Ababa', 9.0054, 38.7636, 2355, 0.00, 6, 8, 'terminal', '{"parking": true, "restaurant": true, "waiting_room": true, "cargo": true}', '{"open": "05:00", "close": "23:00"}'),
('SEB', 'Sebeta', 'ሰበታ', 'Ethiopia', 'Oromia', 8.9167, 38.6167, 2356, 25.50, 3, 4, 'intermediate', '{"parking": true, "waiting_room": true}', '{"open": "06:00", "close": "22:00"}'),
('MOJ', 'Mojo', 'ሞጆ', 'Ethiopia', 'Oromia', 8.5833, 39.1167, 1788, 73.20, 2, 3, 'intermediate', '{"parking": false, "waiting_room": true}', '{"open": "06:00", "close": "22:00"}'),
('ADA', 'Adama (Nazret)', 'አዳማ', 'Ethiopia', 'Oromia', 8.5400, 39.2675, 1712, 99.40, 4, 5, 'junction', '{"parking": true, "restaurant": true, "waiting_room": true, "cargo": true}', '{"open": "05:30", "close": "22:30"}'),
('AWA', 'Awash', 'አዋሽ', 'Ethiopia', 'Afar', 8.9833, 40.1667, 750, 210.80, 3, 4, 'junction', '{"parking": true, "waiting_room": true, "cargo": true}', '{"open": "06:00", "close": "22:00"}'),
('SEM', 'Semera', 'ሰመራ', 'Ethiopia', 'Afar', 11.7833, 41.0167, 300, 389.60, 2, 3, 'intermediate', '{"parking": true, "waiting_room": true}', '{"open": "06:00", "close": "21:00"}'),
('GAL', 'Galafi Border', 'ጋላፊ', 'Ethiopia', 'Afar', 11.7000, 42.6000, 400, 656.70, 2, 2, 'intermediate', '{"customs": true, "immigration": true}', '{"open": "24/7", "close": "24/7"}'),
('ALI', 'Ali Sabieh', 'علي صبيح', 'Djibouti', 'Ali Sabieh', 11.1556, 42.7125, 760, 705.30, 3, 4, 'junction', '{"parking": true, "restaurant": true, "waiting_room": true}', '{"open": "06:00", "close": "22:00"}'),
('DJI', 'Djibouti Port', 'ميناء جيبوتي', 'Djibouti', 'Djibouti', 11.5951, 43.1481, 15, 756.00, 4, 6, 'terminal', '{"parking": true, "restaurant": true, "waiting_room": true, "cargo": true, "port": true}', '{"open": "24/7", "close": "24/7"}');

INSERT INTO users (username, email, password_hash, full_name, role, department, phone) VALUES
('admin', 'admin@edr.et', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'administrator', 'IT Department', '+251911123456'),
('scheduler1', 'scheduler@edr.et', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Senior Train Scheduler', 'scheduler', 'Operations', '+251911234567'),
('operator1', 'operator@edr.et', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Control Room Operator', 'operator', 'Operations', '+251911345678'),
('viewer1', 'viewer@edr.et', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Operations Viewer', 'viewer', 'Management', '+251911456789');

INSERT INTO system_config (config_key, config_value, description, data_type) VALUES
('minimum_headway_minutes', '5', 'Minimum time between trains on same track', 'integer'),
('default_buffer_time_minutes', '3', 'Default buffer time for scheduling', 'integer'),
('max_delay_threshold_minutes', '30', 'Maximum acceptable delay before alert', 'integer'),
('auto_conflict_detection', 'true', 'Enable automatic conflict detection', 'boolean'),
('real_time_update_interval', '30', 'Real-time update interval in seconds', 'integer'),
('backup_retention_days', '90', 'Number of days to retain backup data', 'integer');

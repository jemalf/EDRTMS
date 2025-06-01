-- Ethio-Djibouti Railway Train Timetable Management System Database Schema

-- Users and Authentication
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('administrator', 'scheduler', 'operator') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

-- Stations
CREATE TABLE stations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    station_code VARCHAR(10) UNIQUE NOT NULL,
    station_name VARCHAR(100) NOT NULL,
    location VARCHAR(100),
    distance_from_origin DECIMAL(8,2),
    platforms INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Train Types
CREATE TABLE train_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type_name VARCHAR(50) NOT NULL,
    type_code VARCHAR(10) UNIQUE NOT NULL,
    description TEXT,
    color_code VARCHAR(7) DEFAULT '#000000',
    priority_level INT DEFAULT 1
);

-- Trains
CREATE TABLE trains (
    id INT PRIMARY KEY AUTO_INCREMENT,
    train_number VARCHAR(20) UNIQUE NOT NULL,
    train_name VARCHAR(100),
    train_type_id INT,
    capacity INT,
    status ENUM('active', 'maintenance', 'retired') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (train_type_id) REFERENCES train_types(id)
);

-- Routes
CREATE TABLE routes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    route_name VARCHAR(100) NOT NULL,
    origin_station_id INT,
    destination_station_id INT,
    total_distance DECIMAL(8,2),
    estimated_duration TIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (origin_station_id) REFERENCES stations(id),
    FOREIGN KEY (destination_station_id) REFERENCES stations(id)
);

-- Route Stations (intermediate stops)
CREATE TABLE route_stations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    route_id INT,
    station_id INT,
    sequence_order INT,
    distance_from_origin DECIMAL(8,2),
    platform_number VARCHAR(10),
    FOREIGN KEY (route_id) REFERENCES routes(id),
    FOREIGN KEY (station_id) REFERENCES stations(id)
);

-- Timetables
CREATE TABLE timetables (
    id INT PRIMARY KEY AUTO_INCREMENT,
    timetable_name VARCHAR(100) NOT NULL,
    effective_date DATE,
    expiry_date DATE,
    status ENUM('draft', 'approved', 'active', 'archived') DEFAULT 'draft',
    created_by INT,
    approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Train Schedules
CREATE TABLE train_schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    timetable_id INT,
    train_id INT,
    route_id INT,
    departure_time TIME,
    arrival_time TIME,
    operating_days VARCHAR(7) DEFAULT '1111111', -- MTWTFSS
    track_assignment VARCHAR(10),
    is_temporary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (timetable_id) REFERENCES timetables(id),
    FOREIGN KEY (train_id) REFERENCES trains(id),
    FOREIGN KEY (route_id) REFERENCES routes(id)
);

-- Schedule Stops
CREATE TABLE schedule_stops (
    id INT PRIMARY KEY AUTO_INCREMENT,
    schedule_id INT,
    station_id INT,
    arrival_time TIME,
    departure_time TIME,
    platform VARCHAR(10),
    stop_duration INT, -- in minutes
    sequence_order INT,
    FOREIGN KEY (schedule_id) REFERENCES train_schedules(id),
    FOREIGN KEY (station_id) REFERENCES stations(id)
);

-- Real-time Train Positions
CREATE TABLE train_positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    train_id INT,
    schedule_id INT,
    current_station_id INT,
    status ENUM('on_time', 'delayed', 'early', 'stopped', 'cancelled'),
    delay_minutes INT DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (train_id) REFERENCES trains(id),
    FOREIGN KEY (schedule_id) REFERENCES train_schedules(id),
    FOREIGN KEY (current_station_id) REFERENCES stations(id)
);

-- Conflicts
CREATE TABLE conflicts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    conflict_type ENUM('track_overlap', 'platform_overlap', 'timing_conflict'),
    schedule_id_1 INT,
    schedule_id_2 INT,
    conflict_time TIME,
    station_id INT,
    status ENUM('detected', 'resolved', 'ignored') DEFAULT 'detected',
    resolution_notes TEXT,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    resolved_by INT,
    FOREIGN KEY (schedule_id_1) REFERENCES train_schedules(id),
    FOREIGN KEY (schedule_id_2) REFERENCES train_schedules(id),
    FOREIGN KEY (station_id) REFERENCES stations(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);

-- Audit Log
CREATE TABLE audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100),
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Insert sample data
INSERT INTO train_types (type_name, type_code, description, color_code, priority_level) VALUES
('Passenger', 'PASS', 'Passenger train service', '#2563eb', 1),
('Freight', 'FRGT', 'Freight cargo transport', '#dc2626', 2),
('Diesel Locomotive', 'DIES', 'Diesel powered locomotive', '#16a34a', 3),
('Rescue Locomotive', 'RESC', 'Emergency rescue locomotive', '#ea580c', 1),
('Railcar', 'RAIL', 'Light rail car service', '#7c3aed', 2);

INSERT INTO stations (station_code, station_name, location, distance_from_origin, platforms) VALUES
('ADD', 'Addis Ababa', 'Ethiopia', 0.00, 4),
('SEB', 'Sebeta', 'Ethiopia', 25.50, 2),
('MOJ', 'Mojo', 'Ethiopia', 73.20, 2),
('ADA', 'Adama', 'Ethiopia', 99.40, 3),
('AWA', 'Awash', 'Ethiopia', 210.80, 2),
('SEM', 'Semera', 'Ethiopia', 389.60, 2),
('GAL', 'Galafi', 'Ethiopia-Djibouti Border', 656.70, 1),
('ALI', 'Ali Sabieh', 'Djibouti', 705.30, 2),
('DJI', 'Djibouti Port', 'Djibouti', 756.00, 3);

INSERT INTO users (username, email, password_hash, full_name, role) VALUES
('admin', 'admin@edr.et', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'administrator'),
('scheduler1', 'scheduler@edr.et', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Train Scheduler', 'scheduler'),
('operator1', 'operator@edr.et', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Train Operator', 'operator');

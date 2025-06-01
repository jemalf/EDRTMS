<?php
require_once 'config/database.php';
require_once 'classes/Auth.php';
require_once 'classes/TrainSchedule.php';

$auth = new Auth();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        $trainSchedule = new TrainSchedule();
        
        switch ($action) {
            case 'update_train_position':
                if (!$auth->isLoggedIn()) {
                    throw new Exception('Not authenticated');
                }
                
                $result = $trainSchedule->updateTrainPosition(
                    $_POST['train_id'],
                    $_POST['schedule_id'],
                    $_POST['current_station_id'],
                    $_POST['status'],
                    $_POST['delay_minutes'] ?? 0
                );
                echo json_encode(['success' => $result, 'message' => 'Position updated']);
                break;
                
            case 'get_schedules':
                if (!$auth->isLoggedIn()) {
                    throw new Exception('Not authenticated');
                }
                
                $schedules = $trainSchedule->getActiveSchedules();
                echo json_encode(['success' => true, 'schedules' => $schedules]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle login
if ($_POST['action'] ?? '' === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($auth->login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}

// Handle logout
if ($_GET['action'] ?? '' === 'logout') {
    $auth->logout();
    header('Location: index.php');
    exit;
}

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    include 'views/login.php';
    exit;
}

// Get current schedules
$trainSchedule = new TrainSchedule();
$schedules = $trainSchedule->getActiveSchedules();

// Get system statistics
$database = new Database();
$db = $database->getConnection();

$stats = [];
try {
    $query = "SELECT COUNT(*) as total_trains FROM trains WHERE status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['total_trains'] = $stmt->fetchColumn();

    $query = "SELECT COUNT(*) as active_schedules FROM train_schedules ts 
              JOIN timetables t ON ts.timetable_id = t.id 
              WHERE t.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['active_schedules'] = $stmt->fetchColumn();

    $query = "SELECT COUNT(*) as delayed_trains FROM train_positions tp 
              JOIN train_schedules ts ON tp.schedule_id = ts.id 
              WHERE tp.delay_minutes > 0";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['delayed_trains'] = $stmt->fetchColumn();

    $query = "SELECT COUNT(*) as active_conflicts FROM conflicts WHERE status = 'detected'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['active_conflicts'] = $stmt->fetchColumn();
} catch (Exception $e) {
    // Set default values if queries fail
    $stats = [
        'total_trains' => 0,
        'active_schedules' => 0,
        'delayed_trains' => 0,
        'active_conflicts' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Enhanced Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .train-diagram {
            background: linear-gradient(to right, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            position: relative;
            overflow: hidden;
        }
        
        .train-path {
            stroke-width: 3;
            fill: none;
            cursor: pointer;
            transition: stroke-width 0.2s;
        }
        
        .train-path:hover {
            stroke-width: 5;
        }
        
        .train-path.delayed {
            stroke-dasharray: 10,5;
            animation: dash 1s linear infinite;
        }
        
        @keyframes dash {
            to { stroke-dashoffset: -15; }
        }
        
        .station-line {
            stroke: #6c757d;
            stroke-width: 1;
            stroke-dasharray: 5,5;
        }
        
        .time-grid {
            stroke: #e9ecef;
            stroke-width: 1;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        
        .status-on-time { background-color: #10b981; }
        .status-delayed { background-color: #ef4444; }
        .status-early { background-color: #3b82f6; }
        .status-stopped { background-color: #f59e0b; }
        .status-cancelled { background-color: #6b7280; }
        
        .tab-content.hidden { display: none; }
        .tab-btn.active {
            border-color: #3b82f6;
            color: #3b82f6;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation -->
    <nav class="bg-blue-900 text-white shadow-lg sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <i class="fas fa-train text-2xl"></i>
                    <div>
                        <h1 class="text-xl font-bold"><?php echo APP_NAME; ?></h1>
                        <p class="text-xs text-blue-200">Version <?php echo APP_VERSION; ?></p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <!-- Real-time clock -->
                    <div class="text-center">
                        <div id="currentTime" class="text-lg font-mono"></div>
                        <div class="text-xs text-blue-200">Addis Ababa Time</div>
                    </div>
                    
                    <!-- User info -->
                    <div class="flex items-center space-x-3">
                        <div class="text-right">
                            <div class="text-sm font-medium"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div class="text-xs text-blue-200"><?php echo ucfirst($_SESSION['role']); ?></div>
                        </div>
                        <a href="?action=logout" class="bg-red-600 hover:bg-red-700 px-3 py-1 rounded text-sm">
                            <i class="fas fa-sign-out-alt mr-1"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        <!-- Sidebar -->
        <div class="bg-white w-64 min-h-screen shadow-lg">
            <div class="p-4">
                <!-- System Status -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">System Status</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-green-50 p-3 rounded-lg">
                            <div class="text-2xl font-bold text-green-600"><?php echo $stats['total_trains']; ?></div>
                            <div class="text-xs text-green-700">Active Trains</div>
                        </div>
                        <div class="bg-blue-50 p-3 rounded-lg">
                            <div class="text-2xl font-bold text-blue-600"><?php echo $stats['active_schedules']; ?></div>
                            <div class="text-xs text-blue-700">Active Schedules</div>
                        </div>
                        <div class="bg-yellow-50 p-3 rounded-lg">
                            <div class="text-2xl font-bold text-yellow-600"><?php echo $stats['delayed_trains']; ?></div>
                            <div class="text-xs text-yellow-700">Delayed Trains</div>
                        </div>
                        <div class="bg-red-50 p-3 rounded-lg">
                            <div class="text-2xl font-bold text-red-600"><?php echo $stats['active_conflicts']; ?></div>
                            <div class="text-xs text-red-700">Active Conflicts</div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">Quick Actions</h3>
                    <div class="space-y-2">
                        <?php if ($auth->hasRole('scheduler')): ?>
                        <button class="w-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">
                            <i class="fas fa-plus mr-2"></i>Add Schedule
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($auth->hasRole('operator')): ?>
                        <button class="w-full bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded text-sm">
                            <i class="fas fa-map-marker-alt mr-2"></i>Update Position
                        </button>
                        <?php endif; ?>
                        
                        <button class="w-full bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm">
                            <i class="fas fa-chart-bar mr-2"></i>View Reports
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 p-6">
            <!-- Control Panel -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800">Train Diagram Control Panel</h2>
                    <div class="flex space-x-2">
                        <button id="zoomIn" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded">
                            <i class="fas fa-search-plus mr-1"></i>Enlarge
                        </button>
                        <button id="zoomOut" class="bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1 rounded">
                            <i class="fas fa-search-minus mr-1"></i>Reduce
                        </button>
                        <button id="resetZoom" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded">
                            <i class="fas fa-undo mr-1"></i>Restore
                        </button>
                        <button id="autoRefresh" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded">
                            <i class="fas fa-sync mr-1"></i>Auto Refresh
                        </button>
                    </div>
                </div>
                
                <!-- Navigation Controls -->
                <div class="flex justify-center space-x-2 mb-4">
                    <button id="panUp" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <div class="flex space-x-2">
                        <button id="panLeft" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <button id="panCenter" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded">
                            <i class="fas fa-crosshairs"></i>
                        </button>
                        <button id="panRight" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                    <button id="panDown" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bg-white rounded-lg shadow-md mb-6">
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex space-x-8 px-6">
                        <button class="tab-btn active py-4 px-1 border-b-2 border-blue-500 font-medium text-sm text-blue-600" data-tab="diagram">
                            <i class="fas fa-chart-line mr-2"></i>Train Diagram
                        </button>
                        <button class="tab-btn py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700" data-tab="analytics">
                            <i class="fas fa-chart-bar mr-2"></i>Analytics
                        </button>
                        <button class="tab-btn py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700" data-tab="conflicts">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Conflicts
                        </button>
                    </nav>
                </div>

                <!-- Tab Content -->
                <div class="p-6">
                    <!-- Train Diagram Tab -->
                    <div id="diagramTab" class="tab-content">
                        <div id="diagramContainer" class="train-diagram overflow-auto" style="height: 600px;">
                            <svg id="trainDiagram" width="1400" height="900">
                                <g id="timeGrid"></g>
                                <g id="stationLines"></g>
                                <g id="trainPaths"></g>
                                <g id="labels"></g>
                            </svg>
                        </div>
                    </div>

                    <!-- Analytics Tab -->
                    <div id="analyticsTab" class="tab-content hidden">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div class="bg-gray-50 p-4 rounded">
                                <h4 class="font-semibold text-gray-800 mb-4">Performance Overview</h4>
                                <div class="space-y-3">
                                    <div class="flex justify-between">
                                        <span>On-time Performance:</span>
                                        <span class="font-semibold text-green-600">87%</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>Average Delay:</span>
                                        <span class="font-semibold text-yellow-600">12 minutes</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>Cancelled Trains:</span>
                                        <span class="font-semibold text-red-600">2</span>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 p-4 rounded">
                                <h4 class="font-semibold text-gray-800 mb-4">Route Utilization</h4>
                                <div class="space-y-3">
                                    <div class="flex justify-between">
                                        <span>ADD-DJI:</span>
                                        <span class="font-semibold">85%</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>ADD-ADA:</span>
                                        <span class="font-semibold">92%</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>AWA-DJI:</span>
                                        <span class="font-semibold">78%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Conflicts Tab -->
                    <div id="conflictsTab" class="tab-content hidden">
                        <div class="mb-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Active Conflicts</h4>
                            <div class="bg-yellow-50 border border-yellow-200 rounded p-4">
                                <div class="flex items-center">
                                    <i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>
                                    <span class="text-yellow-800"><?php echo $stats['active_conflicts']; ?> conflicts detected requiring attention</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full table-auto">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left">Type</th>
                                        <th class="px-4 py-2 text-left">Severity</th>
                                        <th class="px-4 py-2 text-left">Description</th>
                                        <th class="px-4 py-2 text-left">Status</th>
                                        <th class="px-4 py-2 text-left">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b">
                                        <td class="px-4 py-2">Track Overlap</td>
                                        <td class="px-4 py-2"><span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs">High</span></td>
                                        <td class="px-4 py-2">Sample conflict for demonstration</td>
                                        <td class="px-4 py-2"><span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs">Detected</span></td>
                                        <td class="px-4 py-2">
                                            <button class="bg-blue-500 text-white px-2 py-1 rounded text-xs">Resolve</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Train Status Panel -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Real-Time Train Status</h3>
                    <div class="flex space-x-2">
                        <button id="refreshStatus" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                            <i class="fas fa-sync mr-1"></i>Refresh
                        </button>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Status</th>
                                <th class="px-4 py-2 text-left">Train Number</th>
                                <th class="px-4 py-2 text-left">Type</th>
                                <th class="px-4 py-2 text-left">Route</th>
                                <th class="px-4 py-2 text-left">Departure</th>
                                <th class="px-4 py-2 text-left">Arrival</th>
                                <th class="px-4 py-2 text-left">Delay</th>
                                <th class="px-4 py-2 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="trainStatusTable">
                            <?php foreach ($schedules as $schedule): ?>
                            <tr class="border-b hover:bg-gray-50" data-schedule-id="<?php echo $schedule['id']; ?>">
                                <td class="px-4 py-2">
                                    <span class="status-indicator status-<?php echo $schedule['current_status'] ?? 'on_time'; ?>"></span>
                                </td>
                                <td class="px-4 py-2 font-medium"><?php echo htmlspecialchars($schedule['train_number']); ?></td>
                                <td class="px-4 py-2">
                                    <span class="inline-block w-3 h-3 rounded-full mr-2" style="background-color: <?php echo $schedule['color_code']; ?>"></span>
                                    <?php echo htmlspecialchars($schedule['type_name']); ?>
                                </td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars($schedule['origin'] . ' → ' . $schedule['destination']); ?></td>
                                <td class="px-4 py-2"><?php echo date('H:i', strtotime($schedule['departure_time'])); ?></td>
                                <td class="px-4 py-2"><?php echo date('H:i', strtotime($schedule['arrival_time'])); ?></td>
                                <td class="px-4 py-2">
                                    <?php if (($schedule['delay_minutes'] ?? 0) > 0): ?>
                                        <span class="text-red-600">+<?php echo $schedule['delay_minutes']; ?>m</span>
                                    <?php else: ?>
                                        <span class="text-green-600">On time</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex space-x-1">
                                        <button onclick="viewTrainDetails(<?php echo $schedule['id']; ?>)" 
                                                class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded text-xs">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($auth->hasRole('operator')): ?>
                                        <button onclick="updateTrainStatus(<?php echo $schedule['id']; ?>)" 
                                                class="bg-green-500 hover:bg-green-600 text-white px-2 py-1 rounded text-xs">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($auth->hasRole('scheduler')): ?>
                                        <button onclick="editSchedule(<?php echo $schedule['id']; ?>)" 
                                                class="bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded text-xs">
                                            <i class="fas fa-pencil-alt"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="trainModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden">
        <div class="flex justify-center items-center h-full">
            <div class="bg-white rounded-lg p-6 w-96">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Train Details</h3>
                    <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="modalContent"></div>
            </div>
        </div>
    </div>

    <script>
        // Enhanced JavaScript functionality
        class TTMS {
            constructor() {
                this.schedules = <?php echo json_encode($schedules); ?>;
                this.stations = ['ADD', 'SEB', 'MOJ', 'ADA', 'AWA', 'SEM', 'GAL', 'ALI', 'DJI'];
                this.currentZoom = 1;
                this.panX = 0;
                this.panY = 0;
                this.autoRefreshInterval = null;
                
                this.init();
            }
            
            init() {
                this.initEventListeners();
                this.initTrainDiagram();
                this.startRealTimeClock();
            }
            
            initEventListeners() {
                // Zoom and pan controls
                document.getElementById('zoomIn').addEventListener('click', () => this.zoomIn());
                document.getElementById('zoomOut').addEventListener('click', () => this.zoomOut());
                document.getElementById('resetZoom').addEventListener('click', () => this.resetZoom());
                document.getElementById('panUp').addEventListener('click', () => this.pan(0, 20));
                document.getElementById('panDown').addEventListener('click', () => this.pan(0, -20));
                document.getElementById('panLeft').addEventListener('click', () => this.pan(20, 0));
                document.getElementById('panRight').addEventListener('click', () => this.pan(-20, 0));
                document.getElementById('panCenter').addEventListener('click', () => this.centerView());
                
                // Tab switching
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => this.switchTab(e.target.dataset.tab));
                });
                
                // Auto refresh toggle
                document.getElementById('autoRefresh').addEventListener('click', () => this.toggleAutoRefresh());
                
                // Refresh status
                document.getElementById('refreshStatus').addEventListener('click', () => this.refreshStatus());
            }
            
            initTrainDiagram() {
                const svg = document.getElementById('trainDiagram');
                const timeGrid = document.getElementById('timeGrid');
                const stationLines = document.getElementById('stationLines');
                const trainPaths = document.getElementById('trainPaths');
                const labels = document.getElementById('labels');
                
                // Clear existing content
                [timeGrid, stationLines, trainPaths, labels].forEach(g => g.innerHTML = '');
                
                // Draw time grid
                for (let hour = 0; hour < 24; hour++) {
                    const y = 50 + (hour * 30);
                    
                    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                    line.setAttribute('x1', 100);
                    line.setAttribute('y1', y);
                    line.setAttribute('x2', 1350);
                    line.setAttribute('y2', y);
                    line.setAttribute('class', 'time-grid');
                    timeGrid.appendChild(line);
                    
                    // Time labels
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', 10);
                    text.setAttribute('y', y + 5);
                    text.setAttribute('font-size', '12');
                    text.setAttribute('fill', '#374151');
                    text.textContent = String(hour).padStart(2, '0') + ':00';
                    labels.appendChild(text);
                }
                
                // Draw station lines
                this.stations.forEach((station, index) => {
                    const x = 120 + (index * 140);
                    
                    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                    line.setAttribute('x1', x);
                    line.setAttribute('y1', 30);
                    line.setAttribute('x2', x);
                    line.setAttribute('y2', 770);
                    line.setAttribute('class', 'station-line');
                    stationLines.appendChild(line);
                    
                    // Station labels
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', x);
                    text.setAttribute('y', 20);
                    text.setAttribute('text-anchor', 'middle');
                    text.setAttribute('font-size', '12');
                    text.setAttribute('font-weight', 'bold');
                    text.setAttribute('fill', '#1f2937');
                    text.textContent = station;
                    labels.appendChild(text);
                });
                
                // Draw train paths
                this.schedules.forEach((schedule) => {
                    this.drawTrainPath(schedule, trainPaths);
                });
            }
            
            drawTrainPath(schedule, container) {
                const departureTime = new Date('1970-01-01T' + schedule.departure_time);
                const arrivalTime = new Date('1970-01-01T' + schedule.arrival_time);
                
                const startY = 50 + (departureTime.getHours() * 30) + (departureTime.getMinutes() * 0.5);
                const endY = 50 + (arrivalTime.getHours() * 30) + (arrivalTime.getMinutes() * 0.5);
                
                // Assuming origin is first station and destination is last for simplicity
                const startX = 120;
                const endX = 120 + ((this.stations.length - 1) * 140);
                
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                path.setAttribute('x1', startX);
                path.setAttribute('y1', startY);
                path.setAttribute('x2', endX);
                path.setAttribute('y2', endY);
                path.setAttribute('stroke', schedule.color_code);
                path.setAttribute('class', 'train-path' + (schedule.delay_minutes > 0 ? ' delayed' : ''));
                path.setAttribute('data-schedule-id', schedule.id);
                path.style.cursor = 'pointer';
                
                // Add click event
                path.addEventListener('click', () => viewTrainDetails(schedule.id));
                
                // Add tooltip
                const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                title.textContent = `${schedule.train_number} - ${schedule.type_name}`;
                path.appendChild(title);
                
                container.appendChild(path);
            }
            
            // Zoom and pan functions
            zoomIn() {
                this.currentZoom = Math.min(this.currentZoom * 1.2, 3);
                this.updateTransform();
            }
            
            zoomOut() {
                this.currentZoom = Math.max(this.currentZoom / 1.2, 0.5);
                this.updateTransform();
            }
            
            resetZoom() {
                this.currentZoom = 1;
                this.panX = 0;
                this.panY = 0;
                this.updateTransform();
            }
            
            pan(deltaX, deltaY) {
                this.panX += deltaX;
                this.panY += deltaY;
                this.updateTransform();
            }
            
            centerView() {
                this.panX = 0;
                this.panY = 0;
                this.updateTransform();
            }
            
            updateTransform() {
                const svg = document.getElementById('trainDiagram');
                svg.style.transform = `scale(${this.currentZoom}) translate(${this.panX}px, ${this.panY}px)`;
            }
            
            switchTab(tabName) {
                // Update tab buttons
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.classList.remove('active', 'border-blue-500', 'text-blue-600');
                    btn.classList.add('border-transparent', 'text-gray-500');
                });
                
                document.querySelector(`[data-tab="${tabName}"]`).classList.add('active', 'border-blue-500', 'text-blue-600');
                document.querySelector(`[data-tab="${tabName}"]`).classList.remove('border-transparent', 'text-gray-500');
                
                // Update tab content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.add('hidden');
                });
                
                document.getElementById(`${tabName}Tab`).classList.remove('hidden');
            }
            
            toggleAutoRefresh() {
                if (this.autoRefreshInterval) {
                    clearInterval(this.autoRefreshInterval);
                    this.autoRefreshInterval = null;
                    document.getElementById('autoRefresh').innerHTML = '<i class="fas fa-sync mr-1"></i>Auto Refresh';
                } else {
                    this.autoRefreshInterval = setInterval(() => {
                        this.refreshStatus();
                    }, 30000);
                    document.getElementById('autoRefresh').innerHTML = '<i class="fas fa-pause mr-1"></i>Stop Refresh';
                }
            }
            
            refreshStatus() {
                location.reload();
            }
            
            startRealTimeClock() {
                const updateClock = () => {
                    const now = new Date();
                    const timeString = now.toLocaleTimeString('en-US', {
                        timeZone: 'Africa/Addis_Ababa',
                        hour12: false
                    });
                    document.getElementById('currentTime').textContent = timeString;
                };
                
                updateClock();
                setInterval(updateClock, 1000);
            }
        }
        
        // Modal functions
        function viewTrainDetails(scheduleId) {
            const schedule = ttms.schedules.find(s => s.id == scheduleId);
            if (!schedule) return;
            
            const content = `
                <div class="space-y-3">
                    <div><strong>Train Number:</strong> ${schedule.train_number}</div>
                    <div><strong>Type:</strong> ${schedule.type_name}</div>
                    <div><strong>Route:</strong> ${schedule.origin} → ${schedule.destination}</div>
                    <div><strong>Departure:</strong> ${schedule.departure_time}</div>
                    <div><strong>Arrival:</strong> ${schedule.arrival_time}</div>
                    <div><strong>Track:</strong> ${schedule.track_assignment || 'Not assigned'}</div>
                    <div><strong>Status:</strong> ${schedule.current_status || 'On time'}</div>
                    ${schedule.delay_minutes > 0 ? `<div><strong>Delay:</strong> ${schedule.delay_minutes} minutes</div>` : ''}
                </div>
            `;
            
            document.getElementById('modalContent').innerHTML = content;
            document.getElementById('trainModal').classList.remove('hidden');
        }
        
        function closeModal() {
            document.getElementById('trainModal').classList.add('hidden');
        }
        
        function updateTrainStatus(scheduleId) {
            alert('Train status update functionality - to be implemented');
        }
        
        function editSchedule(scheduleId) {
            alert('Schedule editing functionality - to be implemented');
        }
        
        // Initialize the system
        const ttms = new TTMS();
    </script>
</body>
</html>

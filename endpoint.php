<?php
/**
 * endpoint.php - Receptor BAIK Multi-Dispositivo
 * Versión 2.5 - GET + POST Support
 */
date_default_timezone_set('America/Bogota');

$globalLogFile = __DIR__ . '/baik_data.txt';
$debugLog = __DIR__ . '/debug.txt';

// ========================================
// MANEJO DE GET REQUESTS
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);

    // GET /clients/{device_id}/latest.json
    if (preg_match('#^/clients/([A-Za-z0-9_-]+)/latest\.json$#', $path, $matches)) {
        $device_id = $matches[1];
        $latestFile = __DIR__ . '/clients/' . $device_id . '/latest.json';

        if (file_exists($latestFile)) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Access-Control-Allow-Origin: *');
            readfile($latestFile);
            exit;
        } else {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Device not found']);
            exit;
        }
    }

    // GET /clients/{device_id}/data.txt
    if (preg_match('#^/clients/([A-Za-z0-9_-]+)/data\.txt$#', $path, $matches)) {
        $device_id = $matches[1];
        $dataFile = __DIR__ . '/clients/' . $device_id . '/data.txt';

        if (file_exists($dataFile)) {
            header('Content-Type: text/plain; charset=UTF-8');
            header('Access-Control-Allow-Origin: *');
            readfile($dataFile);
            exit;
        } else {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Device not found";
            exit;
        }
    }

    // GET /clients/{device_id}/debug_history.txt
    if (preg_match('#^/clients/([A-Za-z0-9_-]+)/debug_history\.txt$#', $path, $matches)) {
        $device_id = $matches[1];
        $debugFile = __DIR__ . '/clients/' . $device_id . '/debug_history.txt';

        if (file_exists($debugFile)) {
            header('Content-Type: text/plain; charset=UTF-8');
            header('Access-Control-Allow-Origin: *');
            readfile($debugFile);
            exit;
        } else {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Debug file not found";
            exit;
        }
    }

    // GET /clients/ - Listar dispositivos
    if (preg_match('#^/clients/?$#', $path)) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');

        $clientsDir = __DIR__ . '/clients/';
        $devices = [];

        if (is_dir($clientsDir)) {
            $dirs = array_diff(scandir($clientsDir), ['.', '..']);
            foreach ($dirs as $dir) {
                $latestFile = $clientsDir . $dir . '/latest.json';
                if (file_exists($latestFile)) {
                    $data = json_decode(file_get_contents($latestFile), true);
                    if ($data) {
                        $devices[] = [
                            'device_id' => $dir,
                            'last_update' => $data['timestamp'] ?? 'Unknown',
                            'battery' => $data['battery'] ?? 0,
                            'is_moving' => $data['is_moving'] ?? false,
                            'gps_valid' => $data['gps']['is_valid'] ?? false,
                            'latest_json_url' => "/clients/$dir/latest.json",
                            'data_log_url' => "/clients/$dir/data.txt"
                        ];
                    }
                }
            }
        }

        echo json_encode([
            'total_devices' => count($devices),
            'devices' => $devices
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET / - Status page
    if ($path === '/' || $path === '') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');

        echo json_encode([
            'status' => 'online',
            'service' => 'BAIK API Endpoint',
            'version' => '2.5',
            'timestamp' => date('Y-m-d H:i:s'),
            'endpoints' => [
                'POST /' => 'Receive device data (JSON)',
                'GET /clients/' => 'List all devices',
                'GET /clients/{device_id}/latest.json' => 'Get latest device data',
                'GET /clients/{device_id}/data.txt' => 'Get device log file',
                'GET /clients/{device_id}/debug_history.txt' => 'Get debug history'
            ]
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // 404 para otras rutas GET
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

// ========================================
// MANEJO DE POST REQUESTS (ORIGINAL)
// ========================================
header('Content-Type: text/html; charset=UTF-8');

$json_recibido = file_get_contents('php://input');

$debugEntry = str_repeat('=', 80) . "\n";
$debugEntry .= "TIMESTAMP:      " . date('Y-m-d H:i:s') . "\n";
$debugEntry .= "IP ORIGEN:      " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n";
$debugEntry .= "RAW INPUT:      " . ($json_recibido ?: '(vacío)') . "\n";
$debugEntry .= str_repeat('=', 80) . "\n\n";
file_put_contents($debugLog, $debugEntry, FILE_APPEND | LOCK_EX);

$data = json_decode($json_recibido, true);

function interpretDebugPoint($dp) {
    $points = [
        1  => "Loop Principal - Inicio de ciclo",
        2  => "Lectura GPS - Get_GPS_Legacy()",
        3  => "Lectura Bateria - ADC",
        4  => "Smart Retry GPS - Loop 15s",
        5  => "Inicio GPRS - Comandos AT (SAPBR)",
        6  => "HTTP Setup - AT+HTTPPARA",
        7  => "Construccion JSON - sprintf/strcat",
        8  => "Envio HTTP - AT+HTTPDATA/HTTPACTION",
        9  => "HTTP Cleanup - AT+HTTPTERM",
        10 => "Limpieza Final - Reset variables"
    ];
    return $points[$dp] ?? "Desconocido ($dp)";
}

function analyzeFreezeProbability($dp) {
    $analysis = [
        1  => "Probable freeze entre transmisiones",
        2  => "CRITICO: Freeze en lectura GPS",
        3  => "Freeze en ADC (poco probable)",
        4  => "CRITICO: Freeze en smart retry GPS",
        5  => "ALTO: Freeze en comandos AT iniciales",
        6  => "ALTO: Freeze en configuracion HTTP",
        7  => "MEDIO: Freeze en construccion JSON",
        8  => "CRITICO: Freeze en transmision HTTP",
        9  => "MEDIO: Freeze en cleanup GPRS",
        10 => "Freeze despues de transmision exitosa"
    ];
    return $analysis[$dp] ?? "Analisis no disponible";
}

if ($data && is_array($data)) {
    $timestamp = date('Y-m-d H:i:s');
    $device_id = isset($data['s']) ? trim($data['s']) : 'UNKNOWN';

    if (!preg_match('/^[A-Za-z0-9_-]+$/', $device_id)) {
        $device_id = 'INVALID_' . substr(md5($device_id), 0, 8);
    }

    $deviceDir = __DIR__ . '/clients/' . $device_id;
    if (!file_exists($deviceDir)) {
        @mkdir($deviceDir, 0755, true);
    }

    $deviceLogFile = $deviceDir . '/data.txt';
    $deviceLatestFile = $deviceDir . '/latest.json';
    $deviceDebugFile = $deviceDir . '/debug_history.txt';

    $fallen_flag     = $data['f'] ?? 0;
    $gps_raw         = $data['n'] ?? '0.0000,0.0000';
    $running_flag    = $data['r'] ?? 0;
    $battery         = $data['b'] ?? 0;
    $mpu_success     = $data['mc'] ?? 0;
    $cx              = $data['cx'] ?? 0;
    $cy              = $data['cy'] ?? 0;
    $cz              = $data['cz'] ?? 0;
    $impact_progress = $data['id'] ?? 0;
    $seconds_after   = $data['sa'] ?? 0;
    $debug_point     = $data['dp'] ?? 0;

    $latitude = null;
    $longitude = null;
    $gpsText = "GPS: Buscando senal satelital...";

    if (strpos($gps_raw, ',') !== false) {
        list($lat, $lon) = explode(',', $gps_raw, 2);
        $lat = trim($lat);
        $lon = trim($lon);

        if ($lat !== '0.0000' && $lat !== '0' && $lon !== '0.0000' && $lon !== '0') {
            $latitude = $lat;
            $longitude = $lon;
            $gpsText = "Lat: $latitude, Lon: $longitude\n";
            $gpsText .= "                 Maps: https://www.google.com/maps?q=$latitude,$longitude";
        }
    }

    $parseG = function($val) {
        $g = $val / 100.0;
        if ($val >= 150) return sprintf("%.2fg IMPACTO CRITICO", $g);
        if ($val >= 40)  return sprintf("%.2fg Sacudida fuerte", $g);
        if ($val >= 15)  return sprintf("%.2fg Movimiento", $g);
        return sprintf("%.2fg (Reposo)", $g);
    };

    $logEntry = "\n" . str_repeat('=', 80) . "\n";
    $logEntry .= "  REPORTE BAIK: $device_id | V6.9.8 DEBUG\n";
    $logEntry .= str_repeat('=', 80) . "\n";
    $logEntry .= "HORA LOCAL:     $timestamp\n";

    if ($debug_point > 0) {
        $logEntry .= str_repeat('-', 80) . "\n";
        $logEntry .= "DEBUG POINT:  $debug_point - " . interpretDebugPoint($debug_point) . "\n";
        $logEntry .= "   ANALISIS:     " . analyzeFreezeProbability($debug_point) . "\n";
        $logEntry .= str_repeat('-', 80) . "\n";
    }

    $bStatus = "BAT: $battery%";
    if ($battery < 20) $bStatus = "BAT: $battery% (CRITICO)";
    elseif ($battery < 50) $bStatus = "BAT: $battery% (BAJO)";
    $logEntry .= "BATERIA:        $bStatus\n";

    $statusText = ($running_flag == 1) ? "EN MOVIMIENTO (45s)" : "EN REPOSO (20 min)";
    if ($fallen_flag == 1) $statusText = "EMERGENCIA: CAIDA DETECTADA";
    $logEntry .= "ESTADO:         $statusText\n";
    $logEntry .= "GPS:            $gpsText\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    $logEntry .= "DEBUG ACELEROMETRO:\n";
    $logEntry .= "X: " . $parseG($cx) . " | Y: " . $parseG($cy) . " | Z: " . $parseG($cz) . "\n";

    if ($impact_progress == 1) {
        $logEntry .= "CONTADOR CAIDA: IMPACTO | Espera: {$seconds_after}s / 30s\n";
    }

    $logEntry .= "LECTURAS MPU:   $mpu_success\n";
    $logEntry .= "JSON RECIBIDO:  $json_recibido\n";
    $logEntry .= str_repeat('=', 80) . "\n";

    file_put_contents($globalLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    file_put_contents($deviceLogFile, $logEntry, FILE_APPEND | LOCK_EX);

    if ($debug_point > 0) {
        $debugHistoryEntry = date('Y-m-d H:i:s') . " | dp=$debug_point | " .
                             interpretDebugPoint($debug_point) . " | " .
                             "GPS=$gps_raw | BAT=$battery% | MC=$mpu_success\n";
        file_put_contents($deviceDebugFile, $debugHistoryEntry, FILE_APPEND | LOCK_EX);
    }

    $latestData = [
        'device_id'    => $device_id,
        'timestamp'    => $timestamp,
        'is_moving'    => (bool)$running_flag,
        'battery'      => (int)$battery,
        'is_fallen'    => (bool)$fallen_flag,
        'gps' => [
            'lat'       => $latitude,
            'lon'       => $longitude,
            'is_valid'  => ($latitude !== null),
            'google_maps' => ($latitude) ? "https://www.google.com/maps?q=$latitude,$longitude" : null
        ],
        'sensors' => [
            'max_g_force' => max($cx, $cy, $cz) / 100.0,
            'impact_active' => (bool)$impact_progress,
            'stability_timer' => (int)$seconds_after
        ],
        'debug' => [
            'last_point' => (int)$debug_point,
            'point_description' => interpretDebugPoint($debug_point),
            'freeze_probability' => analyzeFreezeProbability($debug_point)
        ]
    ];
    file_put_contents($deviceLatestFile, json_encode($latestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    http_response_code(200);
    echo "OK";
} else {
    http_response_code(400);
    echo "ERROR: JSON invalido o vacio";
}
?>

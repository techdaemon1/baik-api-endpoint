<?php
header('Content-Type: text/html; charset=UTF-8');
date_default_timezone_set('America/Bogota');

$globalLogFile = __DIR__ . '/baik_data.txt';
$debugLog = __DIR__ . '/debug.txt';
$json_recibido = file_get_contents('php://input');

$debugEntry = str_repeat('=', 80) . "\n";
$debugEntry .= "TIMESTAMP: " . date('Y-m-d H:i:s') . "\n";
$debugEntry .= "IP ORIGEN: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n";
$debugEntry .= "RAW INPUT: " . ($json_recibido ?: '(vacío)') . "\n";
$debugEntry .= str_repeat('=', 80) . "\n\n";
file_put_contents($debugLog, $debugEntry, FILE_APPEND | LOCK_EX);

$data = json_decode($json_recibido, true);

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

    $battery = $data['b'] ?? 0;
    $gps_raw = $data['n'] ?? '0.0000,0.0000';
    $running_flag = $data['r'] ?? 0;
    $fallen_flag = $data['f'] ?? 0;

    $logEntry = "\n" . str_repeat('=', 80) . "\n";
    $logEntry .= "BAIK: $device_id | " . $timestamp . "\n";
    $logEntry .= "Batería: $battery% | GPS: $gps_raw\n";
    $logEntry .= "Estado: " . ($running_flag ? "Movimiento" : "Reposo") . "\n";
    $logEntry .= str_repeat('=', 80) . "\n";

    file_put_contents($globalLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    file_put_contents($deviceLogFile, $logEntry, FILE_APPEND | LOCK_EX);

    $latestData = [
        'device_id' => $device_id,
        'timestamp' => $timestamp,
        'battery' => (int)$battery,
        'gps_raw' => $gps_raw,
        'is_moving' => (bool)$running_flag,
        'is_fallen' => (bool)$fallen_flag
    ];
    file_put_contents($deviceLatestFile, json_encode($latestData, JSON_PRETTY_PRINT));

    http_response_code(200);
    echo "OK";
} else {
    http_response_code(400);
    echo "ERROR: JSON invalido";
}
?>

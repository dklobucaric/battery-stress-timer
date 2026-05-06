<?php
/*
|--------------------------------------------------------------------------
| Battery Stress Timer - API Start
|--------------------------------------------------------------------------
|
| Creates a new battery test record in MySQL.
|
| Vibe code by Dalibor Klobučarić & my friend ChatGPT
|
|--------------------------------------------------------------------------
*/

require __DIR__ . '/../db.php';

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function uuid_v4(): string
{
    $data = random_bytes(16);

    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function str_or_null($value, int $maxLength = 255): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, $maxLength);
}

function int_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (int)$value : null;
}

function float_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float)$value : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'ok' => false,
        'error' => 'POST required',
    ], 405);
}

$config = app_config();
$profiles = $config['profiles'] ?? [];
$app = $config['app'] ?? [];

$data = read_json_body();

$profileKey = str_or_null($data['profile'] ?? null, 40) ?? ($app['default_profile'] ?? 'medium');

if (!isset($profiles[$profileKey])) {
    $profileKey = $app['default_profile'] ?? 'medium';
}

$profile = $profiles[$profileKey] ?? [
    'workload_interval_seconds' => 60,
    'workload_duration_seconds' => 15,
];

$testUuid = uuid_v4();

$battery = is_array($data['battery'] ?? null) ? $data['battery'] : [];
$screen = is_array($data['screen'] ?? null) ? $data['screen'] : [];
$webgl = is_array($data['webgl'] ?? null) ? $data['webgl'] : [];
$input = is_array($data['input'] ?? null) ? $data['input'] : [];

$label = str_or_null($data['label'] ?? null, 255) ?? 'Unnamed battery test';

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO battery_tests (
            test_uuid,
            label,
            status,

            client_ip,
            user_agent,
            accept_language,

            browser_name,
            browser_version,
            os_name,
            platform,
            language,
            timezone,

            cpu_cores,
            device_memory_gb,

            screen_width,
            screen_height,
            avail_width,
            avail_height,
            pixel_ratio,

            touch_enabled,
            max_touch_points,

            webgl_vendor,
            webgl_renderer,

            battery_supported,
            battery_start_percent,
            battery_last_percent,
            battery_charging,
            battery_discharging_time_seconds,

            stress_profile,
            worker_count,
            workload_interval_seconds,
            workload_duration_seconds,
            workload_cycles_completed,

            started_at,
            last_seen_at,
            elapsed_seconds
        ) VALUES (
            :test_uuid,
            :label,
            'running',

            :client_ip,
            :user_agent,
            :accept_language,

            :browser_name,
            :browser_version,
            :os_name,
            :platform,
            :language,
            :timezone,

            :cpu_cores,
            :device_memory_gb,

            :screen_width,
            :screen_height,
            :avail_width,
            :avail_height,
            :pixel_ratio,

            :touch_enabled,
            :max_touch_points,

            :webgl_vendor,
            :webgl_renderer,

            :battery_supported,
            :battery_start_percent,
            :battery_last_percent,
            :battery_charging,
            :battery_discharging_time_seconds,

            :stress_profile,
            :worker_count,
            :workload_interval_seconds,
            :workload_duration_seconds,
            :workload_cycles_completed,

            NOW(),
            NOW(),
            0
        )
    ");

    $batterySupported = !empty($battery['supported']) ? 1 : 0;
    $batteryPercent = int_or_null($battery['level_percent'] ?? null);

    $stmt->execute([
        ':test_uuid' => $testUuid,
        ':label' => $label,

        ':client_ip' => get_client_ip(),
        ':user_agent' => str_or_null($_SERVER['HTTP_USER_AGENT'] ?? null, 65535),
        ':accept_language' => str_or_null($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null, 255),

        ':browser_name' => str_or_null($data['browser_name'] ?? null, 80),
        ':browser_version' => str_or_null($data['browser_version'] ?? null, 80),
        ':os_name' => str_or_null($data['os_name'] ?? null, 80),
        ':platform' => str_or_null($data['platform'] ?? null, 120),
        ':language' => str_or_null($data['language'] ?? null, 40),
        ':timezone' => str_or_null($data['timezone'] ?? null, 80),

        ':cpu_cores' => int_or_null($data['cpu_cores'] ?? null),
        ':device_memory_gb' => float_or_null($data['device_memory_gb'] ?? null),

        ':screen_width' => int_or_null($screen['width'] ?? null),
        ':screen_height' => int_or_null($screen['height'] ?? null),
        ':avail_width' => int_or_null($screen['avail_width'] ?? null),
        ':avail_height' => int_or_null($screen['avail_height'] ?? null),
        ':pixel_ratio' => float_or_null($screen['pixel_ratio'] ?? null),

        ':touch_enabled' => isset($input['touch_enabled']) ? (int)(bool)$input['touch_enabled'] : null,
        ':max_touch_points' => int_or_null($input['max_touch_points'] ?? null),

        ':webgl_vendor' => str_or_null($webgl['vendor'] ?? null, 255),
        ':webgl_renderer' => str_or_null($webgl['renderer'] ?? null, 255),

        ':battery_supported' => $batterySupported,
        ':battery_start_percent' => $batteryPercent,
        ':battery_last_percent' => $batteryPercent,
        ':battery_charging' => isset($battery['charging']) ? (int)(bool)$battery['charging'] : null,
        ':battery_discharging_time_seconds' => int_or_null($battery['discharging_time_seconds'] ?? null),

        ':stress_profile' => $profileKey,
        ':worker_count' => int_or_null($data['worker_count'] ?? null),
        ':workload_interval_seconds' => int_or_null($profile['workload_interval_seconds'] ?? 60),
        ':workload_duration_seconds' => int_or_null($profile['workload_duration_seconds'] ?? 15),
        ':workload_cycles_completed' => 0,
    ]);

    json_response([
        'ok' => true,
        'test_uuid' => $testUuid,
        'heartbeat_seconds' => (int)($app['heartbeat_seconds'] ?? 60),
        'server_time' => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Failed to start test. Please try again.',
    ], 500);
}

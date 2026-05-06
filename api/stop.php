<?php
/*
|--------------------------------------------------------------------------
| Battery Stress Timer - API Stop
|--------------------------------------------------------------------------
|
| Stops a running battery test.
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

function int_or_zero($value): int
{
    return is_numeric($value) ? max(0, (int)$value) : 0;
}

function int_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (int)$value : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'ok' => false,
        'error' => 'POST required',
    ], 405);
}

$data = read_json_body();

$testUuid = trim((string)($data['test_uuid'] ?? ''));

if ($testUuid === '') {
    json_response([
        'ok' => false,
        'error' => 'Missing test_uuid',
    ], 400);
}

$battery = is_array($data['battery'] ?? null) ? $data['battery'] : [];

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE battery_tests
        SET
            status = 'stopped',
            ended_at = NOW(),
            last_seen_at = NOW(),
            elapsed_seconds = :elapsed_seconds,
            workload_cycles_completed = :workload_cycles_completed,

            battery_last_percent = :battery_last_percent,
            battery_charging = :battery_charging,
            battery_discharging_time_seconds = :battery_discharging_time_seconds
        WHERE test_uuid = :test_uuid
        LIMIT 1
    ");

    $stmt->execute([
        ':elapsed_seconds' => int_or_zero($data['elapsed_seconds'] ?? 0),
        ':workload_cycles_completed' => int_or_zero($data['workload_cycles_completed'] ?? 0),

        ':battery_last_percent' => int_or_null($battery['level_percent'] ?? null),
        ':battery_charging' => isset($battery['charging']) ? (int)(bool)$battery['charging'] : null,
        ':battery_discharging_time_seconds' => int_or_null($battery['discharging_time_seconds'] ?? null),

        ':test_uuid' => $testUuid,
    ]);

    if ($stmt->rowCount() < 1) {
        json_response([
            'ok' => false,
            'error' => 'Test not found',
        ], 404);
    }

    json_response([
        'ok' => true,
        'server_time' => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Failed to stop test. Please try again.',
    ], 500);
}

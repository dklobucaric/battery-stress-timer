<?php
/*
|--------------------------------------------------------------------------
| Battery Stress Timer - Running Test Screen
|--------------------------------------------------------------------------
|
| Version: 1.0.0
|
| Fullscreen timer screen with:
|
| - big monospace timer with centiseconds
| - smooth animated background
| - selected stress profile display
| - battery telemetry when supported
| - fullscreen button
| - stop test confirmation
| - local JavaScript/Web Worker CPU workload
| - workload cycle counter
| - MySQL start / heartbeat / stop logging
| - API start retry
| - final sendBeacon heartbeat on page close
|
| Vibe code by Dalibor Klobučarić & my friend ChatGPT
|
|--------------------------------------------------------------------------
*/

$config = require __DIR__ . '/config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$appName = $config['app']['name'] ?? 'Battery Stress Timer';
$appVersion = $config['app']['version'] ?? '1.0.0';

$profiles = $config['profiles'] ?? [];
$defaultProfile = $config['app']['default_profile'] ?? 'medium';

$selectedProfileKey = $_GET['profile'] ?? $defaultProfile;

if (!isset($profiles[$selectedProfileKey])) {
    $selectedProfileKey = $defaultProfile;
}

$selectedProfile = $profiles[$selectedProfileKey] ?? [
    'label' => 'Medium',
    'description' => 'Balanced CPU + display stress',
    'worker_max' => 4,
    'workload_interval_seconds' => 60,
    'workload_duration_seconds' => 15,
];

$testLabel = trim((string)($_GET['label'] ?? ''));

if ($testLabel === '') {
    $testLabel = 'Unnamed battery test';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title><?= h($appName) ?> - Running</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--
      DD-Lab Battery Stress Timer v<?= h($appVersion) ?>
      Running test screen
      Vibe code by Dalibor Klobučarić & my friend ChatGPT
    -->

    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="test-page">

    <main class="test-shell">

        <section class="timer-panel">

            <div class="test-topline">
                <span><?= h($appName) ?></span>
                <span>v<?= h($appVersion) ?></span>
            </div>

            <div class="test-label" title="<?= h($testLabel) ?>">
                <?= h($testLabel) ?>
            </div>

            <div class="timer-display" aria-label="Elapsed time">
                <span id="timerMain">00:00:00</span><span class="timer-centi" id="timerCenti">.00</span>
            </div>

            <div class="telemetry-grid">

                <div class="telemetry-card">
                    <span class="telemetry-label">Workload cycles completed</span>
                    <strong id="cyclesCompleted">0</strong>
                </div>

                <div class="telemetry-card">
                    <span class="telemetry-label">Workload status</span>
                    <strong id="workloadStatus">waiting</strong>
                </div>

                <div class="telemetry-card">
                    <span class="telemetry-label">Battery</span>
                    <strong id="batteryStatus">detecting…</strong>
                </div>

                <div class="telemetry-card">
                    <span class="telemetry-label">Battery used</span>
                    <strong id="batteryUsed">n/a</strong>
                </div>

                <div class="telemetry-card">
                    <span class="telemetry-label">Server sync</span>
                    <strong id="serverSync">starting...</strong>
                </div>

                <div class="telemetry-card">
                    <span class="telemetry-label">Fullscreen</span>
                    <strong id="fullscreenStatus">no</strong>
                </div>

                <div class="telemetry-card">
                    <span class="telemetry-label">Wake lock</span>
                    <strong id="wakeStatus">not requested</strong>
                </div>

                <div class="telemetry-card">
                    <span class="telemetry-label">Profile</span>
                    <strong><?= h($selectedProfile['label'] ?? ucfirst($selectedProfileKey)) ?></strong>
                </div>

            </div>

            <div class="profile-runtime-info">
                <?= h($selectedProfile['description'] ?? '') ?>
                · workload <?= h($selectedProfile['workload_duration_seconds'] ?? '?') ?>s every
                <?= h($selectedProfile['workload_interval_seconds'] ?? '?') ?>s
                · max workers <?= h($selectedProfile['worker_max'] ?? '?') ?>
            </div>

            <div class="test-actions">
                <button type="button" class="secondary-action" id="fullscreenButton">
                    Enter fullscreen
                </button>

                <button type="button" class="danger-action" id="stopButton">
                    Stop test
                </button>

                <a href="index.php" class="success-action" id="backHomeButton" hidden>
                    ← Vrati se na početnu
                </a>
            </div>

            <footer class="test-footer">
                Vibe code by Dalibor Klobučarić & my friend ChatGPT
            </footer>

        </section>

    </main>

    <div class="stop-modal" id="stopModal" hidden>
        <div class="stop-modal-card">
            <h2>Stop test?</h2>
            <p>
                Are you sure you want to stop this test?
                Battery tests are long, and one accidental click is violence against humanity.
            </p>

            <div class="stop-modal-actions">
                <button type="button" class="secondary-action" id="cancelStopButton">
                    Cancel
                </button>

                <button type="button" class="danger-action" id="confirmStopButton">
                    Stop test
                </button>
            </div>
        </div>
    </div>

    <script>
        window.DDLAB_TEST_CONFIG = {
            appName: <?= json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            appVersion: <?= json_encode($appVersion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            label: <?= json_encode($testLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            profileKey: <?= json_encode($selectedProfileKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            profile: <?= json_encode($selectedProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };
    </script>

    <script>
        /*
        |--------------------------------------------------------------------------
        | Battery Stress Timer - Frontend test screen logic
        |--------------------------------------------------------------------------
        */

        const timerMain = document.getElementById('timerMain');
        const timerCenti = document.getElementById('timerCenti');

        const cyclesCompleted = document.getElementById('cyclesCompleted');
        const workloadStatus = document.getElementById('workloadStatus');
        const batteryStatus = document.getElementById('batteryStatus');
        const batteryUsed = document.getElementById('batteryUsed');
        const serverSync = document.getElementById('serverSync');
        const fullscreenStatus = document.getElementById('fullscreenStatus');
        const wakeStatus = document.getElementById('wakeStatus');

        const fullscreenButton = document.getElementById('fullscreenButton');
        const stopButton = document.getElementById('stopButton');
        const backHomeButton = document.getElementById('backHomeButton');

        const stopModal = document.getElementById('stopModal');
        const cancelStopButton = document.getElementById('cancelStopButton');
        const confirmStopButton = document.getElementById('confirmStopButton');

        let testRunning = true;
        let startTime = performance.now();
        let stoppedElapsedMs = 0;

        let testUuid = null;
        let heartbeatSeconds = 60;
        let heartbeatTimer = null;
        let startRetryTimer = null;
        let startSyncInProgress = false;
        let serverSessionStarted = false;

        let batteryManager = null;
        let batteryStartPercent = null;
        let batteryLastPercent = null;
        let batteryLastCharging = null;
        let batteryLastDischargingTime = null;

        let wakeLock = null;

        let workloadCycleCounter = 0;
        let workloadWorkers = [];
        let workloadBurstTimer = null;
        let workloadStopTimer = null;
        let workloadBurstActive = false;
        let workloadInitialized = false;

        const profileKey = window.DDLAB_TEST_CONFIG.profileKey || 'medium';
        const profileConfig = window.DDLAB_TEST_CONFIG.profile || {};

        const workloadEnabled = profileConfig.workload_enabled !== false;

        const workloadIntervalSeconds = Number(profileConfig.workload_interval_seconds ?? 60);
        const workloadDurationSeconds = Number(profileConfig.workload_duration_seconds ?? 15);
        const workloadWorkerMax = Number(profileConfig.worker_max ?? 4);

        const workloadBatchSize = workloadEnabled ? getBatchSizeForProfile(profileKey) : 0;
        const workloadWorkerCount = workloadEnabled ? calculateWorkerCount(workloadWorkerMax) : 0;

        function pad2(value) {
            return String(value).padStart(2, '0');
        }

        function formatElapsed(ms) {
            const totalCentiseconds = Math.floor(ms / 10);
            const centiseconds = totalCentiseconds % 100;

            const totalSeconds = Math.floor(ms / 1000);
            const seconds = totalSeconds % 60;

            const totalMinutes = Math.floor(totalSeconds / 60);
            const minutes = totalMinutes % 60;

            const hours = Math.floor(totalMinutes / 60);

            return {
                main: `${pad2(hours)}:${pad2(minutes)}:${pad2(seconds)}`,
                centi: `.${pad2(centiseconds)}`
            };
        }

        function getElapsedMs() {
            return testRunning
                ? performance.now() - startTime
                : stoppedElapsedMs;
        }

        function getElapsedSeconds() {
            return Math.floor(getElapsedMs() / 1000);
        }

        function renderTimer() {
            const formatted = formatElapsed(getElapsedMs());

            timerMain.textContent = formatted.main;
            timerCenti.textContent = formatted.centi;

            if (testRunning) {
                requestAnimationFrame(renderTimer);
            }
        }

        function formatBatteryTime(seconds) {
            if (!Number.isFinite(seconds) || seconds < 0) {
                return 'n/a';
            }

            const minutesTotal = Math.round(seconds / 60);
            const hours = Math.floor(minutesTotal / 60);
            const minutes = minutesTotal % 60;

            if (hours > 0) {
                return `${hours}h ${minutes}m`;
            }

            return `${minutes}m`;
        }

        async function ensureBatteryManager() {
            if (batteryManager) {
                return batteryManager;
            }

            if (!navigator.getBattery) {
                return null;
            }

            try {
                batteryManager = await navigator.getBattery();
                return batteryManager;
            } catch (error) {
                return null;
            }
        }

        function collectBatterySnapshotSync() {
            if (!batteryManager) {
                return {
                    supported: false,
                    level_percent: null,
                    charging: null,
                    discharging_time_seconds: null
                };
            }

            return {
                supported: true,
                level_percent: Math.round(batteryManager.level * 100),
                charging: !!batteryManager.charging,
                discharging_time_seconds: Number.isFinite(batteryManager.dischargingTime)
                    ? Math.round(batteryManager.dischargingTime)
                    : null
            };
        }

        async function collectBatterySnapshot() {
            const battery = await ensureBatteryManager();

            if (!battery) {
                return {
                    supported: false,
                    level_percent: null,
                    charging: null,
                    discharging_time_seconds: null
                };
            }

            return collectBatterySnapshotSync();
        }

        async function initBatteryTelemetry() {
            const battery = await ensureBatteryManager();

            if (!battery) {
                batteryStatus.textContent = 'unavailable';
                batteryUsed.textContent = 'n/a';
                return;
            }

            function updateBattery() {
                const percent = Math.round(battery.level * 100);

                if (batteryStartPercent === null) {
                    batteryStartPercent = percent;
                }

                batteryLastPercent = percent;
                batteryLastCharging = !!battery.charging;
                batteryLastDischargingTime = Number.isFinite(battery.dischargingTime)
                    ? Math.round(battery.dischargingTime)
                    : null;

                const state = battery.charging ? 'charging' : 'discharging';

                const remaining = battery.charging
                    ? formatBatteryTime(battery.chargingTime)
                    : formatBatteryTime(battery.dischargingTime);

                batteryStatus.textContent = `${percent}% / ${state}`;

                if (batteryStartPercent !== null && batteryLastPercent !== null) {
                    const used = Math.max(0, batteryStartPercent - batteryLastPercent);
                    batteryUsed.textContent = `${used}%`;
                } else {
                    batteryUsed.textContent = 'n/a';
                }

                if (!battery.charging && remaining !== 'n/a') {
                    batteryStatus.textContent += ` / ${remaining} left`;
                }
            }

            updateBattery();

            battery.addEventListener('chargingchange', updateBattery);
            battery.addEventListener('levelchange', updateBattery);
            battery.addEventListener('chargingtimechange', updateBattery);
            battery.addEventListener('dischargingtimechange', updateBattery);
        }

        function updateFullscreenStatus() {
            const isFullscreen = !!document.fullscreenElement;

            fullscreenStatus.textContent = isFullscreen ? 'yes' : 'no';
            fullscreenButton.textContent = isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen';
        }

        async function requestWakeLock() {
            if (!('wakeLock' in navigator)) {
                wakeStatus.textContent = 'unavailable';
                return;
            }

            try {
                wakeLock = await navigator.wakeLock.request('screen');
                wakeStatus.textContent = 'active';

                wakeLock.addEventListener('release', () => {
                    wakeStatus.textContent = 'released';
                });
            } catch (error) {
                wakeStatus.textContent = 'failed';
            }
        }

        async function releaseWakeLock() {
            if (!wakeLock) {
                return;
            }

            try {
                await wakeLock.release();
            } catch (error) {
                // ignore
            }

            wakeLock = null;
            wakeStatus.textContent = 'released';
        }

        async function toggleFullscreen() {
            try {
                if (!document.fullscreenElement) {
                    await document.documentElement.requestFullscreen();
                    await requestWakeLock();
                } else {
                    await document.exitFullscreen();
                    await releaseWakeLock();
                }
            } catch (error) {
                fullscreenStatus.textContent = 'failed';
            }

            updateFullscreenStatus();
        }

        function showStopModal() {
            stopModal.hidden = false;
        }

        function hideStopModal() {
            stopModal.hidden = true;
        }

        function getBatchSizeForProfile(key) {
            const sizes = {
                light: 100000,
                medium: 180000,
                high: 260000
            };

            return sizes[key] || sizes.medium;
        }

        function calculateWorkerCount(maxWorkers) {
            const cores = Number(navigator.hardwareConcurrency || 2);
            const suggested = Math.max(1, cores - 1);

            return Math.max(1, Math.min(maxWorkers, suggested));
        }

        function startWorkloadScheduler() {
                    if (!workloadEnabled) {
                        workloadInitialized = true;
                        workloadStatus.textContent = 'disabled / timer only';
                        cyclesCompleted.textContent = '0';
                    return;
                    }

                    if (!window.Worker) {
                        workloadStatus.textContent = 'Web Worker unavailable';
                    return;
                    }

                    if (workloadInitialized) {
                    return;
                }

            workloadInitialized = true;

                    try {
                        createWorkloadWorkers();

                        workloadStatus.textContent = `idle (${workloadWorkerCount} workers)`;
                        workloadBurstTimer = setTimeout(startWorkloadBurst, 1500);

                    } catch (error) {
                        workloadStatus.textContent = 'worker error';
                    }
        }

        function createWorkloadWorkers() {
            for (let i = 0; i < workloadWorkerCount; i++) {
                const worker = new Worker('assets/worker.js');

                worker.postMessage({
                    command: 'init',
                    workerId: i
                });

                worker.onmessage = function (event) {
                    const data = event.data || {};

                    if (data.type !== 'cycle') {
                        return;
                    }

                    workloadCycleCounter++;
                    cyclesCompleted.textContent = workloadCycleCounter.toLocaleString();
                };

                worker.onerror = function () {
                    workloadStatus.textContent = 'worker failed';
                };

                workloadWorkers.push(worker);
            }
        }

        function startWorkloadBurst() {
            if (!testRunning) {
                return;
            }

            if (!workloadWorkers.length) {
                workloadStatus.textContent = 'no workers';
                return;
            }

            workloadBurstActive = true;
            workloadStatus.textContent = `running (${workloadWorkerCount} workers)`;

            const burstSeed = Math.floor(Date.now() % 1000000);

            for (const worker of workloadWorkers) {
                worker.postMessage({
                    command: 'start',
                    batchSize: workloadBatchSize,
                    seed: burstSeed
                });
            }

            workloadStopTimer = setTimeout(stopWorkloadBurst, workloadDurationSeconds * 1000);
        }

        function stopWorkloadBurst() {
            if (!workloadWorkers.length) {
                return;
            }

            for (const worker of workloadWorkers) {
                worker.postMessage({
                    command: 'stop'
                });
            }

            workloadBurstActive = false;

            if (!testRunning) {
                workloadStatus.textContent = 'stopped';
                return;
            }

            workloadStatus.textContent = `idle (${workloadWorkerCount} workers)`;

            const idleDelay = Math.max(
                1000,
                (workloadIntervalSeconds - workloadDurationSeconds) * 1000
            );

            workloadBurstTimer = setTimeout(startWorkloadBurst, idleDelay);
        }

        function stopWorkloadScheduler() {
            if (workloadBurstTimer) {
                clearTimeout(workloadBurstTimer);
                workloadBurstTimer = null;
            }

            if (workloadStopTimer) {
                clearTimeout(workloadStopTimer);
                workloadStopTimer = null;
            }

            for (const worker of workloadWorkers) {
                try {
                    worker.postMessage({
                        command: 'stop'
                    });

                    worker.terminate();
                } catch (error) {
                    // ignore
                }
            }

            workloadWorkers = [];
            workloadBurstActive = false;
            workloadStatus.textContent = 'stopped';
        }

        function detectBrowserInfo() {
            const ua = navigator.userAgent || '';
            let name = 'Unknown';
            let version = '';

            const patterns = [
                ['Edge', /Edg\/([\d.]+)/],
                ['Opera', /OPR\/([\d.]+)/],
                ['Firefox', /Firefox\/([\d.]+)/],
                ['Chrome', /Chrome\/([\d.]+)/],
                ['Safari', /Version\/([\d.]+).*Safari/]
            ];

            for (const [candidateName, pattern] of patterns) {
                const match = ua.match(pattern);

                if (match) {
                    name = candidateName;
                    version = match[1] || '';
                    break;
                }
            }

            return {
                name,
                version
            };
        }

        function detectOS() {
            const ua = navigator.userAgent || '';
            const platform = navigator.platform || '';

            if (/iPhone|iPad|iPod/i.test(ua)) return 'iOS';
            if (/Android/i.test(ua)) return 'Android';
            if (/Mac/i.test(platform) || /Mac OS X/i.test(ua)) return 'macOS';
            if (/Win/i.test(platform) || /Windows/i.test(ua)) return 'Windows';
            if (/Linux/i.test(platform) || /Linux/i.test(ua)) return 'Linux';

            return platform || 'Unknown OS';
        }

        function collectWebGLInfo() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');

                if (!gl) {
                    return {
                        vendor: null,
                        renderer: null
                    };
                }

                const dbg = gl.getExtension('WEBGL_debug_renderer_info');

                return {
                    vendor: dbg ? gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) : gl.getParameter(gl.VENDOR),
                    renderer: dbg ? gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER)
                };
            } catch (error) {
                return {
                    vendor: null,
                    renderer: null
                };
            }
        }

        async function collectClientInfo() {
            const browser = detectBrowserInfo();
            const battery = await collectBatterySnapshot();

            return {
                label: window.DDLAB_TEST_CONFIG.label,
                profile: profileKey,

                browser_name: browser.name,
                browser_version: browser.version,
                os_name: detectOS(),

                platform: navigator.platform || null,
                language: navigator.language || null,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || null,

                cpu_cores: navigator.hardwareConcurrency || null,
                device_memory_gb: navigator.deviceMemory || null,

                screen: {
                    width: screen.width || null,
                    height: screen.height || null,
                    avail_width: screen.availWidth || null,
                    avail_height: screen.availHeight || null,
                    pixel_ratio: window.devicePixelRatio || null
                },

                input: {
                    touch_enabled: ('ontouchstart' in window) || navigator.maxTouchPoints > 0,
                    max_touch_points: navigator.maxTouchPoints ?? null
                },

                webgl: collectWebGLInfo(),

                battery,

                worker_count: workloadWorkerCount
            };
        }

        async function apiPost(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                cache: 'no-store',
                body: JSON.stringify(payload)
            });

            const data = await response.json().catch(() => ({
                ok: false,
                error: 'Invalid JSON response'
            }));

            if (!response.ok || !data.ok) {
                throw new Error(data.error || `Request failed: ${response.status}`);
            }

            return data;
        }

        async function startServerSession() {
            if (serverSessionStarted || startSyncInProgress || !testRunning) {
                return;
            }

            startSyncInProgress = true;
            serverSync.textContent = 'starting...';

            try {
                const payload = await collectClientInfo();
                const result = await apiPost('api/start.php', payload);

                testUuid = result.test_uuid;
                heartbeatSeconds = Number(result.heartbeat_seconds || 60);
                serverSessionStarted = true;
                startSyncInProgress = false;

                if (startRetryTimer) {
                    clearTimeout(startRetryTimer);
                    startRetryTimer = null;
                }

                serverSync.textContent = 'ok';

                scheduleHeartbeat();

            } catch (error) {
                startSyncInProgress = false;
                serverSessionStarted = false;

                if (testRunning) {
                    serverSync.textContent = 'start failed, retrying...';

                    if (!startRetryTimer) {
                        startRetryTimer = setTimeout(() => {
                            startRetryTimer = null;
                            startServerSession();
                        }, 10000);
                    }
                }
            }
        }

        function scheduleHeartbeat() {
            if (heartbeatTimer) {
                clearInterval(heartbeatTimer);
            }

            /*
             * Heartbeat stays on configured interval.
             * For v1, default is 60 seconds.
             */
            heartbeatTimer = setInterval(() => {
                sendHeartbeat();
            }, heartbeatSeconds * 1000);
        }

        async function sendHeartbeat() {
            if (!testRunning || !testUuid) {
                return;
            }

            try {
                const battery = await collectBatterySnapshot();

                await apiPost('api/heartbeat.php', {
                    test_uuid: testUuid,
                    elapsed_seconds: getElapsedSeconds(),
                    workload_cycles_completed: workloadCycleCounter,
                    battery
                });

                serverSync.textContent = 'ok';

            } catch (error) {
                serverSync.textContent = 'failed, retrying...';
            }
        }

        function sendFinalHeartbeatBeacon() {
            if (!testRunning || !testUuid || !navigator.sendBeacon) {
                return;
            }

            const payload = {
                test_uuid: testUuid,
                elapsed_seconds: getElapsedSeconds(),
                workload_cycles_completed: workloadCycleCounter,
                battery: collectBatterySnapshotSync()
            };

            const blob = new Blob(
                [JSON.stringify(payload)],
                {
                    type: 'application/json'
                }
            );

            navigator.sendBeacon('api/heartbeat.php', blob);
        }

        async function sendStopToServer() {
            if (!testUuid) {
                serverSync.textContent = 'stopped locally';
                return;
            }

            try {
                const battery = await collectBatterySnapshot();

                await apiPost('api/stop.php', {
                    test_uuid: testUuid,
                    elapsed_seconds: getElapsedSeconds(),
                    workload_cycles_completed: workloadCycleCounter,
                    battery
                });

                serverSync.textContent = 'stopped / saved';

            } catch (error) {
                serverSync.textContent = 'stop save failed';
            }
        }

        async function stopTest() {
            stoppedElapsedMs = performance.now() - startTime;
            testRunning = false;

            stopWorkloadScheduler();

            if (heartbeatTimer) {
                clearInterval(heartbeatTimer);
                heartbeatTimer = null;
            }

            if (startRetryTimer) {
                clearTimeout(startRetryTimer);
                startRetryTimer = null;
            }

            hideStopModal();

            serverSync.textContent = 'stopping...';

            await sendStopToServer();
            await releaseWakeLock();

            document.body.classList.add('test-stopped');

            stopButton.hidden = true;
            backHomeButton.hidden = false;
        }

        fullscreenButton.addEventListener('click', toggleFullscreen);
        document.addEventListener('fullscreenchange', updateFullscreenStatus);

        stopButton.addEventListener('click', showStopModal);
        cancelStopButton.addEventListener('click', hideStopModal);
        confirmStopButton.addEventListener('click', stopTest);

        window.addEventListener('beforeunload', (event) => {
            if (!testRunning) {
                return;
            }

            sendFinalHeartbeatBeacon();

            event.preventDefault();
            event.returnValue = '';
        });

        window.addEventListener('pagehide', () => {
            sendFinalHeartbeatBeacon();
        });

        document.addEventListener('visibilitychange', async () => {
            if (document.visibilityState === 'hidden') {
                sendFinalHeartbeatBeacon();
                return;
            }

            if (document.visibilityState === 'visible' && testRunning && wakeLock === null) {
                if (document.fullscreenElement) {
                    await requestWakeLock();
                }
            }
        });

        updateFullscreenStatus();
        initBatteryTelemetry();
        requestAnimationFrame(renderTimer);
        startWorkloadScheduler();
        startServerSession();
    </script>

</body>
</html>

<?php
/*
|--------------------------------------------------------------------------
| Battery Stress Timer
|--------------------------------------------------------------------------
|
| Version: 1.0.0
|
| A self-hosted PHP + JavaScript battery stress timer.
| Designed for browser-based battery/runtime testing with:
|
| - fullscreen timer
| - smooth animated background
| - selectable stress profiles
| - local JavaScript/Web Worker CPU workload
| - workload cycle counter
| - battery telemetry when supported by browser
| - MySQL heartbeat logging
| - simple admin overview
|
| Recommended browsers: Chrome or Chromium.
| Target platforms: macOS, Windows, Linux.
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
$defaultProfile = $config['app']['default_profile'] ?? 'medium';
$profiles = $config['profiles'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title><?= h($appName) ?> v<?= h($appVersion) ?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--
      Battery Stress Timer v<?= h($appVersion) ?>
      Vibe code by Dalibor Klobučarić & my friend ChatGPT
    -->

    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="start-page">

    <main class="start-shell">
        <section class="start-card">

            <div class="brand-kicker">DD-Lab</div>

            <h1><?= h($appName) ?></h1>

            <p class="lead">
                Browser-based battery stress timer with animated display load,
                local JavaScript workload and MySQL heartbeat logging.
            </p>

            <form class="start-form" action="test.php" method="get">

                <label for="label">Test label</label>
                <input
                    type="text"
                    id="label"
                    name="label"
                    maxlength="255"
                    placeholder="Auto-detected label will appear here; you can edit it"
                    autocomplete="off"
                >

                <p class="field-hint" id="detectedHint">
                    Detecting browser and device…
                </p>

                <label for="profile">Stress profile</label>
                <select id="profile" name="profile">
                    <?php foreach ($profiles as $key => $profile): ?>
                        <option
                            value="<?= h($key) ?>"
                            data-description="<?= h($profile['description'] ?? '') ?>"
                            <?= $key === $defaultProfile ? 'selected' : '' ?>
                        >
                            <?= h($profile['label'] ?? ucfirst($key)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <p class="profile-description" id="profileDescription">
                    <?= h($profiles[$defaultProfile]['description'] ?? '') ?>
                </p>

                <div class="profile-list">
                    <?php foreach ($profiles as $key => $profile): ?>
                        <div class="profile-row">
                            <strong><?= h($profile['label'] ?? ucfirst($key)) ?></strong>
                            <span><?= h($profile['description'] ?? '') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="start-button">
                    START TEST
                </button>

            </form>

            <div class="recommendations">
                <div class="recommend-title">Recommended test conditions</div>

                <div class="recommend-grid">
                    <span>Chrome or Chromium</span>
                    <span>Battery on 100%</span>
                    <span>Fullscreen</span>
                    <span>Same brightness</span>
                    <span>Unplugged</span>
                    <span>Close other tabs</span>
                    <span>Close other apps</span>
                </div>
            </div>

            <p class="warning">
                This test intentionally stresses the browser and may drain your battery.
            </p>

            <footer class="footer">
                <?= h($appName) ?> · v<?= h($appVersion) ?><br>
                Vibe code by Dalibor Klobučarić & my friend ChatGPT
            </footer>

        </section>
    </main>

    <script>
        const profileSelect = document.getElementById('profile');
        const profileDescription = document.getElementById('profileDescription');
        const labelInput = document.getElementById('label');
        const detectedHint = document.getElementById('detectedHint');

        function updateProfileDescription() {
            const selected = profileSelect.options[profileSelect.selectedIndex];
            profileDescription.textContent = selected.dataset.description || '';
        }

        profileSelect.addEventListener('change', updateProfileDescription);
        updateProfileDescription();

        function detectBrowser() {
            const ua = navigator.userAgent || '';

            if (ua.includes('Edg/')) return 'Edge';
            if (ua.includes('OPR/') || ua.includes('Opera')) return 'Opera';
            if (ua.includes('Firefox/')) return 'Firefox';
            if (ua.includes('Chrome/') && !ua.includes('Edg/')) return 'Chrome';
            if (ua.includes('Safari/') && !ua.includes('Chrome/')) return 'Safari';

            return 'Unknown browser';
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

        function formatMemoryHint() {
            if (!navigator.deviceMemory) {
                return 'RAM n/a';
            }

            return `${navigator.deviceMemory} GB RAM`;
        }

        function formatCpuHint() {
            if (!navigator.hardwareConcurrency) {
                return 'cores n/a';
            }

            return `${navigator.hardwareConcurrency} cores`;
        }

        async function detectClientLabel() {
            let os = detectOS();
            let browser = detectBrowser();

            /*
             * Prefer User-Agent Client Hints where available.
             * Fallback remains navigator.userAgent / navigator.platform.
             */
            if (navigator.userAgentData) {
                try {
                    os = navigator.userAgentData.platform || os;

                    const brands = navigator.userAgentData.brands || [];
                    const brandNames = brands.map(item => item.brand).join(' ');

                    if (brandNames.includes('Google Chrome')) {
                        browser = 'Chrome';
                    } else if (brandNames.includes('Microsoft Edge')) {
                        browser = 'Edge';
                    } else if (brandNames.includes('Chromium')) {
                        browser = 'Chromium';
                    }
                } catch (e) {
                    // Keep fallback values.
                }
            }

            const screenText = `${screen.width}x${screen.height}`;
            const cpuText = formatCpuHint();
            const ramText = formatMemoryHint();

            const autoLabel = `${os} - ${browser} - ${screenText} - brightness: manual`;

            /*
             * Auto-fill only when the user did not type anything yet.
             * User can freely edit the label before starting the test.
             */
            if (!labelInput.value.trim()) {
                labelInput.value = autoLabel;
            }

            detectedHint.textContent =
                `Detected: ${os}, ${browser}, ${screenText}, ${cpuText}, ${ramText}. ` +
                `Brightness cannot be read by the browser, so keep it manual in the label.`;
        }

        detectClientLabel();
    </script>

</body>
</html>

<?php
/*
|--------------------------------------------------------------------------
| Battery Stress Timer - Admin
|--------------------------------------------------------------------------
|
| Version: 1.0.0
|
| Simple admin overview for recorded battery tests.
|
| - config fallback login
| - database admin_users login
| - admin password change
| - latest tests first
| - simple white table layout
| - detailed test view
| - running / stopped / probably lost status detection
| - MySQL-side heartbeat age calculation
|
| Vibe code by Dalibor Klobučarić & my friend ChatGPT
|
|--------------------------------------------------------------------------
*/

session_start();

require __DIR__ . '/db.php';

$config = app_config();

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token(?string $token): bool
{
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function format_duration(?int $seconds): string
{
    $seconds = max(0, (int)$seconds);

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%dh %02dm %02ds', $hours, $minutes, $secs);
    }

    if ($minutes > 0) {
        return sprintf('%dm %02ds', $minutes, $secs);
    }

    return sprintf('%ds', $secs);
}

function format_number($value): string
{
    if ($value === null || $value === '') {
        return 'n/a';
    }

    return number_format((float)$value, 0, ',', '.');
}

function format_percent($value): string
{
    if ($value === null || $value === '') {
        return 'n/a';
    }

    return (int)$value . '%';
}

function format_age($seconds): string
{
    if ($seconds === null || $seconds === '') {
        return 'n/a';
    }

    $seconds = max(0, (int)$seconds);

    if ($seconds < 60) {
        return $seconds . 's';
    }

    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm ' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    return sprintf('%dh %02dm %02ds', $hours, $minutes, $secs);
}

function calculated_status(array $row, int $heartbeatSeconds): string
{
    $status = (string)($row['status'] ?? '');

    if ($status !== 'running') {
        return $status;
    }

    if (empty($row['last_seen_at'])) {
        return 'running';
    }

    $secondsSinceLastSeen = isset($row['seconds_since_last_seen'])
        ? (int)$row['seconds_since_last_seen']
        : 0;

    $lostAfter = max(120, $heartbeatSeconds * 2);

    if ($secondsSinceLastSeen > $lostAfter) {
        return 'probably lost';
    }

    return 'running';
}

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['ddlab_timer_admin']);
}

function current_admin_mode(): string
{
    return (string)($_SESSION['ddlab_timer_admin_mode'] ?? '');
}

function current_admin_username(): string
{
    return (string)($_SESSION['ddlab_timer_admin_username'] ?? 'admin');
}

$adminConfig = $config['admin'] ?? [];
$fallbackEnabled = !empty($adminConfig['fallback_enabled']);

$appName = $config['app']['name'] ?? 'Battery Stress Timer';
$appVersion = $config['app']['version'] ?? '1.0.0';
$heartbeatSeconds = (int)($config['app']['heartbeat_seconds'] ?? 60);

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$loginError = '';
$passwordError = '';
$passwordMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf_token($csrfToken)) {
        $loginError = 'Invalid request. Please try again.';
    } else {
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');

        if ($fallbackEnabled) {
            $fallbackUser = (string)($adminConfig['fallback_user'] ?? 'admin');
            $fallbackPass = (string)($adminConfig['fallback_pass'] ?? 'admin');

            $validUser = hash_equals($fallbackUser, $user);
            $validPass = hash_equals($fallbackPass, $pass);

            if ($validUser && $validPass) {
                session_regenerate_id(true);
                $_SESSION['ddlab_timer_admin'] = true;
                $_SESSION['ddlab_timer_admin_mode'] = 'fallback';
                $_SESSION['ddlab_timer_admin_username'] = $fallbackUser;
                $_SESSION['ddlab_timer_admin_user_id'] = null;

                header('Location: admin.php');
                exit;
            }

            $loginError = 'Invalid username or password.';
        } else {
            try {
                $pdo = db();

                $stmt = $pdo->prepare('
                    SELECT id, username, password_hash, is_active
                    FROM admin_users
                    WHERE username = :username
                    LIMIT 1
                ');

                $stmt->execute([
                    ':username' => $user,
                ]);

                $adminUser = $stmt->fetch();

                if (
                    $adminUser
                    && (int)$adminUser['is_active'] === 1
                    && password_verify($pass, (string)$adminUser['password_hash'])
                ) {
                    session_regenerate_id(true);
                    $_SESSION['ddlab_timer_admin'] = true;
                    $_SESSION['ddlab_timer_admin_mode'] = 'db';
                    $_SESSION['ddlab_timer_admin_username'] = (string)$adminUser['username'];
                    $_SESSION['ddlab_timer_admin_user_id'] = (int)$adminUser['id'];

                    $update = $pdo->prepare('
                        UPDATE admin_users
                        SET last_login_at = NOW()
                        WHERE id = :id
                        LIMIT 1
                    ');

                    $update->execute([
                        ':id' => (int)$adminUser['id'],
                    ]);

                    header('Location: admin.php');
                    exit;
                }

                $loginError = 'Invalid username or password.';
            } catch (Throwable $e) {
                $loginError = 'Login failed. Database admin table is not available.';
            }
        }
    }
}

$isLoggedIn = is_admin_logged_in();

if (
    $isLoggedIn
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['change_password'])
) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf_token($csrfToken)) {
        $passwordError = 'Invalid request. Please try again.';
    } elseif (current_admin_mode() !== 'db') {
        $passwordError = 'Password change is available only when database admin login is enabled.';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newPasswordRepeat = (string)($_POST['new_password_repeat'] ?? '');
        $adminUserId = (int)($_SESSION['ddlab_timer_admin_user_id'] ?? 0);

        if ($adminUserId < 1) {
            $passwordError = 'Invalid admin session.';
        } elseif ($newPassword !== $newPasswordRepeat) {
            $passwordError = 'New passwords do not match.';
        } elseif (strlen($newPassword) < 8) {
            $passwordError = 'New password must be at least 8 characters long.';
        } else {
            try {
                $pdo = db();

                $stmt = $pdo->prepare('
                    SELECT id, username, password_hash, is_active
                    FROM admin_users
                    WHERE id = :id
                    LIMIT 1
                ');

                $stmt->execute([
                    ':id' => $adminUserId,
                ]);

                $adminUser = $stmt->fetch();

                if (!$adminUser || (int)$adminUser['is_active'] !== 1) {
                    $passwordError = 'Admin user not found or inactive.';
                } elseif (!password_verify($currentPassword, (string)$adminUser['password_hash'])) {
                    $passwordError = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, [
                        'cost' => 12,
                    ]);

                    $update = $pdo->prepare('
                        UPDATE admin_users
                        SET password_hash = :password_hash
                        WHERE id = :id
                        LIMIT 1
                    ');

                    $update->execute([
                        ':password_hash' => $newHash,
                        ':id' => $adminUserId,
                    ]);

                    $passwordMessage = 'Password changed successfully.';
                }
            } catch (Throwable $e) {
                $passwordError = 'Password change failed.';
            }
        }
    }
}

$pdo = null;
$tests = [];
$selectedTest = null;

if ($isLoggedIn) {
    $pdo = db();

    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $stmt = $pdo->prepare('
            SELECT
                battery_tests.*,
                TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seconds_since_last_seen
            FROM battery_tests
            WHERE id = :id
            LIMIT 1
        ');

        $stmt->execute([
            ':id' => (int)$_GET['id'],
        ]);

        $selectedTest = $stmt->fetch() ?: null;
    }

    $stmt = $pdo->query('
        SELECT
            battery_tests.*,
            TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seconds_since_last_seen
        FROM battery_tests
        ORDER BY started_at DESC, id DESC
        LIMIT 100
    ');

    $tests = $stmt->fetchAll();
}

$showPasswordForm = $isLoggedIn && isset($_GET['change_password']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= h($appName) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--
      DD-Lab Battery Stress Timer Admin v<?= h($appVersion) ?>
      Vibe code by Dalibor Klobučarić & my friend ChatGPT
    -->

    <style>
        :root {
            color-scheme: light;
            --bg: #f3f3f3;
            --card: #ffffff;
            --text: #111111;
            --muted: #666666;
            --border: #dddddd;
            --soft: #f7f7f7;
            --black: #000000;
            --green: #1ca75b;
            --red: #d93636;
            --yellow: #9a6a00;
            --blue: #1c67d2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family:
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        a {
            color: inherit;
        }

        .admin-shell {
            width: min(1470px, 100%);
            margin: 0 auto;
            padding: 24px;
        }

        .admin-card,
        .small-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.06);
        }

        .small-card {
            margin-bottom: 24px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 22px;
        }

        .kicker {
            font-family:
                ui-monospace,
                SFMono-Regular,
                Menlo,
                Monaco,
                Consolas,
                "Liberation Mono",
                "Courier New",
                monospace;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 0.76rem;
            color: var(--muted);
            margin-bottom: 8px;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.8rem, 4vw, 3rem);
            letter-spacing: -0.05em;
            line-height: 1;
        }

        h2 {
            margin: 0 0 14px;
            font-size: 1.4rem;
            letter-spacing: -0.03em;
        }

        .admin-sub {
            margin: 10px 0 0;
            color: var(--muted);
        }

        .admin-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn,
        button {
            border: 0;
            border-radius: 999px;
            padding: 10px 14px;
            background: #000;
            color: #fff;
            text-decoration: none;
            font-weight: 800;
            cursor: pointer;
            font-family:
                ui-monospace,
                SFMono-Regular,
                Menlo,
                Monaco,
                Consolas,
                "Liberation Mono",
                "Courier New",
                monospace;
            font-size: 0.84rem;
        }

        .btn.secondary {
            background: #fff;
            color: #000;
            border: 1px solid #000;
        }

        .btn.danger {
            background: var(--red);
        }

        .btn.blue {
            background: var(--blue);
        }

        .login-wrap {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
        }

        .login-card {
            width: min(430px, 100%);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 26px;
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.08);
        }

        .login-card h1 {
            font-size: 2rem;
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: 800;
            font-size: 0.9rem;
        }

        input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px;
            font: inherit;
        }

        input:focus {
            outline: none;
            border-color: #000;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.08);
        }

        .error {
            margin: 12px 0 0;
            color: var(--red);
            font-weight: 700;
        }

        .success {
            margin: 12px 0 0;
            color: var(--green);
            font-weight: 800;
        }

        .notice {
            margin: 12px 0 0;
            color: var(--muted);
            line-height: 1.45;
        }

        .password-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: end;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1180px;
            background: #fff;
        }

        th,
        td {
            padding: 11px 12px;
            border-bottom: 1px solid #eeeeee;
            text-align: left;
            vertical-align: top;
            font-size: 0.88rem;
        }

        th {
            background: #fafafa;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #555;
            white-space: nowrap;
        }

        tr:nth-child(even) td {
            background: #fcfcfc;
        }

        tr:hover td {
            background: #f5f5f5;
        }

        .mono {
            font-family:
                ui-monospace,
                SFMono-Regular,
                Menlo,
                Monaco,
                Consolas,
                "Liberation Mono",
                "Courier New",
                monospace;
            font-variant-numeric: tabular-nums;
            font-feature-settings: "zero" 1;
        }

        .muted {
            color: var(--muted);
        }

        .status {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 0.76rem;
            font-weight: 900;
            background: #eeeeee;
        }

        .status.running {
            background: #e8f8ef;
            color: var(--green);
        }

        .status.stopped,
        .status.finished {
            background: #eeeeee;
            color: #333333;
        }

        .status.lost {
            background: #fff2d7;
            color: var(--yellow);
        }

        .label-cell {
            max-width: 260px;
        }

        .label-cell strong {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .detail-card {
            margin-bottom: 24px;
            border: 1px solid var(--border);
            border-radius: 20px;
            background: #fff;
            overflow: hidden;
        }

        .detail-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding: 18px;
            background: #fafafa;
            border-bottom: 1px solid var(--border);
        }

        .detail-head h2 {
            margin: 0;
            font-size: 1.3rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0;
        }

        .detail-item {
            padding: 14px 16px;
            border-right: 1px solid #eeeeee;
            border-bottom: 1px solid #eeeeee;
        }

        .detail-item:nth-child(4n) {
            border-right: 0;
        }

        .detail-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-bottom: 6px;
            font-weight: 800;
        }

        .detail-value {
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .ua-box {
            padding: 16px;
            background: #111;
            color: #fff;
            font-size: 0.82rem;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        @media (max-width: 900px) {
            .admin-header {
                flex-direction: column;
            }

            .admin-actions {
                justify-content: flex-start;
            }

            .detail-grid,
            .password-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .detail-item:nth-child(4n) {
                border-right: 1px solid #eeeeee;
            }

            .detail-item:nth-child(2n) {
                border-right: 0;
            }
        }

        @media (max-width: 560px) {
            .admin-shell {
                padding: 12px;
            }

            .admin-card,
            .small-card {
                padding: 18px;
                border-radius: 20px;
            }

            .detail-grid,
            .password-grid {
                grid-template-columns: 1fr;
            }

            .detail-item,
            .detail-item:nth-child(2n),
            .detail-item:nth-child(4n) {
                border-right: 0;
            }
        }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>

    <main class="login-wrap">
        <section class="login-card">
            <div class="kicker">DD-LAB</div>
            <h1>Admin login</h1>

            <form method="post" action="admin.php">
                <input type="hidden" name="admin_login" value="1">
                <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">

                <label for="user">Username</label>
                <input type="text" id="user" name="user" value="admin" autocomplete="username">

                <label for="pass">Password</label>
                <input type="password" id="pass" name="pass" autocomplete="current-password">

                <?php if ($loginError): ?>
                    <p class="error"><?= h($loginError) ?></p>
                <?php endif; ?>

                <p style="margin-top:18px;">
                    <button type="submit">Login</button>
                </p>
            </form>

            <p class="muted mono" style="margin-top:20px;font-size:.8rem;">
                Login mode: <?= $fallbackEnabled ? 'config fallback' : 'database admin_users' ?><br>
                <?= h($appName) ?> · v<?= h($appVersion) ?><br>
                Vibe code by Dalibor Klobučarić & my friend ChatGPT
            </p>
        </section>
    </main>

<?php else: ?>

    <main class="admin-shell">
        <section class="admin-card">

            <header class="admin-header">
                <div>
                    <div class="kicker">DD-LAB Admin</div>
                    <h1>Battery tests</h1>
                    <p class="admin-sub">
                        Latest tests first. Showing last <?= count($tests) ?> test(s).
                        Logged in as <strong><?= h(current_admin_username()) ?></strong>
                        via <span class="mono"><?= h(current_admin_mode()) ?></span>.
                    </p>
                </div>

                <div class="admin-actions">
                    <a class="btn secondary" href="index.php" target="_blank">← Start page</a>
                    <a class="btn secondary" href="admin.php">Refresh</a>
                    <a class="btn blue" href="admin.php?change_password=1">Change password</a>
                    <a class="btn danger" href="admin.php?logout=1">Logout</a>
                </div>
            </header>

            <?php if ($showPasswordForm): ?>
                <section class="small-card">
                    <h2>Change admin password</h2>

                    <?php if (current_admin_mode() === 'fallback'): ?>
                        <p class="notice">
                            Password change is disabled in fallback mode.
                            Set <span class="mono">fallback_enabled</span> to <span class="mono">false</span>
                            and use the <span class="mono">admin_users</span> table for database-backed admin login.
                        </p>
                    <?php else: ?>
                        <form method="post" action="admin.php?change_password=1">
                            <input type="hidden" name="change_password" value="1">
                            <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">

                            <div class="password-grid">
                                <div>
                                    <label for="current_password">Current password</label>
                                    <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                                </div>

                                <div>
                                    <label for="new_password">New password</label>
                                    <input type="password" id="new_password" name="new_password" autocomplete="new-password">
                                </div>

                                <div>
                                    <label for="new_password_repeat">Repeat new password</label>
                                    <input type="password" id="new_password_repeat" name="new_password_repeat" autocomplete="new-password">
                                </div>
                            </div>

                            <?php if ($passwordError): ?>
                                <p class="error"><?= h($passwordError) ?></p>
                            <?php endif; ?>

                            <?php if ($passwordMessage): ?>
                                <p class="success"><?= h($passwordMessage) ?></p>
                            <?php endif; ?>

                            <p style="margin-top:18px;">
                                <button type="submit">Save new password</button>
                                <a class="btn secondary" href="admin.php">Cancel</a>
                            </p>
                        </form>
                    <?php endif; ?>
                </section>
            <?php elseif ($passwordMessage || $passwordError): ?>
                <section class="small-card">
                    <?php if ($passwordError): ?>
                        <p class="error"><?= h($passwordError) ?></p>
                    <?php endif; ?>

                    <?php if ($passwordMessage): ?>
                        <p class="success"><?= h($passwordMessage) ?></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($selectedTest): ?>
                <?php
                    $displayStatus = calculated_status($selectedTest, $heartbeatSeconds);
                    $statusClass = str_contains($displayStatus, 'lost') ? 'lost' : $displayStatus;

                    $batteryStart = $selectedTest['battery_start_percent'];
                    $batteryLast = $selectedTest['battery_last_percent'];
                    $batteryUsed = null;

                    if ($batteryStart !== null && $batteryLast !== null) {
                        $batteryUsed = max(0, (int)$batteryStart - (int)$batteryLast);
                    }
                ?>

                <section class="detail-card">
                    <div class="detail-head">
                        <div>
                            <h2>Test #<?= h($selectedTest['id']) ?></h2>
                            <div class="muted"><?= h($selectedTest['label']) ?></div>
                        </div>

                        <div>
                            <span class="status <?= h($statusClass) ?>">
                                <?= h($displayStatus) ?>
                            </span>
                        </div>
                    </div>

                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Started</div>
                            <div class="detail-value mono"><?= h($selectedTest['started_at']) ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Last seen</div>
                            <div class="detail-value mono"><?= h($selectedTest['last_seen_at'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Last heartbeat age</div>
                            <div class="detail-value mono"><?= h(format_age($selectedTest['seconds_since_last_seen'] ?? null)) ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Ended</div>
                            <div class="detail-value mono"><?= h($selectedTest['ended_at'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Duration</div>
                            <div class="detail-value mono"><?= h(format_duration((int)$selectedTest['elapsed_seconds'])) ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Battery</div>
                            <div class="detail-value mono">
                                <?= h(format_percent($batteryStart)) ?> → <?= h(format_percent($batteryLast)) ?>
                            </div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Battery used</div>
                            <div class="detail-value mono">
                                <?= $batteryUsed === null ? 'n/a' : h($batteryUsed . '%') ?>
                            </div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Cycles</div>
                            <div class="detail-value mono"><?= h(format_number($selectedTest['workload_cycles_completed'])) ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Profile</div>
                            <div class="detail-value mono"><?= h($selectedTest['stress_profile']) ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Workers</div>
                            <div class="detail-value mono"><?= h($selectedTest['worker_count'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Workload timing</div>
                            <div class="detail-value mono">
                                <?= h($selectedTest['workload_duration_seconds'] ?? 'n/a') ?>s /
                                <?= h($selectedTest['workload_interval_seconds'] ?? 'n/a') ?>s
                            </div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">IP</div>
                            <div class="detail-value mono"><?= h($selectedTest['client_ip'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Browser</div>
                            <div class="detail-value mono">
                                <?= h($selectedTest['browser_name'] ?? 'n/a') ?>
                                <?= h($selectedTest['browser_version'] ?? '') ?>
                            </div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">OS</div>
                            <div class="detail-value mono"><?= h($selectedTest['os_name'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Platform</div>
                            <div class="detail-value mono"><?= h($selectedTest['platform'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">CPU / RAM</div>
                            <div class="detail-value mono">
                                <?= h($selectedTest['cpu_cores'] ?? 'n/a') ?> cores /
                                <?= h($selectedTest['device_memory_gb'] ?? 'n/a') ?> GB
                            </div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Screen</div>
                            <div class="detail-value mono">
                                <?= h($selectedTest['screen_width'] ?? 'n/a') ?>x<?= h($selectedTest['screen_height'] ?? 'n/a') ?>
                                @ <?= h($selectedTest['pixel_ratio'] ?? 'n/a') ?>
                            </div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Language</div>
                            <div class="detail-value mono"><?= h($selectedTest['language'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">Timezone</div>
                            <div class="detail-value mono"><?= h($selectedTest['timezone'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">WebGL vendor</div>
                            <div class="detail-value mono"><?= h($selectedTest['webgl_vendor'] ?? 'n/a') ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">WebGL renderer</div>
                            <div class="detail-value mono"><?= h($selectedTest['webgl_renderer'] ?? 'n/a') ?></div>
                        </div>
                    </div>

                    <div class="ua-box mono">
                        <strong>User-Agent:</strong><br>
                        <?= h($selectedTest['user_agent'] ?? 'n/a') ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Label</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Last seen</th>
                            <th>Age</th>
                            <th>Duration</th>
                            <th>Battery</th>
                            <th>Cycles</th>
                            <th>Profile</th>
                            <th>IP</th>
                            <th>Browser</th>
                            <th>OS</th>
                            <th>View</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$tests): ?>
                            <tr>
                                <td colspan="14" class="muted">No tests yet.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($tests as $row): ?>
                            <?php
                                $displayStatus = calculated_status($row, $heartbeatSeconds);
                                $statusClass = str_contains($displayStatus, 'lost') ? 'lost' : $displayStatus;

                                $batteryText = format_percent($row['battery_start_percent'])
                                    . ' → '
                                    . format_percent($row['battery_last_percent']);
                            ?>

                            <tr>
                                <td class="mono"><?= h($row['id']) ?></td>

                                <td class="label-cell">
                                    <strong title="<?= h($row['label']) ?>">
                                        <?= h($row['label']) ?>
                                    </strong>
                                    <span class="muted mono"><?= h($row['test_uuid']) ?></span>
                                </td>

                                <td>
                                    <span class="status <?= h($statusClass) ?>">
                                        <?= h($displayStatus) ?>
                                    </span>
                                </td>

                                <td class="mono"><?= h($row['started_at']) ?></td>
                                <td class="mono"><?= h($row['last_seen_at'] ?? 'n/a') ?></td>
                                <td class="mono"><?= h(format_age($row['seconds_since_last_seen'] ?? null)) ?></td>
                                <td class="mono"><?= h(format_duration((int)$row['elapsed_seconds'])) ?></td>
                                <td class="mono"><?= h($batteryText) ?></td>
                                <td class="mono"><?= h(format_number($row['workload_cycles_completed'])) ?></td>
                                <td class="mono"><?= h($row['stress_profile']) ?></td>
                                <td class="mono"><?= h($row['client_ip'] ?? 'n/a') ?></td>
                                <td class="mono">
                                    <?= h($row['browser_name'] ?? 'n/a') ?>
                                    <?= h($row['browser_version'] ?? '') ?>
                                </td>
                                <td class="mono"><?= h($row['os_name'] ?? 'n/a') ?></td>

                                <td>
                                    <a class="btn secondary" href="admin.php?id=<?= h($row['id']) ?>">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </section>
    </main>

<?php endif; ?>

</body>
</html>

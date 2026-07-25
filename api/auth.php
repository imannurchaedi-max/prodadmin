<?php
declare(strict_types=1);

// Blok semua output PHP (warning/notice) agar tidak merusak JSON
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// -- Helpers -----------------------------------------------------------------

function ok(mixed $data): void {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function fail(string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function param(string $key, mixed $default = null): mixed {
    // Query string > POST form > JSON body (cached in $GLOBALS)
    if (isset($_GET[$key]))  return $_GET[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (!isset($GLOBALS['_BODY'])) $GLOBALS['_BODY'] = body();
    return $GLOBALS['_BODY'][$key] ?? $default;
}

// getBearerToken() tersedia dari auth_helper.php (Authorization: Bearer header only, no URL params)

function getAdminPin(): string {
    $db   = getDb();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'ADMIN_PIN'");
    $stmt->execute();
    $row = $stmt->fetch();
    return $row['value'] ?? '';
}

// Returns ['isValid'=>bool, 'username'=>string, 'role'=>string]
function validateToken(string $token): array {
    if ($token === '') return ['isValid' => false, 'username' => '', 'role' => ''];

    $db   = getDb();
    $stmt = $db->prepare(
        "SELECT s.username, u.role
         FROM sessions s
         JOIN users u ON u.username = s.username
         WHERE s.token = :token AND s.expires_at > NOW()"
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    if (!$row) return ['isValid' => false, 'username' => '', 'role' => ''];
    return ['isValid' => true, 'username' => $row['username'], 'role' => $row['role']];
}

function generateToken(): string {
    return bin2hex(random_bytes(32)); // 64-char hex, cryptographically secure
}

function createSession(PDO $db, string $username, string $machineId): string {
    // Pembersihan rutin (Garbage Collection) agar tabel tidak membengkak (OOM/Bloating)
    try {
        $db->exec("DELETE FROM sessions WHERE expires_at <= NOW()");
        $db->exec("DELETE FROM takeover_requests WHERE created_at < NOW() - INTERVAL '1 day'");
    } catch (Throwable $e) {}

    $token = generateToken();
    $stmt = $db->prepare(
        "INSERT INTO sessions (token, username, machine_id, expires_at)
         VALUES (:token, :username, :machine_id, NOW() + make_interval(secs => :ttl))"
    );
    $stmt->execute([
        ':token'      => $token,
        ':username'   => $username,
        ':machine_id' => $machineId,
        ':ttl'        => SESSION_TTL_SEC,
    ]);
    return $token;
}

function deleteSession(PDO $db, string $username, string $machineId): void {
    $stmt = $db->prepare(
        "DELETE FROM sessions WHERE username = :u AND machine_id = :m"
    );
    $stmt->execute([':u' => $username, ':m' => $machineId]);
}

function getActiveSession(PDO $db, string $username, string $machineId): ?array {
    $stmt = $db->prepare(
        "SELECT token FROM sessions
         WHERE username = :u AND machine_id = :m AND expires_at > NOW()"
    );
    $stmt->execute([':u' => $username, ':m' => $machineId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getPendingTakeover(PDO $db, string $username, string $machineId): ?array {
    $stmt = $db->prepare(
        "SELECT id, requester_token, created_at, timeout_count
         FROM takeover_requests
         WHERE username = :u AND machine_id = :m AND status = 'PENDING'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':u' => $username, ':m' => $machineId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function clearTakeoverHistory(PDO $db, string $username, string $machineId): void {
    $stmt = $db->prepare(
        "DELETE FROM takeover_requests WHERE username = :u AND machine_id = :m"
    );
    $stmt->execute([':u' => $username, ':m' => $machineId]);
}

function clearTakeoverRequests(PDO $db, string $username, string $machineId): void {
    $stmt = $db->prepare(
        "DELETE FROM takeover_requests
         WHERE username = :u AND machine_id = :m AND status <> 'EXPIRED'"
    );
    $stmt->execute([':u' => $username, ':m' => $machineId]);
}

function getTakeoverTimeoutCount(PDO $db, string $username, string $machineId): int {
    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(timeout_count), 0)
         FROM takeover_requests
         WHERE username = :u AND machine_id = :m AND status = 'EXPIRED'"
    );
    $stmt->execute([':u' => $username, ':m' => $machineId]);
    return (int) ($stmt->fetchColumn() ?? 0);
}

function promoteTakeover(PDO $db, string $username, string $machineId, string $token): void {
    // Invalidate old token from APCu cache before replacing
    $oldStmt = $db->prepare("SELECT token FROM sessions WHERE username = :u AND machine_id = :m");
    $oldStmt->execute([':u' => $username, ':m' => $machineId]);
    $oldRow = $oldStmt->fetch();
    if ($oldRow) {
        invalidateSessionCache($oldRow['token']);
    }

    $stmt = $db->prepare(
        "UPDATE sessions
         SET token = :t, expires_at = NOW() + make_interval(secs => :ttl), updated_at = NOW()
         WHERE username = :u AND machine_id = :m"
    );
    $stmt->execute([
        ':t'   => $token,
        ':ttl' => SESSION_TTL_SEC,
        ':u'   => $username,
        ':m'   => $machineId,
    ]);
}

// -- Actions ------------------------------------------------------------------

function actionLogin(): void {
    $username = trim((string) param('username', ''));
    $password = (string) param('password', '');
    $machine  = trim((string) param('machine', ''));
    $force    = filter_var(param('force', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($username === '' || $password === '' || $machine === '') {
        fail('username, password, dan machine wajib diisi.');
    }

    // --- Rate limiting (requires APCu) ---
    $rlKey = null;
    if (function_exists('apcu_fetch')) {
        $rlKey     = 'ratelimit_login_' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $username);
        $rlAttempts = (int)(apcu_fetch($rlKey) ?: 0);
        if ($rlAttempts >= 10) {
            fail('Terlalu banyak percobaan login. Coba lagi dalam 5 menit.', 429);
        }
        apcu_store($rlKey, $rlAttempts + 1, 300); // 5 minute window
    }

    $db = getDb();

    // 1. Cek user & password
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        fail('Password salah atau User tidak ditemukan.');
    }

    // Reset rate limit counter on successful credential check
    if ($rlKey !== null && function_exists('apcu_delete')) {
        apcu_delete($rlKey);
    }

    // 2. Cek sesi aktif di mesin ini (siapapun user-nya)
    $stmtMachine = $db->prepare(
        "SELECT token, username FROM sessions WHERE machine_id = :m AND expires_at > NOW() LIMIT 1"
    );
    $stmtMachine->execute([':m' => $machine]);
    $anyActive = $stmtMachine->fetch();

    // Beda user di mesin yang sama: tampilkan konfirmasi ambil alih di frontend
    if ($anyActive && $anyActive['username'] !== $username && !$force) {
        echo json_encode([
            'success'    => false,
            'status'     => 'MACHINE_IN_USE',
            'activeUser' => $anyActive['username'],
            'message'    => "Mesin {$machine} sedang digunakan oleh {$anyActive['username']}.",
        ]);
        exit;
    }

    // Sesi user yang sama di mesin ini: takeover flow normal
    $active = ($anyActive && $anyActive['username'] === $username) ? $anyActive : null;

    if ($active && !$force) {
        $timeoutCount = getTakeoverTimeoutCount($db, $username, $machine);
        $nextAttempt  = $timeoutCount + 1;

        if ($timeoutCount >= 2) {
            echo json_encode([
                'success' => false,
                'status'  => 'FORCE_TAKEOVER_PROMPT'
            ]);
            exit;
        }

        // Ada sesi aktif -> buat takeover request
        $tempToken = generateToken();
        clearTakeoverRequests($db, $username, $machine);

        $db->prepare(
            "INSERT INTO takeover_requests (username, machine_id, requester_token, status)
             VALUES (:u, :m, :token, 'PENDING')"
        )->execute([':u' => $username, ':m' => $machine, ':token' => $tempToken]);

        echo json_encode([
            'success'   => false,
            'status'    => 'WAITING_APPROVAL',
            'tempToken' => $tempToken,
            'attempts'  => $nextAttempt,
            'message'   => "User aktif di {$machine}. Menunggu izin...",
        ]);
        exit;
    }

    // 3. Login bersih -> buat sesi baru (ATOMIC: cegah race condition)
    $db->beginTransaction();
    try {
        // Ambil token + username lama untuk invalidate cache dan bersihkan history
        $oldStmt = $db->prepare("SELECT token, username FROM sessions WHERE machine_id = :m FOR UPDATE");
        $oldStmt->execute([':m' => $machine]);
        $oldRow = $oldStmt->fetch();
        if ($oldRow) {
            invalidateSessionCache($oldRow['token']);
            // Bersihkan takeover history user lama jika beda user
            if ($oldRow['username'] !== $username) {
                clearTakeoverHistory($db, $oldRow['username'], $machine);
            }
        }

        clearTakeoverHistory($db, $username, $machine);
        $db->prepare("DELETE FROM sessions WHERE machine_id = :m")->execute([':m' => $machine]);
        $token = createSession($db, $username, $machine);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Gagal membuat sesi. Coba lagi.');
    }

    ok(['token' => $token, 'username' => $username, 'takeoverDirect' => $force]);
}

function actionLogout(): void {
    $machine = trim((string) param('machine', ''));

    if ($machine !== '') {
        $db = getDb();

        // Invalidate APCu cache for the session being destroyed
        $oldStmt = $db->prepare("SELECT token FROM sessions WHERE machine_id = :m");
        $oldStmt->execute([':m' => $machine]);
        while ($row = $oldStmt->fetch()) {
            invalidateSessionCache($row['token']);
        }

        $db->prepare("DELETE FROM sessions WHERE machine_id = :m")->execute([':m' => $machine]);
        $db->prepare("DELETE FROM takeover_requests WHERE machine_id = :m")->execute([':m' => $machine]);
    }
    ok(true);
}

function actionForceLogin(): void {
    $pin      = (string) param('pin', '');
    $username = trim((string) param('username', ''));
    $machine  = trim((string) param('machine', ''));

    if ($username === '' || $machine === '') fail('username dan machine wajib diisi.');
    if ($pin !== getAdminPin()) fail('PIN admin salah.', 403);

    $db = getDb();

    // Invalidate old session cache before replacing
    $oldStmt = $db->prepare("SELECT token FROM sessions WHERE username = :u AND machine_id = :m");
    $oldStmt->execute([':u' => $username, ':m' => $machine]);
    $oldRow = $oldStmt->fetch();
    if ($oldRow) {
        invalidateSessionCache($oldRow['token']);
    }

    deleteSession($db, $username, $machine);
    clearTakeoverHistory($db, $username, $machine);

    $token = createSession($db, $username, $machine);
    ok(['token' => $token, 'username' => $username]);
}

function actionChangePassword(): void {
    $username = trim((string) param('username', ''));
    $oldPass  = (string) param('oldPass', '');
    $newPass  = (string) param('newPass', '');

    if ($username === '' || $oldPass === '' || $newPass === '') fail('Wajib diisi.');

    $db   = getDb();
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($oldPass, $user['password_hash'])) fail('Password lama salah.');

    $newHash = password_hash($newPass, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password_hash = :h WHERE username = :u")
       ->execute([':h' => $newHash, ':u' => $username]);

    ok(true);
}

function actionValidate(): void {
    $token = getBearerToken();
    if ($token === '') fail('Token tidak diberikan.', 401);

    $result = validateToken($token);
    if (!$result['isValid']) fail('Sesi tidak valid atau telah berakhir.', 401);

    ok(['username' => $result['username'], 'isAdmin' => $result['role'] === 'admin']);
}

function actionCheckTakeover(): void {
    $username     = trim((string) param('username', ''));
    $currentToken = (string)(body()['token'] ?? '');
    $machine      = trim((string) param('machine', ''));

    if ($username === '' || $machine === '') fail('username dan machine wajib diisi.');

    $db     = getDb();
    $active = getActiveSession($db, $username, $machine);

    if (!$active && $currentToken !== '') {
        ok(['logout' => true, 'reason' => 'Session expired on server']);
    }

    if ($active && $active['token'] !== $currentToken) {
        ok(['logout' => true, 'reason' => 'Session taken over']);
    }

    $req = getPendingTakeover($db, $username, $machine);

    if ($req) {
        $age = (int) (time() - strtotime($req['created_at']));
        if ($age > 30) {
            $db->prepare("DELETE FROM takeover_requests WHERE id = :id")->execute([':id' => $req['id']]);
            ok(['hasRequest' => false]);
        }
        ok(['hasRequest' => true]);
    }

    ok(['hasRequest' => false]);
}
function actionTakeoverDecision(): void {
    $username = trim((string) param('username', ''));
    $machine  = trim((string) param('machine', ''));
    $approved = filter_var(param('approved', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($username === '' || $machine === '' || $approved === null) {
        fail('username, machine, dan approved wajib diisi.');
    }

    $db  = getDb();
    $req = getPendingTakeover($db, $username, $machine);

    if (!$req) fail('Request sudah kadaluarsa atau tidak ada.');

    if ($approved) {
        $db->beginTransaction();
        try {
            // Timpa token sesi aktif dengan token si penantang
            $db->prepare(
                "UPDATE sessions SET token = :t, expires_at = NOW() + make_interval(secs => :ttl)
                 WHERE username = :u AND machine_id = :m"
            )->execute([
                ':t'   => $req['requester_token'],
                ':ttl' => SESSION_TTL_SEC,
                ':u'   => $username,
                ':m'   => $machine,
            ]);

            $db->prepare(
                "UPDATE takeover_requests SET status = 'APPROVED' WHERE id = :id"
            )->execute([':id' => $req['id']]);

            $db->prepare(
                "DELETE FROM takeover_requests WHERE username = :u AND machine_id = :m AND id <> :id"
            )->execute([':u' => $username, ':m' => $machine, ':id' => $req['id']]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            fail('Gagal memproses. Coba lagi.');
        }
    } else {
        $db->prepare(
            "UPDATE takeover_requests SET status = 'REJECTED' WHERE id = :id"
        )->execute([':id' => $req['id']]);
    }

    ok(true);
}

function actionTakeoverStatus(): void {
    $username  = trim((string) param('username', ''));
    $tempToken = trim((string) param('tempToken', ''));
    $machine   = trim((string) param('machine', ''));

    if ($username === '' || $tempToken === '' || $machine === '') {
        fail('username, tempToken, dan machine wajib diisi.');
    }

    $db = getDb();

    $stmt = $db->prepare(
        "SELECT id, status, created_at, timeout_count
         FROM takeover_requests
         WHERE username = :u AND machine_id = :m AND requester_token = :t
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':u' => $username, ':m' => $machine, ':t' => $tempToken]);
    $req = $stmt->fetch();

    if ($req && $req['status'] === 'APPROVED') {
        // Verifikasi sesi sudah diganti dengan tempToken
        $session = getActiveSession($db, $username, $machine);
        if ($session && $session['token'] === $tempToken) {
            // Bersihkan request yang sudah resolved
            clearTakeoverHistory($db, $username, $machine);
            ok(['status' => 'SUCCESS', 'token' => $tempToken, 'username' => $username]);
        }
    }

    if ($req && $req['status'] === 'REJECTED') {
        clearTakeoverHistory($db, $username, $machine);
        ok(['status' => 'REJECTED']);
    }

    // Cek timeout: request tidak ditemukan atau PENDING tapi sudah > 30 detik
    $isExpired = false;
    if (!$req) {
        $isExpired = true;
    } elseif ($req['status'] === 'PENDING') {
        $age = (int) (time() - strtotime($req['created_at']));
        if ($age > 30) {
            $isExpired = true;
            $db->prepare("DELETE FROM takeover_requests WHERE id = :id")
               ->execute([':id' => $req['id']]);
        }
    }

    if ($isExpired) {
        // Request sudah dihapus -- ambil MAX timeout_count yang tersimpan lalu increment.
        // Simpan entry baru ber-status EXPIRED sebagai counter; ON CONFLICT update saja.
        $stmt2 = $db->prepare(
            "SELECT COALESCE(MAX(timeout_count),0) AS cnt
             FROM takeover_requests
             WHERE username = :u AND machine_id = :m"
        );
        $stmt2->execute([':u' => $username, ':m' => $machine]);
        $cnt = (int) ($stmt2->fetchColumn() ?? 0) + 1;

        // Simpan log timeout untuk tracking force-takeover threshold.
        // ON CONFLICT butuh UNIQUE constraint (migration_v2_scale100.sql).
        // Jika constraint belum ada, fallback ke DELETE + INSERT agar tidak crash.
        try {
            $db->prepare(
                "INSERT INTO takeover_requests (username, machine_id, requester_token, status, timeout_count)
                 VALUES (:u, :m, :t, 'EXPIRED', :cnt)
                 ON CONFLICT (username, machine_id, requester_token) DO UPDATE SET timeout_count = EXCLUDED.timeout_count"
            )->execute([':u' => $username, ':m' => $machine, ':t' => $tempToken, ':cnt' => $cnt]);
        } catch (Throwable $e) {
            // Constraint not yet present (migration not run) — use safe fallback
            $db->prepare(
                "DELETE FROM takeover_requests WHERE username = :u AND machine_id = :m AND requester_token = :t"
            )->execute([':u' => $username, ':m' => $machine, ':t' => $tempToken]);
            $db->prepare(
                "INSERT INTO takeover_requests (username, machine_id, requester_token, status, timeout_count)
                 VALUES (:u, :m, :t, 'EXPIRED', :cnt)"
            )->execute([':u' => $username, ':m' => $machine, ':t' => $tempToken, ':cnt' => $cnt]);
        }

        ok([
            'status' => 'TIMEOUT',
            'attempts' => $cnt,
            'canDirectTakeover' => $cnt >= 2,
        ]);
    }

    ok(['status' => 'WAITING']);
}

// -- Router -------------------------------------------------------------------

$action = trim((string) ($_GET['action'] ?? ''));

// Global safety net: any unhandled exception returns JSON (not a blank 500 page)
try {
    match($action) {
        'login'            => actionLogin(),
        'logout'           => actionLogout(),
        'forceLogin'       => actionForceLogin(),
        'changePassword'   => actionChangePassword(),
        'validate'         => actionValidate(),
        'checkTakeover'    => actionCheckTakeover(),
        'takeoverDecision' => actionTakeoverDecision(),
        'takeoverStatus'   => actionTakeoverStatus(),
        default            => fail("Action '{$action}' tidak dikenal.", 400),
    };
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Coba lagi.']);
    exit;
}

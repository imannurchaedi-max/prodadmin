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

function bearerToken(): ?string {
    // Sama seperti getBearerToken() di auth_helper.php - semua fallback
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $h = $headers['authorization'] ?? '';
    }
    if ($h && preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return $_GET['token'] ?? null;
}

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

function createSession(PDO $db, string $username, string $machineId): string {
    $token = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );
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

// -- Actions ------------------------------------------------------------------

function actionLogin(): void {
    $username = trim((string) param('username', ''));
    $password = (string) param('password', '');
    $machine  = trim((string) param('machine', ''));

    if ($username === '' || $password === '' || $machine === '') {
        fail('username, password, dan machine wajib diisi.');
    }

    $db = getDb();

    // 1. Cek user & password
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        fail('Password salah atau User tidak ditemukan.');
    }

    // 2. Cek sesi aktif di mesin ini
    $active = getActiveSession($db, $username, $machine);

    if ($active) {
        // Ada sesi aktif -> buat takeover request
        $tempToken = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );

        // Hapus request lama (jika ada) sebelum buat baru
        $db->prepare(
            "DELETE FROM takeover_requests WHERE username = :u AND machine_id = :m"
        )->execute([':u' => $username, ':m' => $machine]);

        $db->prepare(
            "INSERT INTO takeover_requests (username, machine_id, requester_token, status)
             VALUES (:u, :m, :token, 'PENDING')"
        )->execute([':u' => $username, ':m' => $machine, ':token' => $tempToken]);

        echo json_encode([
            'success'   => false,
            'status'    => 'WAITING_APPROVAL',
            'tempToken' => $tempToken,
            'message'   => "User aktif di {$machine}. Menunggu izin...",
        ]);
        exit;
    }

    // 3. Login bersih -> buat sesi baru
    $token = createSession($db, $username, $machine);
    ok(['token' => $token, 'username' => $username]);
}

function actionLogout(): void {
    $username = trim((string) param('username', ''));
    $machine  = trim((string) param('machine', ''));

    if ($username !== '' && $machine !== '') {
        deleteSession(getDb(), $username, $machine);
    }
    ok(true);
}

function actionForceLogin(): void {
    // Dilindungi dengan admin PIN
    $pin      = (string) param('pin', '');
    $username = trim((string) param('username', ''));
    $machine  = trim((string) param('machine', ''));

    if ($username === '' || $machine === '') {
        fail('username dan machine wajib diisi.');
    }
    if ($pin !== getAdminPin()) {
        fail('PIN admin salah.', 403);
    }

    $db = getDb();
    deleteSession($db, $username, $machine);

    // Hapus takeover request yang mungkin masih menggantung
    $db->prepare(
        "DELETE FROM takeover_requests WHERE username = :u AND machine_id = :m"
    )->execute([':u' => $username, ':m' => $machine]);

    $token = createSession($db, $username, $machine);
    ok(['token' => $token, 'username' => $username]);
}

function actionChangePassword(): void {
    $username = trim((string) param('username', ''));
    $oldPass  = (string) param('oldPass', '');
    $newPass  = (string) param('newPass', '');

    if ($username === '' || $oldPass === '' || $newPass === '') {
        fail('username, oldPass, dan newPass wajib diisi.');
    }

    $db   = getDb();
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($oldPass, $user['password_hash'])) {
        fail('Password lama salah.');
    }

    $newHash = password_hash($newPass, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password_hash = :h WHERE username = :u")
       ->execute([':h' => $newHash, ':u' => $username]);

    ok(true);
}

function actionValidate(): void {
    $token = bearerToken() ?? (string) param('token', '');
    if ($token === '') fail('Token tidak diberikan.', 401);

    $result = validateToken($token);
    if (!$result['isValid']) fail('Sesi tidak valid atau telah berakhir.', 401);

    ok(['username' => $result['username'], 'isAdmin' => $result['role'] === 'admin']);
}

function actionCheckTakeover(): void {
    $username     = trim((string) param('username', ''));
    $currentToken = (string) param('token', '') ?: (bearerToken() ?? '');
    $machine      = trim((string) param('machine', ''));

    if ($username === '' || $machine === '') fail('username dan machine wajib diisi.');

    $db     = getDb();
    $active = getActiveSession($db, $username, $machine);

    // Sesi server hilang tapi client masih punya token -> paksa logout
    if (!$active && $currentToken !== '') {
        ok(['logout' => true, 'reason' => 'Session expired on server']);
    }

    // Token berbeda -> sesi sudah diambil alih
    if ($active && $active['token'] !== $currentToken) {
        ok(['logout' => true, 'reason' => 'Session taken over']);
    }

    // Cek takeover request pending
    $req = getPendingTakeover($db, $username, $machine);

    if ($req) {
        $age = (int) (time() - strtotime($req['created_at']));
        if ($age > 30) {
            // Request expired -> hapus
            $db->prepare(
                "DELETE FROM takeover_requests WHERE id = :id"
            )->execute([':id' => $req['id']]);
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

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            fail('Gagal memproses keputusan: ' . $e->getMessage());
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
            $db->prepare("DELETE FROM takeover_requests WHERE id = :id")
               ->execute([':id' => $req['id']]);
            ok(['status' => 'SUCCESS', 'token' => $tempToken, 'username' => $username]);
        }
    }

    if ($req && $req['status'] === 'REJECTED') {
        $db->prepare("DELETE FROM takeover_requests WHERE id = :id")
           ->execute([':id' => $req['id']]);
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
        // Ambil / increment timeout_count dari sisi client atau DB
        // GAS menggunakan Props terpisah; di PHP kita simpan di takeover_requests
        // Tapi jika request sudah dihapus, kita tidak bisa increment.
        // Gunakan session temporary counter - simpan di takeover_requests baru dengan status EXPIRED
        $stmt2 = $db->prepare(
            "SELECT COALESCE(MAX(timeout_count),0) AS cnt
             FROM takeover_requests
             WHERE username = :u AND machine_id = :m"
        );
        $stmt2->execute([':u' => $username, ':m' => $machine]);
        $cnt = (int) ($stmt2->fetchColumn() ?? 0) + 1;

        // Simpan log timeout (opsional, untuk audit)
        $db->prepare(
            "INSERT INTO takeover_requests (username, machine_id, requester_token, status, timeout_count)
             VALUES (:u, :m, :t, 'EXPIRED', :cnt)
             ON CONFLICT DO NOTHING"
        )->execute([':u' => $username, ':m' => $machine, ':t' => $tempToken, ':cnt' => $cnt]);

        ok(['status' => 'TIMEOUT', 'attempts' => $cnt]);
    }

    ok(['status' => 'WAITING']);
}

// -- Router -------------------------------------------------------------------

$action = trim((string) ($_GET['action'] ?? ''));

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

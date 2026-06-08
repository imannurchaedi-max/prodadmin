<?php
declare(strict_types=1);

const SESSION_TTL_SEC   = 21600;  // 6 hours
const SESSION_CACHE_TTL = 60;     // APCu cache TTL in seconds

function getBearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $header = (string) ($headers['authorization'] ?? '');
    }

    if ($header !== '' && preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        return trim($m[1]);
    }

    return trim((string) (
        $_GET['token']
        ?? $_POST['token']
        ?? ($GLOBALS['_BODY']['token'] ?? '')
    ));
}

function authFail(string $message, int $code = 401): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'    => false,
        'authFailed' => $code === 401,
        'message'    => $message,
    ]);
    exit;
}

/**
 * Remove a token from the APCu session cache.
 * Call this whenever a session is invalidated (logout, takeover, force-login).
 */
function invalidateSessionCache(string $token): void
{
    if ($token === '' || !function_exists('apcu_delete')) return;
    apcu_delete('sess_' . hash('sha256', $token));
}

/**
 * Validate bearer token and return session row.
 * Results are cached in APCu for SESSION_CACHE_TTL seconds to reduce DB load.
 * Falls back to direct DB query if APCu is unavailable.
 */
function requireSession(PDO $db): array
{
    $token = getBearerToken();
    if ($token === '') {
        authFail('Token tidak diberikan.', 401);
    }

    // --- APCu fast path ---
    if (function_exists('apcu_fetch')) {
        $cacheKey = 'sess_' . hash('sha256', $token);
        $cached   = apcu_fetch($cacheKey, $hit);
        if ($hit && is_array($cached)) {
            return $cached;
        }
    }

    // --- DB query ---
    $stmt = $db->prepare(
        "SELECT s.token, s.username, s.machine_id, s.expires_at, u.role
         FROM sessions s
         JOIN users u ON u.username = s.username
         WHERE s.token = :token
           AND s.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();

    if (!$row) {
        authFail('Sesi tidak valid atau telah berakhir.', 401);
    }

    // --- Populate APCu cache ---
    if (function_exists('apcu_store')) {
        $cacheKey = 'sess_' . hash('sha256', $token);
        apcu_store($cacheKey, $row, SESSION_CACHE_TTL);
    }

    return $row;
}

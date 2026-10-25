<?php
// =================================================================
// include/tracker.php
// Records every page visit (server + client-side signals)
// into `system_visit_logs`, with optional MaxMind GeoLite2 City
// lookup to populate `country` and `city`.
//
// Usage (top of any page):
//     require_once __DIR__ . '/include/tracker.php';
//     tracker_log_visit($pdo);   // $pdo from db_connect.php
//
// For logged-in pages, call AFTER the auth check (so $_SESSION
// is populated) — see admin/admin_panel.php.
//
// Requires (composer):
//     composer require geoip2/geoip2:~3.0
//
// Place GeoLite2-City.mmdb at:
//     /path/to/your/project/geoip/GeoLite2-City.mmdb
// =================================================================

use GeoIp2\Database\Reader;

if (!defined('GEOIP_DB_PATH')) {
    define('GEOIP_DB_PATH', __DIR__ . '/../geoip/GeoLite2-City.mmdb');
}

/* -----------------------------------------------------------------
   Reusable GeoIP reader (lazily created, cached per request)
   ----------------------------------------------------------------- */
function tracker_geo_reader(): ?Reader
{
    static $reader = null;
    static $tried  = false;

    if ($tried) return $reader;
    $tried = true;

    if (!class_exists('\GeoIp2\Database\Reader')) {
        // Composer autoloader not loaded — try common paths
        $autoloads = [
            __DIR__ . '/../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
        ];
        foreach ($autoloads as $a) {
            if (file_exists($a)) {
                require_once $a;
                break;
            }
        }
    }

    if (!class_exists('\GeoIp2\Database\Reader')) {
        error_log('[tracker] GeoIP2 library not installed — run: composer require geoip2/geoip2:~3.0');
        return null;
    }

    if (!file_exists(GEOIP_DB_PATH)) {
        error_log('[tracker] GeoIP DB missing at ' . GEOIP_DB_PATH);
        return null;
    }

    try {
        $reader = new Reader(GEOIP_DB_PATH);
    } catch (Throwable $e) {
        error_log('[tracker] GeoIP reader init failed: ' . $e->getMessage());
        $reader = null;
    }
    return $reader;
}

/**
 * Looks up country + city for a given IP using GeoLite2-City.
 * Returns ['country' => ?string, 'city' => ?string].
 */
function tracker_geo_lookup(string $ip): array
{
    $out = ['country' => null, 'city' => null];

    if (!filter_var($ip, FILTER_VALIDATE_IP)) return $out;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        // Private / reserved IPs — skip lookup
        return $out;
    }

    $reader = tracker_geo_reader();
    if (!$reader) return $out;

    try {
        $rec = $reader->city($ip);
        $out['country'] = $rec->country->name ?: ($rec->registeredCountry->name ?: null);
        $out['city']    = $rec->city->name    ?: null;
    } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
        // IP not in DB — expected, no logging needed
    } catch (Throwable $e) {
        error_log('[tracker] geo_lookup error for ' . $ip . ': ' . $e->getMessage());
    }

    return $out;
}

/* -----------------------------------------------------------------
   Best-effort logged-in user detection (reads many session keys)
   -----------------------------------------------------------------
   Your app (admin_login.php / admin_panel.php) uses:
       $_SESSION['admin_user_id']
       $_SESSION['admin_name']
       $_SESSION['user_role']

   The arrays below include those AND common alternatives so this
   helper works across index.php, admin_panel.php, and any future
   page you add.
   ----------------------------------------------------------------- */
function tracker_detect_user(): array
{
    // Ordered list — first non-empty wins.
    $id_keys = [
        'admin_user_id',                    // ← your admin login
        'user_id',
        'employee_id',
        'admin_id',
        'staff_id',
        'auth_user_id',
        'username',
        'user',
        'login_user',
        'admin_user',
    ];
    $name_keys = [
        'admin_name',                       // ← your admin login
        'user_name',
        'full_name',
        'display_name',
        'name',
        'username',
        'user',
        'admin_user',
    ];

    $user_id = null;
    foreach ($id_keys as $k) {
        if (!empty($_SESSION[$k])) {
            $user_id = (string)$_SESSION[$k];
            break;
        }
    }
    $user_name = null;
    foreach ($name_keys as $k) {
        if (!empty($_SESSION[$k])) {
            $user_name = (string)$_SESSION[$k];
            break;
        }
    }

    // Explicit admin flags — your app uses user_role
    $is_admin = !empty($_SESSION['admin_id'])
        || !empty($_SESSION['is_admin'])
        || !empty($_SESSION['logged_in'])
        || (isset($_SESSION['user_role']) && $_SESSION['user_role'] !== 'guest')   // ← your app
        || (!empty($_SESSION['admin_user']) && empty($_SESSION['employee_id']));

    $logged_in = ($user_id !== null) || $is_admin;

    return [
        'id'     => $user_id,
        'name'   => $user_name,
        'status' => $logged_in ? 'logged_in' : 'guest',
    ];
}

/**
 * Records the current page visit into `system_visit_logs`.
 */
function tracker_log_visit(?PDO $pdo): void
{
    // Silently skip if DB isn't ready (page still renders)
    if (!$pdo instanceof PDO) return;

    // Ensure the table exists (cached by MySQL)
    static $ensured = false;
    if (!$ensured) {
        tracker_ensure_table($pdo);
        $ensured = true;
    }

    // Handle client-side payload (POST from the browser)
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['track_visit'])) {
        tracker_handle_client_payload($pdo);
        exit;
    }

    // Only log top-level GET page views
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (isset($_GET['export']) || isset($_GET['ajax']) || isset($_GET['track_visit'])) return;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return;

    // ── Skip static assets (css, js, images, fonts, media, downloads) ──
    $uri_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $ext = strtolower(pathinfo($uri_path, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, [
        'css',
        'js',
        'mjs',
        'map',
        'json',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'svg',
        'ico',
        'bmp',
        'avif',
        'tif',
        'tiff',
        'woff',
        'woff2',
        'ttf',
        'otf',
        'eot',
        'mp4',
        'm4v',
        'mp3',
        'wav',
        'ogg',
        'oga',
        'webm',
        'mov',
        'avi',
        'pdf',
        'zip',
        'gz',
        'tgz',
        'tar',
        'rar',
        '7z',
        'xml',
        'txt',
        'csv',
        'xls',
        'xlsx',
        'doc',
        'docx',
        'ppt',
        'pptx',
        'wasm',
        'apk',
        'exe',
        'dmg',
        'iso',
    ], true)) {
        return;
    }

    // ── Skip the analytics dashboard itself (would inflate its own numbers) ──
    if (strpos($uri_path, '/system.php') !== false) return;

    // ── Skip common non-page paths ──
    foreach (['/include/', '/vendor/', '/Script/', '/TCPDF/', '/logs/', '/robots.txt', '/sitemap.xml'] as $skip) {
        if (strpos($uri_path, $skip) !== false) return;
    }

    // Skip if already logged for this session+URI in the last 30 seconds
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $sid = session_id() ?: '';
    $recentKey = 'track_logged_' . md5($sid . '|' . $uri);
    if (!empty($_SESSION[$recentKey]) && (time() - $_SESSION[$recentKey]) < 30) return;
    $_SESSION[$recentKey] = time();

    // ── Geo lookup (server-side, at insert time) ──
    $ip  = tracker_client_ip();
    $geo = tracker_geo_lookup($ip);

    // ── Detect logged-in user ──
    $auth = tracker_detect_user();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_visit_logs
                (session_id, user_id, user_name, login_status,
                 ip_address, user_agent, referrer,
                 request_method, request_uri, protocol, host,
                 access_time, server_time,
                 country, city)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $sid,
            $auth['id'],
            $auth['name'],
            $auth['status'],
            $ip,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
            substr((string)($_SERVER['HTTP_REFERER']    ?? ''), 0, 1000),
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $uri,
            $_SERVER['SERVER_PROTOCOL'] ?? '',
            $_SERVER['HTTP_HOST']      ?? '',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $geo['country'],
            $geo['city'],
        ]);

        $_SESSION['track_log_id'] = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[tracker] insert failed: ' . $e->getMessage());
    }
}

/**
 * Applies client-side signals posted by the JS tracker.
 * Geo columns are already filled at insert time; this only updates
 * browser/hardware/behavior fields.
 */
function tracker_handle_client_payload(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');

    $logId = (int)($_SESSION['track_log_id'] ?? 0);
    if ($logId <= 0) {
        echo json_encode(['ok' => false, 'reason' => 'no_log_id']);
        return;
    }

    $payload = $_POST;

    // Fallback: if country/city are still empty in the row but the
    // user's IP resolves now, fill them in too.
    $extraCountry = null;
    $extraCity    = null;
    try {
        $check = $pdo->prepare("SELECT ip_address, country, city FROM system_visit_logs WHERE log_id = ? LIMIT 1");
        $check->execute([$logId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row && (empty($row['country']) || empty($row['city']))) {
            $geo = tracker_geo_lookup((string)$row['ip_address']);
            if (empty($row['country']) && $geo['country']) $extraCountry = $geo['country'];
            if (empty($row['city'])    && $geo['city'])    $extraCity    = $geo['city'];
        }
    } catch (Throwable $e) {
        // Ignore — not critical
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE system_visit_logs SET
                screen_w          = COALESCE(NULLIF(?, ''), screen_w),
                screen_h          = COALESCE(NULLIF(?, ''), screen_h),
                viewport_w        = COALESCE(NULLIF(?, ''), viewport_w),
                viewport_h        = COALESCE(NULLIF(?, ''), viewport_h),
                color_depth       = COALESCE(NULLIF(?, ''), color_depth),
                pixel_ratio       = COALESCE(NULLIF(?, ''), pixel_ratio),
                dark_mode         = COALESCE(NULLIF(?, ''), dark_mode),
                browser_lang      = COALESCE(NULLIF(?, ''), browser_lang),
                languages         = COALESCE(NULLIF(?, ''), languages),
                cpu_cores         = COALESCE(NULLIF(?, ''), cpu_cores),
                device_memory     = COALESCE(NULLIF(?, ''), device_memory),
                connection_type   = COALESCE(NULLIF(?, ''), connection_type),
                connection_speed  = COALESCE(NULLIF(?, ''), connection_speed),
                data_saver        = COALESCE(NULLIF(?, ''), data_saver),
                timezone          = COALESCE(NULLIF(?, ''), timezone),
                platform          = COALESCE(NULLIF(?, ''), platform),
                scroll_depth      = GREATEST(COALESCE(scroll_depth,0), COALESCE(NULLIF(?, ''), 0)),
                dwell_seconds     = GREATEST(COALESCE(dwell_seconds,0), COALESCE(NULLIF(?, ''), 0)),
                clicks            = GREATEST(COALESCE(clicks,0), COALESCE(NULLIF(?, ''), 0)),
                form_focuses      = GREATEST(COALESCE(form_focuses,0), COALESCE(NULLIF(?, ''), 0)),
                downloads         = GREATEST(COALESCE(downloads,0), COALESCE(NULLIF(?, ''), 0)),
                exit_intent       = GREATEST(COALESCE(exit_intent,0), COALESCE(NULLIF(?, ''), 0)),
                country           = COALESCE(country, ?),
                city              = COALESCE(city, ?)
            WHERE log_id = ?
        ");
        $stmt->execute([
            (int)($payload['screen_w']         ?? 0),
            (int)($payload['screen_h']         ?? 0),
            (int)($payload['viewport_w']       ?? 0),
            (int)($payload['viewport_h']       ?? 0),
            (int)($payload['color_depth']      ?? 0),
            (float)($payload['pixel_ratio']    ?? 0),
            (int)($payload['dark_mode']        ?? 0),
            substr((string)($payload['lang']          ?? ''), 0, 20),
            substr((string)($payload['languages']     ?? ''), 0, 255),
            (int)($payload['cpu_cores']        ?? 0),
            (float)($payload['device_memory']  ?? 0),
            substr((string)($payload['conn_type']     ?? ''), 0, 20),
            substr((string)($payload['conn_speed']    ?? ''), 0, 20),
            (int)($payload['data_saver']       ?? 0),
            substr((string)($payload['timezone']      ?? ''), 0, 64),
            substr((string)($payload['platform']      ?? ''), 0, 64),
            (int)($payload['scroll_depth']     ?? 0),
            (int)($payload['dwell_seconds']    ?? 0),
            (int)($payload['clicks']           ?? 0),
            (int)($payload['form_focuses']     ?? 0),
            (int)($payload['downloads']        ?? 0),
            (int)($payload['exit_intent']      ?? 0),
            $extraCountry,
            $extraCity,
            $logId,
        ]);
        echo json_encode(['ok' => true, 'log_id' => $logId]);
    } catch (Throwable $e) {
        error_log('[tracker] update failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'reason' => 'update_failed']);
    }
}

/**
 * Returns the real client IP even behind proxies.
 */
function tracker_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Creates `system_visit_logs` if it doesn't exist.
 */
function tracker_ensure_table(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `system_visit_logs` (
                `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `session_id` VARCHAR(64) DEFAULT NULL,
                `user_id` VARCHAR(50) DEFAULT NULL,
                `user_name` VARCHAR(255) DEFAULT NULL,
                `login_status` ENUM('guest','logged_in') NOT NULL DEFAULT 'guest',
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` TEXT DEFAULT NULL,
                `referrer` TEXT DEFAULT NULL,
                `request_method` VARCHAR(10) DEFAULT NULL,
                `request_uri` TEXT DEFAULT NULL,
                `protocol` VARCHAR(20) DEFAULT NULL,
                `host` VARCHAR(255) DEFAULT NULL,
                `access_time` DATETIME NOT NULL,
                `server_time` DATETIME NOT NULL,
                `screen_w` INT DEFAULT NULL,
                `screen_h` INT DEFAULT NULL,
                `viewport_w` INT DEFAULT NULL,
                `viewport_h` INT DEFAULT NULL,
                `color_depth` TINYINT DEFAULT NULL,
                `pixel_ratio` DECIMAL(4,2) DEFAULT NULL,
                `dark_mode` TINYINT(1) DEFAULT NULL,
                `browser_lang` VARCHAR(20) DEFAULT NULL,
                `languages` VARCHAR(255) DEFAULT NULL,
                `cpu_cores` TINYINT DEFAULT NULL,
                `device_memory` DECIMAL(4,1) DEFAULT NULL,
                `connection_type` VARCHAR(20) DEFAULT NULL,
                `connection_speed` VARCHAR(20) DEFAULT NULL,
                `data_saver` TINYINT(1) DEFAULT NULL,
                `timezone` VARCHAR(64) DEFAULT NULL,
                `platform` VARCHAR(64) DEFAULT NULL,
                `scroll_depth` TINYINT UNSIGNED DEFAULT 0,
                `dwell_seconds` INT UNSIGNED DEFAULT 0,
                `clicks` INT UNSIGNED DEFAULT 0,
                `form_focuses` INT UNSIGNED DEFAULT 0,
                `downloads` INT UNSIGNED DEFAULT 0,
                `exit_intent` TINYINT(1) DEFAULT 0,
                `country` VARCHAR(64) DEFAULT NULL,
                `city` VARCHAR(64) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`log_id`),
                KEY `idx_login` (`login_status`),
                KEY `idx_user` (`user_id`),
                KEY `idx_session` (`session_id`),
                KEY `idx_created` (`created_at`),
                KEY `idx_ip` (`ip_address`),
                KEY `idx_country` (`country`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('[tracker] ensure_table failed: ' . $e->getMessage());
    }
}

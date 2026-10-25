<?php
// =================================================================
// system.php — System Visit Analytics Dashboard
// =================================================================

if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../include/db_connect.php';
require_once '../include/auth.php';

// Only admins can view the dashboard
if (function_exists('requireAdmin') && !isGuest()) {
    requireAdmin();
}

/* -----------------------------------------------------------------
   Filters
   ----------------------------------------------------------------- */
$login_filter = $_GET['login'] ?? 'all';
if (!in_array($login_filter, ['all', 'guest', 'logged_in'], true)) $login_filter = 'all';

$range_days = max(1, min(365, (int)($_GET['days'] ?? 7)));

$where_parts = ["created_at >= DATE_SUB(NOW(), INTERVAL {$range_days} DAY)"];
$params = [];
if ($login_filter !== 'all') {
    $where_parts[] = "login_status = ?";
    $params[] = $login_filter;
}
$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

/* -----------------------------------------------------------------
   Headline KPIs
   ----------------------------------------------------------------- */
$kpis = [
    'total' => 0,
    'guest' => 0,
    'logged_in' => 0,
    'unique_ips' => 0,
    'avg_dwell' => 0,
    'avg_scroll' => 0,
    'clicks' => 0,
    'downloads' => 0
];

try {
    $row = $pdo->prepare("
        SELECT
            COUNT(*)                                       AS total,
            SUM(login_status='guest')                      AS guest,
            SUM(login_status='logged_in')                  AS logged_in,
            COUNT(DISTINCT ip_address)                     AS unique_ips,
            COALESCE(AVG(NULLIF(dwell_seconds,0)),0)       AS avg_dwell,
            COALESCE(AVG(NULLIF(scroll_depth,0)),0)        AS avg_scroll,
            COALESCE(SUM(clicks),0)                        AS clicks,
            COALESCE(SUM(downloads),0)                     AS downloads
        FROM system_visit_logs
        $where_sql
    ");
    $row->execute($params);
    $k = $row->fetch(PDO::FETCH_ASSOC);
    if ($k) $kpis = array_merge($kpis, $k);
} catch (Throwable $e) {
}

/* -----------------------------------------------------------------
   Daily visit trend (last N days, gaps filled with 0)
   ----------------------------------------------------------------- */
$daily_labels = [];
$daily_guest = [];
$daily_user  = [];
$daily_totals = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) AS d,
               SUM(login_status='guest')     AS g,
               SUM(login_status='logged_in') AS u
        FROM system_visit_logs
        $where_sql
        GROUP BY DATE(created_at)
        ORDER BY d ASC
    ");
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['d']] = $r;

    for ($i = $range_days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $daily_labels[] = date('M j', strtotime($d));
        $daily_guest[]  = (int)($map[$d]['g'] ?? 0);
        $daily_user[]   = (int)($map[$d]['u'] ?? 0);
        $daily_totals[] = (int)($map[$d]['g'] ?? 0) + (int)($map[$d]['u'] ?? 0);
    }
} catch (Throwable $e) {
}

/* -----------------------------------------------------------------
   Login status distribution (pie chart)
   ----------------------------------------------------------------- */
$pie_labels = ['Guest', 'Logged in'];
$pie_values = [(int)$kpis['guest'], (int)$kpis['logged_in']];

/* -----------------------------------------------------------------
   Browser breakdown (top 6)
   ----------------------------------------------------------------- */
$browser_labels = [];
$browser_values = [];
try {
    $stmt = $pdo->prepare("
        SELECT user_agent FROM system_visit_logs $where_sql
    ");
    $stmt->execute($params);
    $browsers = ['Chrome' => 0, 'Firefox' => 0, 'Safari' => 0, 'Edge' => 0, 'Opera' => 0, 'Other' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ua = (string)$r['user_agent'];
        if (stripos($ua, 'Edg') !== false)        $browsers['Edge']++;
        elseif (stripos($ua, 'OPR') !== false)    $browsers['Opera']++;
        elseif (stripos($ua, 'Chrome') !== false) $browsers['Chrome']++;
        elseif (stripos($ua, 'Firefox') !== false) $browsers['Firefox']++;
        elseif (stripos($ua, 'Safari') !== false) $browsers['Safari']++;
        else                                       $browsers['Other']++;
    }
    foreach ($browsers as $k => $v) {
        if ($v > 0) {
            $browser_labels[] = $k;
            $browser_values[] = $v;
        }
    }
} catch (Throwable $e) {
}

/* -----------------------------------------------------------------
   OS breakdown
   ----------------------------------------------------------------- */
$os_labels = [];
$os_values = [];
try {
    $stmt = $pdo->prepare("
        SELECT user_agent FROM system_visit_logs $where_sql
    ");
    $stmt->execute($params);
    $os = ['Windows' => 0, 'macOS' => 0, 'Linux' => 0, 'Android' => 0, 'iOS' => 0, 'Other' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ua = (string)$r['user_agent'];
        if (stripos($ua, 'Android') !== false)     $os['Android']++;
        elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os['iOS']++;
        elseif (stripos($ua, 'Windows') !== false) $os['Windows']++;
        elseif (stripos($ua, 'Mac OS') !== false)  $os['macOS']++;
        elseif (stripos($ua, 'Linux') !== false)   $os['Linux']++;
        else                                       $os['Other']++;
    }
    foreach ($os as $k => $v) {
        if ($v > 0) {
            $os_labels[] = $k;
            $os_values[] = $v;
        }
    }
} catch (Throwable $e) {
}

/* -----------------------------------------------------------------
   Top countries (if geo lookup is populated)
   ----------------------------------------------------------------- */
$country_labels = [];
$country_values = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(country,''),'Unknown') AS c, COUNT(*) AS n
        FROM system_visit_logs
        $where_sql
        GROUP BY c
        ORDER BY n DESC
        LIMIT 8
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $country_labels[] = $r['c'];
        $country_values[] = (int)$r['n'];
    }
} catch (Throwable $e) {
}

/* -----------------------------------------------------------------
   Hourly activity heatmap (last 7 days × 24 hours)
   ----------------------------------------------------------------- */
$heat = array_fill(0, 7, array_fill(0, 24, 0));
try {
    $stmt = $pdo->prepare("
        SELECT DAYOFWEEK(created_at) AS dow, HOUR(created_at) AS h, COUNT(*) AS n
        FROM system_visit_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        " . ($login_filter !== 'all' ? "AND login_status = ?" : "") . "
        GROUP BY dow, h
    ");
    $stmt->execute($login_filter !== 'all' ? [$login_filter] : []);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = ((int)$r['dow'] + 5) % 7; // Mon=0..Sun=6
        $heat[$d][(int)$r['h']] = (int)$r['n'];
    }
} catch (Throwable $e) {
}

/* -----------------------------------------------------------------
   Latest visit log (paginated)
   ----------------------------------------------------------------- */
$page = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$logs = [];
$total_logs = 0;
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM system_visit_logs $where_sql");
    $cnt->execute($params);
    $total_logs = (int)$cnt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT *
        FROM system_visit_logs
        $where_sql
        ORDER BY log_id DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$total_pages = max(1, (int)ceil($total_logs / $per_page));

/* -----------------------------------------------------------------
   Helpers for display
   ----------------------------------------------------------------- */
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function browser_icon(string $ua): string
{
    if (stripos($ua, 'Edg') !== false)     return '🌐 Edge';
    if (stripos($ua, 'OPR') !== false)     return '🦊 Opera';
    if (stripos($ua, 'Chrome') !== false)  return '🟢 Chrome';
    if (stripos($ua, 'Firefox') !== false) return '🦊 Firefox';
    if (stripos($ua, 'Safari') !== false)  return '🧭 Safari';
    return '🌐 Other';
}
function os_icon(string $ua): string
{
    if (stripos($ua, 'Android') !== false) return '🤖 Android';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) return '🍎 iOS';
    if (stripos($ua, 'Windows') !== false) return '🪟 Windows';
    if (stripos($ua, 'Mac OS') !== false)  return '🍎 macOS';
    if (stripos($ua, 'Linux') !== false)   return '🐧 Linux';
    return '💻 Other';
}
function status_badge(string $s): string
{
    return $s === 'logged_in'
        ? '<span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">Logged in</span>'
        : '<span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">Guest</span>';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>System Analytics — CourseTrack</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: Inter, ui-sans-serif, system-ui;
        }

        .kpi-card {
            transition: transform .15s;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
        }

        .heat-cell {
            aspect-ratio: 1/1;
            border-radius: 3px;
        }

        .log-row:hover {
            background: #f8fafc;
        }
    </style>
</head>

<body class="bg-slate-100 min-h-screen">

    <!-- ── Header ── -->
    <header class="bg-[#588D01] text-white shadow">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="./img/logo.png" alt="Logo" class="h-10 w-auto" onerror="this.style.display='none'">
                <div>
                    <h1 class="text-xl font-bold">System Analytics</h1>
                    <p class="text-xs opacity-80">Live visit tracking — server + client signals</p>
                </div>
            </div>
            <a href="index.php" class="bg-white text-[#588D01] px-4 py-2 rounded font-semibold shadow text-sm">← Back to Portal</a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">

        <!-- ── Filter bar ── -->
        <form method="GET" class="bg-white rounded-2xl shadow-lg p-5 mb-6 flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1 uppercase tracking-wide">Login status</label>
                <select name="login" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                    <option value="all" <?= $login_filter === 'all'       ? 'selected' : '' ?>>All visitors</option>
                    <option value="guest" <?= $login_filter === 'guest'     ? 'selected' : '' ?>>Non-logged-in (guests)</option>
                    <option value="logged_in" <?= $login_filter === 'logged_in' ? 'selected' : '' ?>>Logged-in users</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1 uppercase tracking-wide">Range</label>
                <select name="days" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                    <?php foreach ([1 => 'Last 24h', 7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $v => $lbl): ?>
                        <option value="<?= $v ?>" <?= $range_days == $v ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="bg-[#588D01] hover:bg-[#456f01] text-white px-4 py-2 rounded-lg text-sm font-semibold">Apply</button>
            <a href="?login=all&days=7" class="text-sm text-slate-500 hover:text-slate-800 underline">Reset</a>
        </form>

        <!-- ── KPI cards ── -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="kpi-card bg-white rounded-xl shadow p-5">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Total visits</div>
                <div class="text-3xl font-bold text-slate-800 mt-1"><?= number_format((int)$kpis['total']) ?></div>
            </div>
            <div class="kpi-card bg-white rounded-xl shadow p-5">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Guests</div>
                <div class="text-3xl font-bold text-slate-600 mt-1"><?= number_format((int)$kpis['guest']) ?></div>
            </div>
            <div class="kpi-card bg-white rounded-xl shadow p-5">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Logged-in</div>
                <div class="text-3xl font-bold text-green-600 mt-1"><?= number_format((int)$kpis['logged_in']) ?></div>
            </div>
            <div class="kpi-card bg-white rounded-xl shadow p-5">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Unique IPs</div>
                <div class="text-3xl font-bold text-indigo-600 mt-1"><?= number_format((int)$kpis['unique_ips']) ?></div>
            </div>
            <div class="kpi-card bg-white rounded-xl shadow p-5">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Avg dwell</div>
                <div class="text-3xl font-bold text-amber-600 mt-1"><?= (int)$kpis['avg_dwell'] ?>s</div>
            </div>
            <div class="kpi-card bg-white rounded-xl shadow p-5">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Avg scroll depth</div>
                <div class="text-3xl font-bold text-cyan-600 mt-1"><?= (int)$kpis['avg_scroll'] ?>%</div>
            </div>
            <div class="kpi-card bg-white rounded-xl shadow p-5">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Clicks</div>
                <div class="text-3xl font-bold text-pink-600 mt-1"><?= number_format((int)$kpis['clicks']) ?></div>
            </div>
            <div class="kpi-card bg-white rounded-xl shadow p-5">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Downloads</div>
                <div class="text-3xl font-bold text-purple-600 mt-1"><?= number_format((int)$kpis['downloads']) ?></div>
            </div>
        </div>

        <!-- ── Charts grid ── -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5">
                <h2 class="font-bold text-slate-700 mb-3">Daily visits</h2>
                <div style="height:320px"><canvas id="trendChart"></canvas></div>
            </div>

            <div class="bg-white rounded-2xl shadow p-5">
                <h2 class="font-bold text-slate-700 mb-3">Login status</h2>
                <div style="height:320px"><canvas id="loginChart"></canvas></div>
            </div>

            <div class="bg-white rounded-2xl shadow p-5">
                <h2 class="font-bold text-slate-700 mb-3">Browsers</h2>
                <div style="height:260px"><canvas id="browserChart"></canvas></div>
            </div>
            <div class="bg-white rounded-2xl shadow p-5">
                <h2 class="font-bold text-slate-700 mb-3">Operating systems</h2>
                <div style="height:260px"><canvas id="osChart"></canvas></div>
            </div>
            <div class="bg-white rounded-2xl shadow p-5">
                <h2 class="font-bold text-slate-700 mb-3">Top countries</h2>
                <div style="height:260px"><canvas id="countryChart"></canvas></div>
            </div>
        </div>

        <!-- ── Heatmap ── -->
        <div class="bg-white rounded-2xl shadow p-5 mb-6">
            <h2 class="font-bold text-slate-700 mb-3">Hourly activity — last 7 days</h2>
            <div class="overflow-x-auto">
                <table class="text-xs">
                    <thead>
                        <tr>
                            <th></th>
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <th class="px-1 py-1 text-slate-400 font-normal"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $max = 1;
                        foreach ($heat as $row) foreach ($row as $v) $max = max($max, $v); ?>
                        <?php $dows = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; ?>
                        <?php foreach ($heat as $d => $row): ?>
                            <tr>
                                <td class="pr-2 text-slate-500 font-medium"><?= $dows[$d] ?></td>
                                <?php foreach ($row as $v): ?>
                                    <?php $op = $v ? round(0.15 + 0.85 * ($v / $max), 2) : 0.03; ?>
                                    <td class="p-0.5">
                                        <div class="heat-cell w-4" title="<?= $dows[$d] ?> <?= $v ?> visits"
                                            style="background: rgba(88,141,1,<?= $op ?>);"></div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Logs table ── -->
        <div class="bg-white rounded-2xl shadow overflow-hidden">
            <div class="px-5 py-4 border-b flex items-center justify-between flex-wrap gap-2">
                <h2 class="font-bold text-slate-700">Recent visits</h2>
                <div class="text-sm text-slate-500">
                    Page <?= $page ?> of <?= $total_pages ?> · <?= number_format($total_logs) ?> records
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">When</th>
                            <th class="px-3 py-2 text-left font-semibold">Status</th>
                            <th class="px-3 py-2 text-left font-semibold">User</th>
                            <th class="px-3 py-2 text-left font-semibold">IP</th>
                            <th class="px-3 py-2 text-left font-semibold">Browser</th>
                            <th class="px-3 py-2 text-left font-semibold">OS</th>
                            <th class="px-3 py-2 text-left font-semibold">Device</th>
                            <th class="px-3 py-2 text-left font-semibold">Conn.</th>
                            <th class="px-3 py-2 text-left font-semibold">Scroll</th>
                            <th class="px-3 py-2 text-left font-semibold">Dwell</th>
                            <th class="px-3 py-2 text-left font-semibold">Clicks</th>
                            <th class="px-3 py-2 text-left font-semibold">URI</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="12" class="px-3 py-8 text-center text-slate-400">No visits recorded yet for this filter.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $r): ?>
                            <tr class="log-row">
                                <td class="px-3 py-2 whitespace-nowrap font-mono text-xs">
                                    <?= h(date('Y-m-d H:i:s', strtotime($r['created_at']))) ?>
                                </td>
                                <td class="px-3 py-2"><?= status_badge($r['login_status']) ?></td>
                                <td class="px-3 py-2 text-slate-700">
                                    <?= $r['user_name'] ? h($r['user_name']) : '<span class="text-slate-400">—</span>' ?>
                                    <?php if ($r['user_id']): ?>
                                        <div class="text-[10px] text-slate-400 font-mono"><?= h($r['user_id']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs"><?= h($r['ip_address'] ?? '') ?></td>
                                <td class="px-3 py-2 whitespace-nowrap"><?= browser_icon((string)$r['user_agent']) ?></td>
                                <td class="px-3 py-2 whitespace-nowrap"><?= os_icon((string)$r['user_agent']) ?></td>
                                <td class="px-3 py-2 whitespace-nowrap font-mono text-xs">
                                    <?php if ($r['screen_w']): ?>
                                        <?= (int)$r['screen_w'] ?>×<?= (int)$r['screen_h'] ?>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs">
                                    <?= $r['connection_type'] ? h(strtoupper($r['connection_type'])) : '<span class="text-slate-400">—</span>' ?>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="w-16 bg-slate-100 rounded-full h-2 overflow-hidden">
                                        <div class="bg-cyan-500 h-2" style="width: <?= (int)$r['scroll_depth'] ?>%"></div>
                                    </div>
                                    <div class="text-[10px] text-slate-500 mt-0.5"><?= (int)$r['scroll_depth'] ?>%</div>
                                </td>
                                <td class="px-3 py-2 text-xs"><?= (int)$r['dwell_seconds'] ?>s</td>
                                <td class="px-3 py-2 text-xs"><?= (int)$r['clicks'] ?></td>
                                <td class="px-3 py-2 text-xs text-slate-500 max-w-[220px] truncate" title="<?= h($r['request_uri'] ?? '') ?>">
                                    <?= h($r['request_uri'] ?? '') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ── Pagination ── -->
            <?php if ($total_pages > 1): ?>
                <div class="px-5 py-3 border-t flex items-center justify-between text-sm">
                    <a href="?login=<?= urlencode($login_filter) ?>&days=<?= $range_days ?>&p=<?= max(1, $page - 1) ?>"
                        class="px-3 py-1 rounded border <?= $page <= 1 ? 'opacity-40 pointer-events-none' : 'hover:bg-slate-50' ?>">← Prev</a>
                    <span class="text-slate-500">Page <?= $page ?> of <?= $total_pages ?></span>
                    <a href="?login=<?= urlencode($login_filter) ?>&days=<?= $range_days ?>&p=<?= min($total_pages, $page + 1) ?>"
                        class="px-3 py-1 rounded border <?= $page >= $total_pages ? 'opacity-40 pointer-events-none' : 'hover:bg-slate-50' ?>">Next →</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="text-center text-xs text-slate-500 py-6">
        © <?= date('Y') ?> MRO Course Track System · System Analytics
    </footer>

    <script>
        const daily_labels = <?= json_encode($daily_labels) ?>;
        const daily_guest = <?= json_encode($daily_guest) ?>;
        const daily_user = <?= json_encode($daily_user) ?>;

        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: daily_labels,
                datasets: [{
                        label: 'Guests',
                        data: daily_guest,
                        borderColor: '#94a3b8',
                        backgroundColor: 'rgba(148,163,184,.2)',
                        tension: .35,
                        fill: true,
                        pointRadius: 3,
                    },
                    {
                        label: 'Logged in',
                        data: daily_user,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22,163,74,.2)',
                        tension: .35,
                        fill: true,
                        pointRadius: 3,
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('loginChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($pie_labels) ?>,
                datasets: [{
                    data: <?= json_encode($pie_values) ?>,
                    backgroundColor: ['#94a3b8', '#16a34a'],
                    borderWidth: 0
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0) || 1;
                                const pct = (ctx.raw / total * 100).toFixed(1);
                                return `${ctx.label}: ${ctx.raw} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('browserChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($browser_labels) ?>,
                datasets: [{
                    data: <?= json_encode($browser_values) ?>,
                    backgroundColor: ['#16a34a', '#22c55e', '#84cc16', '#eab308', '#f97316', '#94a3b8'],
                    borderRadius: 6
                }]
            },
            options: {
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('osChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($os_labels) ?>,
                datasets: [{
                    data: <?= json_encode($os_values) ?>,
                    backgroundColor: ['#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#94a3b8'],
                    borderRadius: 6
                }]
            },
            options: {
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('countryChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($country_labels) ?>,
                datasets: [{
                    data: <?= json_encode($country_values) ?>,
                    backgroundColor: '#0ea5e9',
                    borderRadius: 6
                }]
            },
            options: {
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>
<?php
// =================================================================
// admin/department_gap_report.php
// Department × Course Training GAP Report
// - GAP = Expired + To-be-expired (initial NOT counted in GAP)
// - Uses cc_course_requirements + position rules (same as dashboard)
// - Dynamic department columns (from cost_center_departments)
// - Clickable cells/rows/columns/totals → CSV export
// - Gap type filter (All / Initial / Expired / To-be)
// - Staff category filter (All / Technical / Non-Technical)
// - Certifying Technician filter (All / Certifying only)
// - 5-minute file cache with Force Refresh button
// - v6: honours TAKE / SKIP / FOREVER overrides from cc_position_course_overrides
// - Chart: VERTICAL bar chart, stacked above the Summary card
// =================================================================

if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once __DIR__ . '/../include/db_connect.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/cc_overrides.php';
if (!isGuest()) {
    requireAdmin();
}

// -----------------------------------------------------------------
// CONFIG
// -----------------------------------------------------------------
$CACHE_TTL = 300;
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM', 'MCM'];
const FOREVER_THRESHOLD = 999999;

// Staff category groupings
$TECHNICAL_DEPTS     = ['AEP', 'BMT', 'CMT', 'EMT', 'LMT'];
$NON_TECHNICAL_DEPTS = ['MRO HR', 'MSM', 'PSCM', 'MRO QA'];

// Certifying Technicians (hardcoded list of employee IDs)
$CERTIFYING_TECHNICIAN_IDS = [
    14313,
    12337,
    14296,
    13911,
    12333,
    12363,
    14176,
    10853,
    14814,
    12359,
    14799,
    14157,
    14539,
    14954,
    14965,
    15400,
    15734,
    15827,
    11351,
    15166,
    15168,
    15070,
    15289,
    15290,
    16142,
    16150,
    17088,
    17091,
    15064,
    14291,
    14306,
    15842,
    17321,
    17328,
    17085,
    17075,
    15947,
    16505,
    15402,
    17329,
    17428,
    17325,
    17434,
    16144,
    17430,
    13700,
    17323,
    17316,
    17079,
    17317,
    17432,
    13688,
    16143,
    15161,
    15499,
    14177,
    15170,
    16042,
    14359,
    14812,
    15534,
    14354,
    15541,
    14363,
    15949,
    15744,
    15490,
    15939,
    15058,
    16273,
    14301,
    14356,
    14535,
    15832,
    14366,
    14358,
    15730,
    16274,
    15494,
    15306,
    15069,
    15938,
    14163,
    15546,
    16265,
    16138,
    15743,
    14158,
    16357,
    17096,
    15498,
    12798,
    15737,
    15411,
    16487,
    16352,
    14365,
    14969,
    15741,
    16151,
    17435,
    10981,
    16771,
    15405,
    14966,
    15946,
    14378,
    17320,
    14348,
    15537,
    13907,
    15066,
    17427,
    17437,
    16497,
    15153,
    15397,
    15944,
    15829,
    14171,
    14050,
    14379,
    14368,
    16268,
    17334,
    17529,
    16039,
    17330,
    15731,
    15501,
    17439,
    15950,
    15838,
    15733,
    15738,
    14351,
    16778,
    15065,
    16496,
    14292,
    14382,
    16506,
    15291,
    14527,
    18134,
    17647,
    11421,
    15150,
    17420,
    15292,
    17080,
    15836,
    16777,
    14360,
    15732,
    14958,
    17645,
    16276,
    15413,
    15412,
    15407,
    17648,
    15062,
    15159,
    17641,
    16275,
    17094,
    17637,
    15295,
    17335,
    13091,
    17633,
    18125,
    10977,
    18129,
    18130,
    18132,
    16463,
    14959,
    18274,
    18275,
    18128,
    17644,
    16495,
    16373,
    18278,
    14538,
    18780,
    18790,
    18786,
    18789,
    15408,
    14307,
    18776,
    23576,
    14540,
    23099,
    19983,
    18774,
    13083,
    19921,
    19920,
    19924,
    19922,
    19925,
    19923,
    14375,
    15404,
    19990,
    19319,
    18769,
    19941,
    19934,
    20074,
    19326,
    19321,
    19958,
    19939,
    19994,
    19328,
    20076,
    20438,
    19336,
    15500,
    16339,
    20446,
    20414,
    20415,
    20432,
    20004,
    20408,
    20827,
    20442,
    20443,
    20468,
    20070,
    20421,
    19959,
    20465,
    20418,
    20425,
    20429,
    20424,
    18139,
    20463,
    20068,
    19998,
    19967,
    20419,
    20434,
    20420,
    19969,
    20069,
    20452,
    20403,
    20078,
    20794,
    20099,
    19955,
    20064,
    20435,
    19957,
    20006,
    20423,
    20085,
    15409,
    20000,
    20445,
    20450,
    20084,
    20477,
    20431,
    23577,
    13702,
    20829,
    20824,
    19976,
    20786,
    20789,
    19973,
    20470,
    20398,
    20411,
    20808,
    20832,
    20823,
    20790,
    20788,
    21313,
    21318,
    20473,
    19993,
    19986,
    20060,
    20407,
    19962,
    20811,
    20826,
    21452,
    20005,
    22285,
    21321,
    21451,
    20592,
    19984,
    19931,
    20395,
    23984,
    21316,
    21440,
    21308,
    21309,
    20447,
    20464,
    20480,
    20797,
    21484,
    19937,
    20471,
    18787,
    22290,
    21455,
    21412,
    23579,
    23966,
    20479,
    21416,
    20409,
    21411,
    21442,
    20089,
    19968,
    21403,
    21400,
    20460,
    21396,
    21324,
    20582,
    21456,
    10437,
    21457,
    20098,
    21404,
    21458,
    21464,
    19530,
    20066,
    19970,
    20100,
    20080,
    21432,
    21436,
    20585,
    19621,
    14300,
    19796,
    19332,
    19612,
    19613,
    19622,
    19607,
    19603,
    19617,
    19625,
    19605,
    19549,
    21483,
    19551,
    20817,
    19609,
    19611,
    19539,
    19665,
    19619,
    19652,
    21474,
    19623,
    19807,
    19933,
    19995,
    19552,
    21463,
    21408,
    19602,
    19616,
    19809,
    19972,
    21444,
    21486,
    19953,
    16758,
    19533,
    19537,
    21331,
    19532,
    19544,
    20073,
    19985,
    19615,
    19668,
    19660,
    22353,
    19529,
    19662,
    21429,
    19670,
    19669,
    19534,
    21461,
    20059,
    21418,
    19667,
    14798,
    21434,
    22709,
    19674,
    19610,
    21477,
    21487,
    19801,
    19805,
    23994,
    22480,
    19657,
    19614,
    19663,
    15152,
    19806,
    20082,
    19787,
    19528,
    19604,
    22719,
    22718,
    19800,
    22342,
    19792,
    21479,
    19803,
    19965,
    19790,
    21453,
    19794,
    21428,
    22336,
    22717,
    21405,
    19791,
    19804,
    19661,
    19540,
    19798,
    19802,
    21485,
    21450,
    19799,
    19542,
    21489,
    20792,
    20803,
    11818,
    21470,
    204993,
    20092,
    22713,
    20801,
    23936,
    21421,
    23933,
    23574,
    15072,
    23931,
    19601,
    12272,
    19672,
    23937,
    21803,
    22466,
    21791,
    22468,
    22463,
    22475,
    22474,
    21795,
    22481,
    25662,
    16776,
    22470,
    23941,
    21801,
    25669,
    25671,
    22485,
    22465,
    22286,
    22279,
    22467,
    25651,
    21794,
    25644,
    22288,
    22281,
    21789,
    21800,
    22484,
    21315,
    21793,
    20428,
    22275,
    21798,
    21797,
    22345,
    23930,
    22283,
    22478,
    19795,
    25647,
    21799,
    25676,
    25655,
    20436,
    25650,
    25673,
    22165,
    22276,
    25653,
    25628,
    20102,
    25654,
    25668,
    25678,
    25636,
    25659,
    25677,
    25634,
    25629,
    21788,
    25639,
    22274,
    23575,
    22270,
    20439,
    21438,
    22271,
    22272,
    22287,
    22269,
    22289,
    22278,
    22280,
    22273,
    22282,
    22277,
    23987,
    23961,
    23991,
    24000,
    22477,
    23992,
    23969,
    23995,
    23999,
    14530,
    23990,
    23998,
    19675,
    23986,
    23981,
    23965,
    23960,
    23973,
    23997,
    23979,
    201331,
    201432,
    23970,
    23971,
    11692,
    201334,
    23967,
    23964,
    23993,
    23983,
    23989,
    23988,
    201333,
    16366,
    19618,
    29997,
    23963,
    23968,
    23962,
    21796,
    26653,
    26650,
    26652,
    23985,
    13705,
    26648,
    13118,
    26647,
    23980,
    26658,
    26656,
    26714,
    26718,
    26712,
    16353,
    26722,
    15833,
    23978,
    27286,
    26731,
    23996,
    23976,
    23972,
    26657,
    27282,
    27287,
    27284,
    27295,
    27290,
    27293,
    27299,
    29165,
    27283,
    26726,
    26649,
    26645,
    26720,
    26717,
    23975,
    26660,
    27298,
    26641,
    26713,
    26643,
    26654,
    27288,
    27291,
    31394,
    27289,
    27294,
    26725,
    26659,
    31387,
    26723,
    31396,
    28143,
    28146,
    28133,
    31391,
    31392,
    31395,
    11339,
    30048,
    26662,
    26728,
    30052,
    30051,
    30050,
    30049,
    28952,
    28140,
    28139,
    28131,
    28134,
    30055,
    28940,
    31398,
    19547,
    28929,
    18123,
    28937,
    28953,
    28147,
    28932,
    28132,
    28130,
    28144,
    30053,
    28963,
    28962,
    28956,
    28951,
    28960,
    30057,
    14374,
    28959,
    29270,
    26724,
    28958,
    29262,
    29260,
    29241,
    28944,
    29249,
    28941,
    28961,
    29244,
    28936,
    28142,
    28931,
    28137,
    29246,
    28934,
    28935,
    33888,
    29243,
    29245,
    29261,
    28939,
    28938,
    28967,
    28948,
    27292,
    26727,
    29264,
    29274,
    31393,
    29263,
    29247,
    28954,
    29271,
    28136,
    30054,
    28933,
    28942,
    29273,
    28946,
    29269,
    29257,
    29272,
    29277,
    29258,
    28968,
    28965,
    33898,
    29255,
    29267,
    27300,
    33895,
    28957,
    28135,
    19317,
    29278,
    29518,
    33893,
    19320,
    20581,
    29508,
    29266,
    29519,
    29513,
    29516,
    29509,
    29511,
    29507,
    11690,
    11087,
    205674,
    16440,
    19425,
    19899,
    21030,
    19423,
    19907,
    19272,
    15401,
    19280,
    19424,
    19900,
    19893,
    22305,
    19894,
    22883,
    20971,
    21720,
    22667,
    22878,
    21038,
    22887,
    29578,
    26217,
    22885,
    22299,
    16336,
    27604,
    20970,
    22309,
    22673,
    27613,
    27611,
    27549,
    27606,
    27533,
    22258,
    27548,
    27067,
    27065,
    26218,
    27070,
    27071,
    22239,
    27537,
    27077,
    19722,
    21312,
    19910,
    27607,
    22262,
    22679,
    21041,
    18804,
    17945,
    29573,
    18316,
    19309,
    22237,
    22257,
    27616,
    29582,
    19283,
    27623,
    22407,
    27381,
    27563,
    27536,
    19728,
    22091,
    13882,
    27511,
    27505,
    24836,
    18392,
    21332,
    27615,
    20379,
    27617,
    24859,
    22702,
    26222,
    24659,
    27605,
    16486,
    27619,
    27383,
    19418,
    29370,
    22012,
    29589,
    27507,
    34953,
    27063,
    29588,
    34957,
    29374,
    27069,
    34945,
    11071,
    204635,
    1257,
    17066,
    11430,
    17065,
    11696,
    11079,
    17791,
    18355,
    117,
    18801,
    12494,
    12696,
    24360,
    24795,
    24796,
    24794,
    24797,
    16515,
    20885,
    12520,
    22326,
    2053837,
    17044,
    18184,
    22323,
    20094,
    20700,
    22108,
    20698,
    24385,
    24382,
    24366,
    2431,
    24791,
    22122,
    24806,
    24376,
    2438,
    25805,
    24377,
    24379,
    24384,
    24001,
    24800,
    10989,
    24809,
    24,
    29320,
    12499,
    24807,
    16394,
    29300,
    24114,
    10958,
    24812,
    12502
];

// Fast lookup: ['12345' => true, ...]
$CERTIFYING_TECHNICIAN_LOOKUP = array_fill_keys(array_map('strval', $CERTIFYING_TECHNICIAN_IDS), true);

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------
function safe_table($t)
{
    return preg_match('/^course_records_[a-zA-Z0-9_]+$/', $t);
}

function is_course_required($cc_course_map, $cc, $course_code, $position = '', $overrides = [])
{
    // ── 1. Position-based override (highest priority) ──
    if (!empty($overrides) && $cc !== null && $cc !== '') {
        if (is_forced_applicable_by_override($overrides, $cc, $position, $course_code)) {
            return true;   // forced ON regardless of CC map
        }
    }

    // ── 2. Standard CC mapping gate ──
    if (empty($cc_course_map)) return true;
    if ($cc === null || $cc === '') return false;
    return isset($cc_course_map[$cc][$course_code]);
}

function is_sms_management_role(?string $position): bool
{
    $pos = strtoupper(trim((string)$position));
    if ($pos === '') return false;

    return (strpos($pos, 'MANAGER') !== false)
        || (strpos($pos, 'MGR')     !== false)
        || (strpos($pos, 'MD')      !== false)
        || (strpos($pos, 'DIR')     !== false)
        || (strpos($pos, 'TEAM LEADER') !== false);
}

function is_position_eligible($course_code, $position)
{
    $code = strtoupper(trim((string)$course_code));
    $pos  = strtoupper(trim((string)$position));

    // Normalize whitespace (matches dashboard)
    $pos = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\s]+/u', ' ', $pos);

    $is_mgmt = is_sms_management_role($position);

    if ($code === 'SMS-MGR') return $is_mgmt;
    if ($code === 'SMSMRO')  return !$is_mgmt;

    if ($code === 'ETTPM') {
        return (strpos($pos, 'TEAM LEADER') !== false)
            || (strpos($pos, 'DIR') !== false)
            || (strpos($pos, 'MANAGER') !== false)
            || (strpos($pos, 'MGR') !== false);
    }

    if ($code === 'IP') {
        return (strpos($pos, 'TECHNICIAN III') !== false)
            || (strpos($pos, 'TECHNICIAN IV')  !== false)
            || (strpos($pos, 'TEAM LEADER')    !== false)
            || (strpos($pos, 'FLEET')          !== false)
            || (strpos($pos, 'INSPECTOR')      !== false)
            || (strpos($pos, 'MANAGER')        !== false);
    }

    if ($code === 'MEL') {
        if (strpos($pos, 'MECHANIC') !== false) return false;
    }

    if ($code === 'DGR' || $code === 'RCAT') {
        if (
            strpos($pos, 'TEAM LEADER') !== false
            || strpos($pos, 'MGR')         !== false
            || strpos($pos, 'MANAGER')     !== false
        ) return false;
    }

    return true;
}

function is_employee_eligible($cc_course_map, $cc, $course_code, $position)
{
    global $cc_position_overrides;

    return is_course_required($cc_course_map, $cc, $course_code, $position, $cc_position_overrides ?? [])
        && is_position_eligible($course_code, $position);
}

// -----------------------------------------------------------------
// Force refresh
// -----------------------------------------------------------------
$force_refresh = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_refresh']));

// -----------------------------------------------------------------
// to_be_days
// -----------------------------------------------------------------
if (isset($_GET['to_be_days']))       $to_be_days = max(0, (int)$_GET['to_be_days']);
elseif (isset($_POST['to_be_days']))  $to_be_days = max(0, (int)$_POST['to_be_days']);
else                                  $to_be_days = (int)($_SESSION['dept_gap_to_be_days'] ?? 90);
$_SESSION['dept_gap_to_be_days'] = $to_be_days;

// -----------------------------------------------------------------
// Gap type filter (All / Initial / Expired / To-be)
// -----------------------------------------------------------------
$gap_filter = $_GET['gap_type'] ?? ($_SESSION['dept_gap_filter'] ?? 'expired');
if (!in_array($gap_filter, ['all', 'initial', 'expired', 'tobe'], true)) $gap_filter = 'expired';
$_SESSION['dept_gap_filter'] = $gap_filter;

// -----------------------------------------------------------------
// Staff category filter (All / Technical / Non-Technical)
// Default = technical
// -----------------------------------------------------------------
$staff_filter = $_GET['staff_cat'] ?? ($_SESSION['dept_gap_staff'] ?? 'technical');
if (!in_array($staff_filter, ['all', 'technical', 'non_technical'], true)) $staff_filter = 'technical';
$_SESSION['dept_gap_staff'] = $staff_filter;

// -----------------------------------------------------------------
// Certifying Technician filter (off / on)
// -----------------------------------------------------------------
$cert_filter = $_GET['cert_tech'] ?? ($_SESSION['dept_gap_cert'] ?? 'off');
if (!in_array($cert_filter, ['off', 'on'], true)) $cert_filter = 'off';
$_SESSION['dept_gap_cert'] = $cert_filter;

// -----------------------------------------------------------------
// Load CC → Department map
// -----------------------------------------------------------------
$cc_dept_map = [];
$all_departments = [];
try {
    $rows = $pdo->query("SELECT cost_center, department FROM cost_center_departments")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $cc_dept_map[trim($r['cost_center'])] = trim($r['department']);
    }
    $all_departments = array_values(array_unique(array_values($cc_dept_map)));
    sort($all_departments);
} catch (Exception $e) {
    $cc_dept_map = [];
    $all_departments = [];
}

// Apply staff filter to department list
$departments = [];
foreach ($all_departments as $d) {
    if ($staff_filter === 'technical' && !in_array($d, $GLOBALS['TECHNICAL_DEPTS'], true)) continue;
    if ($staff_filter === 'non_technical' && !in_array($d, $GLOBALS['NON_TECHNICAL_DEPTS'], true)) continue;
    $departments[] = $d;
}

// -----------------------------------------------------------------
// Load CC → Course map
// -----------------------------------------------------------------
$cc_course_map = [];
try {
    $map_rows = $pdo->query("SELECT cost_center, course_code FROM cc_course_requirements")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($map_rows as $r) {
        $cc_course_map[trim($r['cost_center'])][trim($r['course_code'])] = true;
    }
} catch (Exception $e) {
    $cc_course_map = [];
}

// -----------------------------------------------------------------
// Load CC + Position → Course overrides ONCE
// -----------------------------------------------------------------
$cc_position_overrides = load_cc_position_overrides($pdo);

// -----------------------------------------------------------------
// Load active courses
// -----------------------------------------------------------------
$courses = $pdo->query("
    SELECT course_id, course_code, course_name,
           COALESCE(validity_months,24) AS validity_months,
           TRIM(table_name) AS table_name
    FROM courses
    WHERE is_active = 1 AND table_name IS NOT NULL
    ORDER BY course_code
")->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------
// Load active employees (filtered by department category + cert filter)
// -----------------------------------------------------------------
$employees = [];
$unmapped_ccs = [];
foreach ($pdo->query("SELECT employee_id, full_name, position, cost_center FROM employees WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $cc = trim((string)$e['cost_center']);
    if ($cc === '') continue;
    if (!isset($cc_dept_map[$cc])) {
        $unmapped_ccs[$cc] = ($unmapped_ccs[$cc] ?? 0) + 1;
        continue;
    }
    $dept = $cc_dept_map[$cc];

    // Apply staff filter at employee level so counts reflect only selected category
    if ($staff_filter === 'technical' && !in_array($dept, $GLOBALS['TECHNICAL_DEPTS'], true)) continue;
    if ($staff_filter === 'non_technical' && !in_array($dept, $GLOBALS['NON_TECHNICAL_DEPTS'], true)) continue;

    // Apply Certifying Technician filter
    if ($cert_filter === 'on' && !isset($CERTIFYING_TECHNICIAN_LOOKUP[(string)$e['employee_id']])) continue;

    $e['cost_center'] = $cc;
    $e['department']  = $dept;
    $employees[] = $e;
}

// =================================================================
// EXPORT HANDLERS
// =================================================================
if (isset($_GET['export'])) {

    $matrix = build_matrix($pdo, $courses, $employees, $cc_course_map, $to_be_days, $departments, $cc_position_overrides);

    $scope = $_GET['export'];
    $code  = $_GET['course'] ?? '';
    $dept  = $_GET['dept']   ?? '';
    $type  = $_GET['type']   ?? '';

    $filename_parts = ['dept_gap'];
    $rows_to_export = [];

    $headers = [
        'Course Code',
        'Course Name',
        'Employee ID',
        'Employee Name',
        'Cost Center',
        'Department',
        'Position',
        'Completed Date',
        'Expiry Date',
        'Gap Type'
    ];

    // Helper: does a row ($r) match the active gap_type filter?
    $matches_gap_filter = function ($gapTypeStr) use ($gap_filter) {
        if ($gap_filter === 'all') return true;
        $lc = strtolower($gapTypeStr);
        if ($gap_filter === 'initial') return $lc === 'initial';
        if ($gap_filter === 'expired') return $lc === 'expired';
        if ($gap_filter === 'tobe')    return in_array($lc, ['tobe', 'to-be', 'to_be', 'to be expired'], true);
        return true;
    };

    if ($scope === 'cell' && $code !== '' && $dept !== '') {
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $code);
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $dept);
        foreach (($matrix['drilldown'][$code][$dept] ?? []) as $r) {
            if ($matches_gap_filter($r[9])) $rows_to_export[] = $r;
        }
    } elseif ($scope === 'course' && $code !== '') {
        $filename_parts[] = 'course';
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $code);
        foreach ($departments as $d) {
            foreach (($matrix['drilldown'][$code][$d] ?? []) as $r) {
                if ($matches_gap_filter($r[9])) $rows_to_export[] = $r;
            }
        }
    } elseif ($scope === 'dept' && $dept !== '') {
        $filename_parts[] = 'dept';
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $dept);
        foreach ($courses as $c) {
            $cc = trim($c['course_code']);
            foreach (($matrix['drilldown'][$cc][$dept] ?? []) as $r) {
                if ($matches_gap_filter($r[9])) $rows_to_export[] = $r;
            }
        }
    } elseif ($scope === 'total') {
        $filename_parts[] = 'all';
        foreach ($courses as $c) {
            $cc = trim($c['course_code']);
            foreach ($departments as $d) {
                foreach (($matrix['drilldown'][$cc][$d] ?? []) as $r) {
                    if ($matches_gap_filter($r[9])) $rows_to_export[] = $r;
                }
            }
        }
    } elseif ($scope === 'summary' && $type !== '') {
        $filename_parts[] = 'summary';
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $type);
        if ($dept !== '') {
            $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $dept);
        }
        $target_depts = ($dept !== '') ? [$dept] : $departments;
        foreach ($courses as $c) {
            $cc = trim($c['course_code']);
            foreach ($target_depts as $d) {
                foreach (($matrix['drilldown'][$cc][$d] ?? []) as $r) {
                    $gap_type_lc = strtolower($r[9]);
                    $include = false;
                    switch ($type) {
                        case 'expired':
                            $include = ($gap_type_lc === 'expired');
                            break;
                        case 'tobe':
                            $include = in_array($gap_type_lc, ['tobe', 'to-be', 'to_be'], true);
                            break;
                        case 'initial':
                            $include = ($gap_type_lc === 'initial');
                            break;
                        case 'gap_active':
                            $include = in_array($gap_type_lc, ['expired', 'initial'], true);
                            break;
                        case 'gap_total':
                            $include = in_array($gap_type_lc, ['expired', 'initial', 'tobe', 'to-be', 'to_be'], true);
                            break;
                    }
                    if ($include && $matches_gap_filter($r[9])) $rows_to_export[] = $r;
                }
            }
        }
    } else {
        header('HTTP/1.1 400 Bad Request');
        echo "Invalid export scope";
        exit;
    }

    usort($rows_to_export, function ($a, $b) {
        $cmp = strcmp($a[0] . '|' . $a[5], $b[0] . '|' . $b[5]);
        if ($cmp === 0) $cmp = strcmp($a[2], $b[2]);
        return $cmp;
    });

    if ($gap_filter !== 'all') $filename_parts[] = $gap_filter;
    if ($staff_filter !== 'all') $filename_parts[] = $staff_filter;
    if ($cert_filter !== 'off') $filename_parts[] = 'cert_tech';

    $filename = implode('_', $filename_parts) . '_' . date('Ymd_His') . '.csv';

    while (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows_to_export as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// =================================================================
// BUILD MATRIX
// =================================================================
function build_matrix($pdo, $courses, $employees, $cc_course_map, $to_be_days, $departments, $cc_position_overrides = [])
{
    $matrix = [
        'gap'        => [],
        'expired'    => [],
        'tobe'       => [],
        'initial'    => [],
        'na'         => [],
        'drilldown'  => [],
        'dept_totals_gap'     => [],
        'dept_totals_expired' => [],
        'dept_totals_initial' => [],
        'dept_totals_tobe'    => [],
        'grand_gap'           => 0,
        'grand_expired'       => 0,
        'grand_initial'       => 0,
        'grand_tobe'          => 0,
    ];

    $emp_by_dept = [];
    foreach ($employees as $e) {
        $emp_by_dept[$e['department']][] = $e;
    }

    $today = new DateTime('today');

    foreach ($courses as $c) {
        $course_code = trim($c['course_code']);
        $course_name = $c['course_name'];
        $tbl         = $c['table_name'];
        $valid       = (int)$c['validity_months'];
        $never_expire = in_array($course_code, $GLOBALS['NEVER_EXPIRE_COURSES']) || $valid >= FOREVER_THRESHOLD;

        if (!safe_table($tbl)) continue;

        $completed = [];
        try {
            $stmt = $pdo->query("SELECT ID, CompletedDate, Status FROM `{$tbl}` WHERE CompletedDate IS NOT NULL AND CompletedDate != '' AND CompletedDate != '0000-00-00'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $completed[$row['ID']] = $row['CompletedDate'];
            }
        } catch (Exception $e) {
            $completed = [];
        }

        $required_ccs_by_dept = [];
        foreach ($cc_course_map as $cc => $codes) {
            if (!isset($codes[$course_code])) continue;
            $d = $GLOBALS['cc_dept_map'][$cc] ?? null;
            if ($d !== null) $required_ccs_by_dept[$d] = true;
        }

        foreach ($departments as $dept) {
            if (!isset($required_ccs_by_dept[$dept])) {
                $matrix['na'][$course_code][$dept] = true;
                $matrix['gap'][$course_code][$dept]     = 0;
                $matrix['expired'][$course_code][$dept] = 0;
                $matrix['tobe'][$course_code][$dept]    = 0;
                $matrix['initial'][$course_code][$dept] = 0;
                continue;
            }

            $matrix['na'][$course_code][$dept]      = false;
            $matrix['gap'][$course_code][$dept]     = 0;
            $matrix['expired'][$course_code][$dept] = 0;
            $matrix['tobe'][$course_code][$dept]    = 0;
            $matrix['initial'][$course_code][$dept] = 0;

            foreach (($emp_by_dept[$dept] ?? []) as $e) {
                $cc = $e['cost_center'];
                $pos = $e['position'] ?? '';

                if (!is_employee_eligible($cc_course_map, $cc, $course_code, $pos)) continue;

                // ── SKIP override → treat as not applicable ──
                if (is_forced_skipped_by_override($cc_position_overrides, $cc, $pos, $course_code, $dept)) {
                    continue;
                }

                // ── FOREVER override → per-employee never-expire ──
                $is_forever_for_emp = $never_expire
                    || is_forced_forever_by_override($cc_position_overrides, $cc, $pos, $course_code, $dept);

                $emp_id = $e['employee_id'];
                $gap_type = 'ok';
                $expiry_date = null;
                $completed_date = null;

                if (!isset($completed[$emp_id])) {
                    $gap_type = 'initial';
                } else {
                    $completed_date = $completed[$emp_id];
                    $clean_date = substr(trim($completed_date), 0, 10);
                    $cdt = DateTime::createFromFormat('Y-m-d', $clean_date);
                    if (!$cdt) $cdt = date_create($clean_date);

                    if (!$cdt) {
                        $gap_type = 'initial';
                    } elseif ($is_forever_for_emp) {
                        $gap_type = 'ok';
                    } else {
                        $expiry = (clone $cdt)->modify("+{$valid} months");
                        $expiry_date = $expiry->format('Y-m-d');
                        if ($expiry < $today) {
                            $gap_type = 'expired';
                        } else {
                            $days = (int)$today->diff($expiry)->days;
                            if ($days <= $to_be_days) $gap_type = 'tobe';
                            else $gap_type = 'ok';
                        }
                    }
                }

                if ($gap_type === 'ok') continue;

                if ($gap_type === 'expired') {
                    $matrix['expired'][$course_code][$dept]++;
                    $matrix['gap'][$course_code][$dept]++;
                } elseif ($gap_type === 'tobe') {
                    $matrix['tobe'][$course_code][$dept]++;
                    $matrix['gap'][$course_code][$dept]++;
                } elseif ($gap_type === 'initial') {
                    $matrix['initial'][$course_code][$dept]++;
                }

                $matrix['drilldown'][$course_code][$dept][] = [
                    $course_code,
                    $course_name,
                    $emp_id,
                    $e['full_name'],
                    $cc,
                    $dept,
                    $pos,
                    $completed_date ?? 'N/A',
                    $expiry_date ?? 'N/A',
                    ucfirst($gap_type)
                ];
            }

            $matrix['dept_totals_gap'][$dept]     = ($matrix['dept_totals_gap'][$dept] ?? 0)     + $matrix['gap'][$course_code][$dept];
            $matrix['dept_totals_expired'][$dept] = ($matrix['dept_totals_expired'][$dept] ?? 0) + $matrix['expired'][$course_code][$dept];
            $matrix['dept_totals_initial'][$dept] = ($matrix['dept_totals_initial'][$dept] ?? 0) + $matrix['initial'][$course_code][$dept];
            $matrix['dept_totals_tobe'][$dept]    = ($matrix['dept_totals_tobe'][$dept] ?? 0)    + $matrix['tobe'][$course_code][$dept];

            $matrix['grand_gap']     += $matrix['gap'][$course_code][$dept];
            $matrix['grand_expired'] += $matrix['expired'][$course_code][$dept];
            $matrix['grand_initial'] += $matrix['initial'][$course_code][$dept];
            $matrix['grand_tobe']    += $matrix['tobe'][$course_code][$dept];
        }
    }

    return $matrix;
}

// =================================================================
// CACHE (key includes all filters)
// =================================================================
$cache_key  = "dept_gap_v6_tobed_{$to_be_days}_gap_{$gap_filter}_staff_{$staff_filter}_cert_{$cert_filter}";
$cache_file = sys_get_temp_dir() . "/gct_{$cache_key}.cache";

if ($force_refresh && file_exists($cache_file)) {
    unlink($cache_file);
}

$use_cache = file_exists($cache_file) && (time() - filemtime($cache_file)) < $CACHE_TTL;

if ($use_cache) {
    $cached = unserialize(file_get_contents($cache_file));
    $matrix = $cached['matrix'];
    $last_cache_update = date('H:i:s', filemtime($cache_file));
    $next_cache_update = date('H:i:s', time() + ($CACHE_TTL - (time() - filemtime($cache_file))));
} else {
    $matrix = build_matrix($pdo, $courses, $employees, $cc_course_map, $to_be_days, $departments, $cc_position_overrides);
    file_put_contents($cache_file, serialize(['matrix' => $matrix]));
    $last_cache_update = 'Just now';
    $next_cache_update = date('H:i:s', time() + $CACHE_TTL);
}

// =================================================================
// BAR CHART DATA — reflects the active gap type filter
// =================================================================
$pie_labels = [];
$pie_values = [];
foreach ($departments as $d) {
    if ($gap_filter === 'expired') {
        $val = $matrix['dept_totals_expired'][$d] ?? 0;
    } elseif ($gap_filter === 'initial') {
        $val = $matrix['dept_totals_initial'][$d] ?? 0;
    } elseif ($gap_filter === 'tobe') {
        $val = $matrix['dept_totals_tobe'][$d] ?? 0;
    } else {
        $val = $matrix['dept_totals_gap'][$d] ?? 0;
    }
    if ($val > 0) {
        $pie_labels[] = $d;
        $pie_values[] = $val;
    }
}

// Sort bars descending so the biggest gaps are first (left-to-right for vertical bars)
if (!empty($pie_values)) {
    $order = array_keys($pie_values);
    usort($order, function ($a, $b) use ($pie_values) {
        return $pie_values[$b] <=> $pie_values[$a];
    });
    $sorted_labels = [];
    $sorted_values = [];
    foreach ($order as $i) {
        $sorted_labels[] = $pie_labels[$i];
        $sorted_values[] = $pie_values[$i];
    }
    $pie_labels = $sorted_labels;
    $pie_values = $sorted_values;
}

$js_pie_labels = json_encode($pie_labels);
$js_pie_values = json_encode($pie_values);

// Build a course_code => course_name lookup for the tooltip
$course_name_map = [];
foreach ($courses as $c) {
    $course_name_map[trim($c['course_code'])] = $c['course_name'];
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">


    <title>Department Training GAP Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .matrix-table th,
        .matrix-table td {
            border: 1px solid #e5e7eb;
            padding: 6px 10px;
            text-align: center;
            white-space: nowrap;
        }

        .matrix-table thead th {
            background: #1e3a8a;
            color: #fff;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .matrix-table thead th.course-col {
            background: #1e40af;
            text-align: left;
            position: sticky;
            left: 0;
            z-index: 11;
        }

        .matrix-table tbody td.course-col {
            text-align: left;
            font-weight: 500;
            background: #f8fafc;
            position: sticky;
            left: 0;
            z-index: 5;
            cursor: help;
        }

        .matrix-table tbody tr:hover td {
            background: #eff6ff;
        }

        .matrix-table tbody tr:hover td.course-col {
            background: #dbeafe;
        }

        .cell-clickable {
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .cell-clickable:hover {
            background: #fde68a !important;
            outline: 2px solid #f59e0b;
            outline-offset: -2px;
        }

        .na-cell {
            color: #9ca3af;
            font-style: italic;
            background: #f3f4f6 !important;
        }

        .zero-cell {
            color: #9ca3af;
        }

        .gap-cell {
            font-weight: 700;
            color: #b91c1c;
            background: #fef2f2 !important;
        }

        .tobe-cell {
            font-weight: 700;
            color: #b45309;
            background: #fffbeb !important;
        }

        .ini-cell {
            font-weight: 600;
            color: #a16207;
        }

        .total-col {
            background: #fef9c3 !important;
            font-weight: 700;
            color: #854d0e;
        }

        .total-row td {
            background: #f1f5f9 !important;
            font-weight: 700;
            color: #0f172a;
        }

        .total-row td.course-col {
            background: #e2e8f0 !important;
        }

        .summary-row td {
            background: #f8fafc !important;
            font-weight: 700;
            color: #0f172a;
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .summary-row td.course-col {
            background: #e2e8f0 !important;
            text-align: left;
        }

        .summary-row td:hover {
            background: #fde68a !important;
            outline: 2px solid #f59e0b;
            outline-offset: -2px;
        }

        .summary-row td.course-col:hover {
            background: #fde68a !important;
        }

        .expired-row td {
            color: #b91c1c !important;
        }

        .tobe-row td {
            color: #b45309 !important;
        }

        .initial-row td {
            color: #a16207 !important;
        }

        .totalgap-row td {
            color: #588d01 !important;
        }

        .dept-header {
            background: #588d01 !important;
        }

        .clickable-header:hover {
            background: #81c513 !important;
            cursor: pointer;
        }

        .cache-info {
            font-size: 11px;
            color: #6b7280;
        }

        .warn-banner {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
        }

        .matrix-wrap {
            overflow: auto;
            max-height: 70vh;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dbeafe;
            color: #1e40af;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Vertical bar chart canvas height */
        .bar-chart-wrap {
            position: relative;
            height: 380px;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">

    <div class="max-w-7xl mx-auto p-6">

        <div class="flex items-start justify-between mb-4 flex-wrap gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">MRO Training GAP — by Department</h1>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <form method="GET" class="flex items-center gap-2" id="toBeForm">
                    <input type="hidden" name="page" value="department_gap_report">
                    <input type="hidden" name="gap_type" value="<?= htmlspecialchars($gap_filter) ?>">
                    <input type="hidden" name="staff_cat" value="<?= htmlspecialchars($staff_filter) ?>">
                    <input type="hidden" name="cert_tech" value="<?= htmlspecialchars($cert_filter) ?>">
                    <label class="text-sm text-gray-600">To-be window (days):</label>
                    <input type="number" name="to_be_days" min="0"
                        value="<?= htmlspecialchars($to_be_days) ?>"
                        class="w-20 px-2 py-1 border rounded text-sm">
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">Apply</button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="force_refresh" value="1">
                    <input type="hidden" name="to_be_days" value="<?= htmlspecialchars($to_be_days) ?>">
                    <input type="hidden" name="gap_type" value="<?= htmlspecialchars($gap_filter) ?>">
                    <input type="hidden" name="staff_cat" value="<?= htmlspecialchars($staff_filter) ?>">
                    <input type="hidden" name="cert_tech" value="<?= htmlspecialchars($cert_filter) ?>">
                    <button type="submit"
                        class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1 rounded text-sm font-medium"
                        onclick="return confirm('Force refresh from the database?');">
                        🔄 Force Refresh
                    </button>
                </form>
            </div>
        </div>

        <div class="cache-info text-right mb-4">
            <?php if ($use_cache): ?>
                Cached (<?= $last_cache_update ?>) — next auto-refresh: <?= $next_cache_update ?>
            <?php else: ?>
                Live data (<?= $last_cache_update ?>)
            <?php endif; ?>
        </div>

        <?php if (!empty($unmapped_ccs)): ?>
            <div class="warn-banner mb-4">
                <strong>⚠️ Unmapped Cost Centers:</strong>
                <?= count($unmapped_ccs) ?> cost center(s) with active employees are missing from
                <code>cost_center_departments</code> — employees in those CCs are excluded from this report.
                <div class="mt-1 font-mono text-xs">
                    <?php foreach ($unmapped_ccs as $cc => $n): ?>
                        <?= htmlspecialchars($cc) ?> (<?= $n ?>),
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════
             STACKED LAYOUT:
               1) Summary (Total Employees / Expired / To-be)  ← top
               2) GAP by Department (bar chart)                ← bottom
             ═══════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 gap-6 mb-6">

            <!-- ── 1. Summary card — now ABOVE the chart ── -->
            <div class="bg-white p-5 rounded-xl shadow">
                <h3 class="font-bold mb-3 text-gray-700">
                    Summary
                    <?php if ($gap_filter !== 'all'): ?>
                        — showing <span class="text-blue-700"><?= htmlspecialchars(ucfirst($gap_filter)) ?></span> only
                    <?php endif; ?>
                    <?php if ($staff_filter !== 'all'): ?>
                        — <span class="text-blue-700"><?= $staff_filter === 'technical' ? 'Technical' : 'Non-Technical' ?></span>
                    <?php endif; ?>
                    <?php if ($cert_filter === 'on'): ?>
                        — <span class="text-blue-700">Certifying Technicians</span>
                    <?php endif; ?>
                </h3>

                <?php
                $highlight_expired = ($gap_filter === 'expired');
                $highlight_tobe    = ($gap_filter === 'tobe');
                $highlight_initial = ($gap_filter === 'initial');
                $highlight_gap     = ($gap_filter === 'all');
                ?>
                <!-- Added Total Employees card on the LEFT, then Expired, then To-be -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

                    <!-- Total Employees Box -->
                    <div class="bg-emerald-50 border border-emerald-200 rounded p-3">
                        <div class="text-xs uppercase text-emerald-700">Total Employees</div>
                        <div class="text-2xl font-bold text-emerald-700"><?= number_format(count($employees)) ?></div>
                        <?php if ($staff_filter !== 'all' || $cert_filter === 'on'): ?>
                            <div class="text-[10px] text-emerald-600 mt-1">
                                <?php
                                $bits = [];
                                if ($staff_filter !== 'all') $bits[] = $staff_filter === 'technical' ? 'Technical' : 'Non-Technical';
                                if ($cert_filter === 'on')   $bits[] = 'Certifying only';
                                echo htmlspecialchars(implode(' • ', $bits));
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Expired Box -->
                    <div class="bg-red-50 border <?= $highlight_expired ? 'ring-2 ring-red-400' : 'border-red-200' ?> rounded p-3">
                        <div class="text-xs uppercase text-red-700">Expired</div>
                        <div class="text-2xl font-bold text-red-700"><?= number_format($matrix['grand_expired']) ?></div>
                    </div>

                    <!-- To-be Expired Box -->
                    <div class="bg-amber-50 border <?= $highlight_tobe ? 'ring-2 ring-amber-400' : 'border-amber-200' ?> rounded p-3">
                        <div class="text-xs uppercase text-amber-700">To-be expired</div>
                        <div class="text-2xl font-bold text-amber-700"><?= number_format($matrix['grand_tobe']) ?></div>
                    </div>
                </div>
            </div>

            <!-- ── 2. GAP by Department — VERTICAL bar chart ── -->
            <div class="bg-white p-5 rounded-xl shadow">
                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <h3 class="font-bold text-gray-700">GAP by Department</h3>
                    <form method="GET" class="flex items-center gap-2 flex-wrap">
                        <input type="hidden" name="page" value="department_gap_report">
                        <input type="hidden" name="to_be_days" value="<?= htmlspecialchars($to_be_days) ?>">
                        <input type="hidden" name="gap_type" value="<?= htmlspecialchars($gap_filter) ?>">
                        <select name="staff_cat" onchange="this.form.submit()"
                            class="text-xs border border-gray-300 rounded px-2 py-1 bg-white">
                            <option value="all" <?= $staff_filter === 'all' ? 'selected' : '' ?>>All Staff</option>
                            <option value="technical" <?= $staff_filter === 'technical' ? 'selected' : '' ?>>Technical</option>
                            <option value="non_technical" <?= $staff_filter === 'non_technical' ? 'selected' : '' ?>>Non-Technical</option>
                        </select>
                        <select name="cert_tech" onchange="this.form.submit()"
                            class="text-xs border border-gray-300 rounded px-2 py-1 bg-white">
                            <option value="off" <?= $cert_filter === 'off' ? 'selected' : '' ?>>All Technicians</option>
                            <option value="on" <?= $cert_filter === 'on'  ? 'selected' : '' ?>>Certifying Technician</option>
                        </select>
                    </form>
                </div>

                <?php if ($staff_filter !== 'all' || $cert_filter === 'on'): ?>
                    <div class="mb-2 text-center">
                        <span class="filter-pill">
                            <?php if ($staff_filter !== 'all'): ?>
                                <?= $staff_filter === 'technical' ? 'Technical' : 'Non-Technical' ?>
                            <?php endif; ?>
                            <?php if ($staff_filter !== 'all' && $cert_filter === 'on'): ?>
                                <span class="text-gray-400">•</span>
                            <?php endif; ?>
                            <?php if ($cert_filter === 'on'): ?>
                                Certifying Technician
                            <?php endif; ?>
                            <span class="text-gray-400">•</span>
                            <?= count($departments) ?> dept<?= count($departments) != 1 ? 's' : '' ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if (empty($pie_values)): ?>
                    <div class="text-center text-gray-400 py-8 text-sm">No data for selected filters</div>
                <?php else: ?>
                    <div class="bar-chart-wrap">
                        <canvas id="deptBarChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Matrix table -->
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <h3 class="font-bold text-gray-700">
                    Course × Department —
                    <?php
                    switch ($gap_filter) {
                        case 'expired':
                            echo '<span class="text-red-700">Expired</span>';
                            break;
                        case 'initial':
                            echo '<span class="text-yellow-700">Initial (not taken)</span>';
                            break;
                        case 'tobe':
                            echo '<span class="text-amber-700">To-be expired</span>';
                            break;
                        default:
                            echo '<span class="text-blue-700">Training GAP</span>';
                    }
                    ?>
                </h3>

                <div class="flex items-center gap-3 flex-wrap">
                    <form method="GET" class="flex items-center gap-2">
                        <input type="hidden" name="page" value="department_gap_report">
                        <input type="hidden" name="to_be_days" value="<?= htmlspecialchars($to_be_days) ?>">
                        <input type="hidden" name="staff_cat" value="<?= htmlspecialchars($staff_filter) ?>">
                        <input type="hidden" name="cert_tech" value="<?= htmlspecialchars($cert_filter) ?>">
                        <label class="text-sm text-gray-600">Show:</label>
                        <select name="gap_type" onchange="this.form.submit()"
                            class="text-sm border border-gray-300 rounded px-2 py-1 bg-white">
                            <option value="all" <?= $gap_filter === 'all' ? 'selected' : '' ?>>All (GAP = Expired + To-be)</option>
                            <option value="expired" <?= $gap_filter === 'expired' ? 'selected' : '' ?>>Expired only</option>
                            <option value="initial" <?= $gap_filter === 'initial' ? 'selected' : '' ?>>Initial only (not taken)</option>
                            <option value="tobe" <?= $gap_filter === 'tobe' ? 'selected' : '' ?>>To-be expired only</option>
                        </select>
                    </form>

                    <a href="?page=department_gap_report&export=total&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>"
                        class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded text-sm font-medium">
                        📥 Export Filtered
                    </a>
                </div>
            </div>

            <div class="matrix-wrap">
                <table class="matrix-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="course-col">Course</th>
                            <?php foreach ($departments as $d): ?>
                                <th class="dept-header clickable-header"
                                    onclick="location.href='?page=department_gap_report&export=dept&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export all <?= htmlspecialchars($d) ?> rows">
                                    Dir. <?= htmlspecialchars($d) ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="total-col clickable-header"
                                style="color: black !important;"
                                onclick="location.href='?page=department_gap_report&export=total&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                title="Click to export all rows">
                                <?php
                                switch ($gap_filter) {
                                    case 'expired':
                                        echo 'Total Expired';
                                        break;
                                    case 'initial':
                                        echo 'Total Initial';
                                        break;
                                    case 'tobe':
                                        echo 'Total To-be';
                                        break;
                                    default:
                                        echo 'Total GAP';
                                }
                                ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $c):
                            $code = trim($c['course_code']);
                            $c_name = $course_name_map[$code] ?? '';

                            $row_total = 0;
                            foreach ($departments as $d) {
                                if ($gap_filter === 'expired')      $row_total += $matrix['expired'][$code][$d] ?? 0;
                                elseif ($gap_filter === 'initial')  $row_total += $matrix['initial'][$code][$d] ?? 0;
                                elseif ($gap_filter === 'tobe')     $row_total += $matrix['tobe'][$code][$d] ?? 0;
                                else                                $row_total += $matrix['gap'][$code][$d] ?? 0;
                            }
                        ?>
                            <tr>
                                <!-- Full course name shown on hover -->
                                <td class="course-col" title="<?= htmlspecialchars($c_name) ?>">
                                    <?= htmlspecialchars($code) ?>
                                </td>
                                <?php foreach ($departments as $d):
                                    $is_na = !empty($matrix['na'][$code][$d]);
                                    $gap   = $matrix['gap'][$code][$d] ?? 0;
                                    $exp   = $matrix['expired'][$code][$d] ?? 0;
                                    $tobe  = $matrix['tobe'][$code][$d] ?? 0;
                                    $ini   = $matrix['initial'][$code][$d] ?? 0;

                                    if ($gap_filter === 'expired')      $cell_val = $exp;
                                    elseif ($gap_filter === 'initial')  $cell_val = $ini;
                                    elseif ($gap_filter === 'tobe')     $cell_val = $tobe;
                                    else                                $cell_val = $gap;

                                    if ($gap_filter === 'initial')      $cell_class = $cell_val > 0 ? 'ini-cell' : 'zero-cell';
                                    elseif ($gap_filter === 'tobe')     $cell_class = $cell_val > 0 ? 'tobe-cell' : 'zero-cell';
                                    else                                $cell_class = $cell_val > 0 ? 'gap-cell' : 'zero-cell';
                                ?>
                                    <?php if ($is_na): ?>
                                        <td class="na-cell">N/A</td>
                                    <?php else: ?>
                                        <td class="cell-clickable <?= $cell_class ?>"
                                            onclick="location.href='?page=department_gap_report&export=cell&course=<?= urlencode($code) ?>&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                            title="Expired: <?= $exp ?> | To-be: <?= $tobe ?> | Initial: <?= $ini ?> (click to export)">
                                            <?= $cell_val ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <td class="total-col cell-clickable"
                                    onclick="location.href='?page=department_gap_report&export=course&course=<?= urlencode($code) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export all <?= htmlspecialchars($code) ?> rows">
                                    <?= $row_total ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($gap_filter === 'all'): ?>
                            <tr class="summary-row expired-row">
                                <td class="course-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=expired&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export ALL expired rows across all departments">
                                    Expired
                                </td>
                                <?php foreach ($departments as $d): ?>
                                    <td onclick="location.href='?page=department_gap_report&export=summary&type=expired&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                        title="Click to export expired rows for <?= htmlspecialchars($d) ?>">
                                        <?= number_format($matrix['dept_totals_expired'][$d] ?? 0) ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="total-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=expired&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export all expired rows">
                                    <?= number_format($matrix['grand_expired']) ?>
                                </td>
                            </tr>

                            <tr class="summary-row initial-row">
                                <td class="course-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=initial&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export ALL initial rows across all departments">
                                    Initial training not taken
                                </td>
                                <?php foreach ($departments as $d): ?>
                                    <td onclick="location.href='?page=department_gap_report&export=summary&type=initial&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                        title="Click to export initial rows for <?= htmlspecialchars($d) ?>">
                                        <?= number_format($matrix['dept_totals_initial'][$d] ?? 0) ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="total-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=initial&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export all initial rows">
                                    <?= number_format($matrix['grand_initial']) ?>
                                </td>
                            </tr>

                            <tr class="summary-row totalgap-row">
                                <td class="course-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=gap_active&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export ALL expired + initial rows">
                                    Total GAP
                                </td>
                                <?php foreach ($departments as $d):
                                    $v = ($matrix['dept_totals_expired'][$d] ?? 0) + ($matrix['dept_totals_initial'][$d] ?? 0);
                                ?>
                                    <td onclick="location.href='?page=department_gap_report&export=summary&type=gap_active&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                        title="Click to export expired + initial rows for <?= htmlspecialchars($d) ?>">
                                        <?= number_format($v) ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="total-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=gap_active&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export all expired + initial rows">
                                    <?= number_format($matrix['grand_expired'] + $matrix['grand_initial']) ?>
                                </td>
                            </tr>

                            <tr>
                                <td colspan="<?= count($departments) + 2 ?>" style="height:12px; background:transparent; border:none;"></td>
                            </tr>

                            <tr class="summary-row tobe-row">
                                <td class="course-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=tobe&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export ALL to-be-expired rows across all departments">
                                    To be expired
                                </td>
                                <?php foreach ($departments as $d): ?>
                                    <td onclick="location.href='?page=department_gap_report&export=summary&type=tobe&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                        title="Click to export to-be-expired rows for <?= htmlspecialchars($d) ?>">
                                        <?= number_format($matrix['dept_totals_tobe'][$d] ?? 0) ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="total-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=tobe&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export all to-be-expired rows">
                                    <?= number_format($matrix['grand_tobe']) ?>
                                </td>
                            </tr>

                            <tr class="summary-row totalgap-row">
                                <td class="course-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=gap_total&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export ALL expired + initial + to-be rows">
                                    Total GAP
                                </td>
                                <?php foreach ($departments as $d):
                                    $v = ($matrix['dept_totals_expired'][$d] ?? 0)
                                        + ($matrix['dept_totals_initial'][$d] ?? 0)
                                        + ($matrix['dept_totals_tobe'][$d] ?? 0);
                                ?>
                                    <td onclick="location.href='?page=department_gap_report&export=summary&type=gap_total&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                        title="Click to export expired + initial + to-be rows for <?= htmlspecialchars($d) ?>">
                                        <?= number_format($v) ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="total-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=gap_total&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export all expired + initial + to-be rows">
                                    <?= number_format($matrix['grand_expired'] + $matrix['grand_initial'] + $matrix['grand_tobe']) ?>
                                </td>
                            </tr>

                        <?php else: ?>
                            <?php
                            if ($gap_filter === 'initial') {
                                $summary_label   = 'Total GAP (ONLY Initial)';
                                $summary_metric  = 'dept_totals_initial';
                                $summary_grand   = 'grand_initial';
                                $summary_type    = 'initial';
                                $summary_css     = 'initial-row';
                            } elseif ($gap_filter === 'expired') {
                                $summary_label   = 'Total GAP (Expired)';
                                $summary_metric  = 'dept_totals_expired';
                                $summary_grand   = 'grand_expired';
                                $summary_type    = 'expired';
                                $summary_css     = 'expired-row';
                            } else { // tobe
                                $summary_label   = 'Total GAP (To be expired)';
                                $summary_metric  = 'dept_totals_tobe';
                                $summary_grand   = 'grand_tobe';
                                $summary_type    = 'tobe';
                                $summary_css     = 'tobe-row';
                            }
                            ?>

                            <tr class="summary-row <?= $summary_css ?>">
                                <td class="course-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=<?= urlencode($summary_type) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export ALL <?= htmlspecialchars($summary_type) ?> rows across all departments">
                                    <?= htmlspecialchars($summary_label) ?>
                                </td>
                                <?php foreach ($departments as $d): ?>
                                    <td onclick="location.href='?page=department_gap_report&export=summary&type=<?= urlencode($summary_type) ?>&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                        title="Click to export <?= htmlspecialchars($summary_type) ?> rows for <?= htmlspecialchars($d) ?>">
                                        <?= number_format($matrix[$summary_metric][$d] ?? 0) ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="total-col"
                                    onclick="location.href='?page=department_gap_report&export=summary&type=<?= urlencode($summary_type) ?>&to_be_days=<?= $to_be_days ?>&gap_type=<?= urlencode($gap_filter) ?>&staff_cat=<?= urlencode($staff_filter) ?>&cert_tech=<?= urlencode($cert_filter) ?>'"
                                    title="Click to export all <?= htmlspecialchars($summary_type) ?> rows">
                                    <?= number_format($matrix[$summary_grand]) ?>
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-gray-500 mt-3">
                <strong>Tip:</strong> Click any <em>cell</em>, <em>row total</em>, <em>column header</em>, or the <em>grand total</em> button to export a CSV of exactly that scope.
                <br>
                Summary rows are also clickable — clicking any number in those rows exports a CSV of exactly the matching employees.
                <br>
                Hover over a <em>course code</em> to see its full name. Both dropdowns persist in the URL, so bookmarks/exports will keep the same filters.
            </p>
        </div>

    </div>

    <script>
        const pieLabels = <?= $js_pie_labels ?>;
        const pieValues = <?= $js_pie_values ?>;
        const gapFilter = <?= json_encode($gap_filter) ?>;
        const staffFilter = <?= json_encode($staff_filter) ?>;
        const certFilter = <?= json_encode($cert_filter) ?>;
        const toBeDays = <?= (int)$to_be_days ?>;

        if (pieLabels.length > 0) {
            // One colour per bar (cycles if more departments than colours)
            const palette = [
                '#dc2626', '#f59e0b', '#eab308', '#10b981', '#06b6d4',
                '#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e',
                '#14b8a6', '#84cc16', '#f97316', '#a855f7', '#0ea5e9'
            ];
            const barColors = pieLabels.map((_, i) => palette[i % palette.length]);

            // ── Inline plugin: draw the exact number above each VERTICAL bar ──
            const valueLabelsPlugin = {
                id: 'valueLabels',
                afterDatasetsDraw(chart) {
                    const {
                        ctx
                    } = chart;
                    chart.data.datasets.forEach((dataset, di) => {
                        const meta = chart.getDatasetMeta(di);
                        meta.data.forEach((bar, i) => {
                            const val = dataset.data[i];
                            if (val === null || val === undefined) return;

                            ctx.save();
                            ctx.fillStyle = '#111827';
                            ctx.font = 'bold 11px sans-serif';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';
                            // For VERTICAL bars, bar.y is the TOP of the bar.
                            // Draw the label just above it with a small gap.
                            ctx.fillText(
                                Number(val).toLocaleString(),
                                bar.x,
                                bar.y - 6
                            );
                            ctx.restore();
                        });
                    });
                }
            };

            new Chart(document.getElementById('deptBarChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: pieLabels,
                    datasets: [{
                        label: 'GAP',
                        data: pieValues,
                        backgroundColor: barColors,
                        borderColor: barColors,
                        borderWidth: 1,
                        borderRadius: 4,
                        maxBarThickness: 60
                    }]
                },
                options: {
                    // ⚠️ NO indexAxis → VERTICAL bars (default for Chart.js)
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 400
                    },
                    layout: {
                        // Extra top padding so the value label above the tallest bar isn't clipped
                        padding: {
                            top: 30
                        }
                    },
                    scales: {
                        x: {
                            // Department names on the x axis (vertical bars)
                            ticks: {
                                font: {
                                    size: 11,
                                    weight: '600'
                                },
                                maxRotation: 45,
                                minRotation: 45,
                                autoSkip: false
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                font: {
                                    size: 11
                                }
                            },
                            grid: {
                                color: '#e5e7eb'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                    return `${ctx.label}: ${Number(ctx.raw).toLocaleString()} (${pct}%)`;
                                }
                            }
                        }
                    },
                    onClick(e, items) {
                        if (!items.length) return;
                        const dept = pieLabels[items[0].index];
                        window.location.href = `?page=department_gap_report&export=dept&dept=${encodeURIComponent(dept)}&to_be_days=${toBeDays}&gap_type=${encodeURIComponent(gapFilter)}&staff_cat=${encodeURIComponent(staffFilter)}&cert_tech=${encodeURIComponent(certFilter)}`;
                    }
                },
                plugins: [valueLabelsPlugin]
            });
        }
    </script>
</body>

</html>
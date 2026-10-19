<?php
// =================================================================
// include/cc_overrides.php
// Shared CC+Position → Course override logic.
// Load this file wherever you want CC/position rules to be applied.
// =================================================================

/**
 * Load every active override row.
 * Returns:
 *   [
 *     'MROMG413' => [
 *         ['position_match' => 'INSPECTOR', 'courses' => ['EWIS'=>true, 'FTS'=>true, ...]],
 *         ...
 *     ],
 *     ...
 *   ]
 */
function load_cc_position_overrides(PDO $pdo): array
{
    $map = [];
    try {
        $rows = $pdo->query("
            SELECT cost_center, position_match, course_code
            FROM cc_position_course_overrides
            WHERE is_active = 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $cc  = trim($r['cost_center']);
            $pos = trim($r['position_match']);
            $crs = trim($r['course_code']);
            if ($cc === '' || $pos === '' || $crs === '') continue;

            // Find existing bucket for the same (cc, position_match)
            $found = false;
            foreach ($map[$cc] ?? [] as $idx => $bucket) {
                if ($bucket['position_match'] === $pos) {
                    $map[$cc][$idx]['courses'][$crs] = true;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $map[$cc][] = [
                    'position_match' => $pos,
                    'courses'        => [$crs => true],
                ];
            }
        }
    } catch (Exception $e) {
        return [];
    }
    return $map;
}

/**
 * Returns true if a position matches the given substring (case-insensitive, trimmed).
 */
function position_matches_rule(?string $position, string $needle): bool
{
    $pos = strtoupper(trim((string)$position));
    $ndl = strtoupper(trim($needle));
    if ($pos === '' || $ndl === '') return false;
    return strpos($pos, $ndl) !== false;
}

/**
 * Given an override map (from load_cc_position_overrides), a CC, a position,
 * and a course code — return true if this (cc, position, course) is
 * FORCED ON by an override rule.
 */
function is_forced_applicable_by_override(array $overrides, ?string $cc, ?string $position, string $course_code): bool
{
    if (empty($overrides)) return false;
    $cc = trim((string)$cc);
    if ($cc === '' || !isset($overrides[$cc])) return false;

    foreach ($overrides[$cc] as $bucket) {
        if (!position_matches_rule($position, $bucket['position_match'])) continue;
        if (!empty($bucket['courses'][$course_code])) return true;
    }
    return false;
}

/**
 * Optional: is this (cc, position) covered by any override that lists $course_code?
 * Same as above — kept for clarity when used from other files.
 */
function override_course_list_for(array $overrides, ?string $cc, ?string $position): array
{
    $out = [];
    $cc = trim((string)$cc);
    if ($cc === '' || !isset($overrides[$cc])) return $out;

    foreach ($overrides[$cc] as $bucket) {
        if (!position_matches_rule($position, $bucket['position_match'])) continue;
        foreach ($bucket['courses'] as $code => $_) $out[$code] = true;
    }
    return $out;
}

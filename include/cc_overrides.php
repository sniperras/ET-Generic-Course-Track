<?php
// =================================================================
// include/cc_overrides.php
// Shared CC + Dept + Position → Course override logic.
//
// Rules are stored in `cc_position_course_overrides` with:
//   cost_center     : specific CC, or '*' for any
//   department      : specific dept, or '' for any
//   position_match  : substring match on employee position, or '' for any
//   course_code     : one row per course
//   rule_type       : 'take' | 'skip' | 'forever'
//   is_active       : 1 / 0
//
// Semantics:
//   TAKE     → force the course to be applicable, regardless of CC map
//   SKIP     → force the course to NOT be applicable (treated as N/A)
//   FOREVER  → force the course to never expire for matching employees
// =================================================================

function load_cc_position_overrides(PDO $pdo): array
{
    $map = [];
    try {
        $rows = $pdo->query("
            SELECT cost_center, department, position_match, course_code, rule_type
            FROM cc_position_course_overrides
            WHERE is_active = 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $cc   = trim((string)$r['cost_center']);
            $dept = trim((string)($r['department'] ?? '')); // may be empty
            $pos  = trim((string)($r['position_match'] ?? '')); // may be empty
            $crs  = trim((string)$r['course_code']);
            $rt   = ($r['rule_type'] ?? 'take');
            if (!in_array($rt, ['take', 'skip', 'forever'], true)) $rt = 'take';

            if ($cc === '' || $crs === '') continue;

            // ── Safety guard ──────────────────────────────────────────
            // Only reject SKIP/FOREVER rules whose ENTIRE scope is empty
            // (no CC, no dept, no position). Such a rule would hit every
            // employee in every course, which is almost certainly a
            // mistake. Rules with a wildcard CC but a specific dept or
            // position (e.g. "* / MRO QA / (any)") are legitimate and
            // MUST be kept.
            //
            // NOTE: We intentionally do NOT drop rules merely because
            // $pos === '' — that would break every "(any) position"
            // FOREVER/SKIP rule.
            if ($rt !== 'take' && $cc === '*' && $dept === '' && $pos === '') {
                continue;
            }

            // Find existing bucket for (cc, dept, pos)
            $found = false;
            foreach ($map as $idx => $bucket) {
                if (
                    $bucket['cost_center'] === $cc &&
                    $bucket['department'] === $dept &&
                    $bucket['position_match'] === $pos
                ) {

                    $map[$idx][$rt][$crs] = true;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $bucket = [
                    'cost_center'    => $cc,
                    'department'     => $dept,
                    'position_match' => $pos,
                    'take'           => [],
                    'skip'           => [],
                    'forever'        => []
                ];
                $bucket[$rt][$crs] = true;
                $map[] = $bucket;
            }
        }
    } catch (Exception $e) {
        return [];
    }
    return $map;
}

function position_matches_rule(?string $position, string $needle): bool
{
    $ndl = strtoupper(trim($needle));
    if ($ndl === '') return true;

    $pos = strtoupper(trim((string)$position));
    $pos = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\s]+/u', ' ', $pos);
    return strpos($pos, $ndl) !== false;
}

function department_matches_rule(?string $emp_dept, string $rule_dept): bool
{
    if (trim($rule_dept) === '') return true; // Empty rule = any dept
    return strtoupper(trim((string)$emp_dept)) === strtoupper(trim($rule_dept));
}

function cost_center_matches_rule(?string $emp_cc, string $rule_cc): bool
{
    if ($rule_cc === '*' || trim($rule_cc) === '') return true; // Wildcard
    return strtoupper(trim((string)$emp_cc)) === strtoupper(trim($rule_cc));
}

/**
 * Master evaluator.
 * Checks whether a specific rule applies to this employee.
 */
function rule_applies_to_employee(array $bucket, ?string $emp_cc, ?string $emp_dept, ?string $emp_pos): bool
{
    return cost_center_matches_rule($emp_cc, $bucket['cost_center'])
        && department_matches_rule($emp_dept, $bucket['department'])
        && position_matches_rule($emp_pos, $bucket['position_match']);
}

/**
 * Check if a TAKE override applies.
 * $dept is optional (defaults to null) for backward compatibility.
 */
function is_forced_applicable_by_override(array $overrides, ?string $cc, ?string $position, string $course_code, ?string $dept = null): bool
{
    if (empty($overrides)) return false;
    foreach ($overrides as $bucket) {
        if (empty($bucket['take'][$course_code])) continue;
        if (rule_applies_to_employee($bucket, $cc, $dept, $position)) return true;
    }
    return false;
}

/**
 * Check if a SKIP override applies.
 * $dept is optional (defaults to null) for backward compatibility.
 */
function is_forced_skipped_by_override(array $overrides, ?string $cc, ?string $position, string $course_code, ?string $dept = null): bool
{
    if (empty($overrides)) return false;
    foreach ($overrides as $bucket) {
        if (empty($bucket['skip'][$course_code])) continue;
        if (rule_applies_to_employee($bucket, $cc, $dept, $position)) return true;
    }
    return false;
}

/**
 * Check if a FOREVER override applies.
 * $dept is optional (defaults to null) for backward compatibility.
 */
function is_forced_forever_by_override(array $overrides, ?string $cc, ?string $position, string $course_code, ?string $dept = null): bool
{
    if (empty($overrides)) return false;
    foreach ($overrides as $bucket) {
        if (empty($bucket['forever'][$course_code])) continue;
        if (rule_applies_to_employee($bucket, $cc, $dept, $position)) return true;
    }
    return false;
}

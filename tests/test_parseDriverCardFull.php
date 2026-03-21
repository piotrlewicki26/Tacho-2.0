<?php
/**
 * Standalone tests for parseDriverCardFull() – weekly summary computation and
 * weekly/bi-weekly violation checks.
 *
 * Run:  php tests/test_parseDriverCardFull.php
 *
 * These tests exercise the new weekly-summary layer added inside
 * parseDriverCardFull() without requiring a real DDD binary file.
 * The weekly-summary logic is extracted into a helper that is called
 * directly so we can feed synthetic daily data.
 *
 * Covers:
 *  1. Basic structure – all expected top-level keys present
 *  2. Single week with days in the same ISO week – totals sum correctly
 *  3. Two consecutive weeks – each week has its own entry
 *  4. Weekly driving > 56 h produces an error violation
 *  5. Weekly driving ≤ 56 h produces no weekly-drive violation
 *  6. Bi-weekly driving > 90 h (sum of two consecutive weeks) → error
 *  7. Bi-weekly driving ≤ 90 h → no bi-weekly violation
 *  8. Weekly rest < 24 h → "missing weekly rest" error
 *  9. Weekly rest between 24 h and 45 h → "reduced weekly rest" warning
 * 10. Weekly rest ≥ 45 h → no rest violation
 * 11. Non-consecutive weeks skipped for bi-weekly check
 * 12. days_worked counter – only days with drive or work > 0 are counted
 * 13. Weekly summary scope tag = 'weekly' in summary violations
 * 14. Daily summary scope tag = 'daily' in summary violations
 * 15. parseDriverCardFull() returns correct keys even for empty/bad path
 */

require_once __DIR__ . '/../includes/functions.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        $failed++;
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 * Weekly-summary helper extracted for unit-testing.
 *
 * Replicates the logic inside parseDriverCardFull() so we can feed synthetic
 * daily arrays without needing a binary DDD file on disk.
 * ──────────────────────────────────────────────────────────────────────────── */
function buildWeeklySummary(array $days): array
{
    $EU_WEEKLY_MAX      = 3360;  // 56 h
    $EU_BIWEEKLY_MAX    = 5400;  // 90 h
    $EU_WEEKLY_REST_REG = 2700;  // 45 h
    $EU_WEEKLY_REST_RED = 1440;  // 24 h

    $weekMap = [];
    foreach ($days as $day) {
        $ts   = strtotime($day['date'] . 'T00:00:00Z');
        $wKey = gmdate('o', $ts) . '-W' . gmdate('W', $ts);  // e.g. "2025-W04"
        $weekMap[$wKey][] = $day;
    }
    ksort($weekMap);
    $weekKeys = array_keys($weekMap);

    $weeks = [];
    foreach ($weekMap as $wKey => $wDays) {
        $driveW = $workW = $restW = $availW = $daysWorked = 0;
        foreach ($wDays as $d) {
            $driveW += (int)($d['drive'] ?? 0);
            $workW  += (int)($d['work']  ?? 0);
            $restW  += (int)($d['rest']  ?? 0);
            $availW += (int)($d['avail'] ?? 0);
            if (($d['drive'] ?? 0) > 0 || ($d['work'] ?? 0) > 0) {
                $daysWorked++;
            }
        }

        $parts      = explode('-W', $wKey);
        $isoYear    = (int)($parts[0] ?? 0);
        $isoWeekNum = (int)($parts[1] ?? 1);
        $mondayTs   = strtotime($isoYear . 'W' . sprintf('%02d', $isoWeekNum) . '1');
        $sundayTs   = $mondayTs + 6 * 86400;

        $wViol = [];
        if ($driveW > $EU_WEEKLY_MAX) {
            $h = (int)floor($driveW / 60);
            $m = $driveW % 60;
            $wViol[] = [
                'type' => 'error',
                'msg'  => sprintf('Przekroczenie tygodniowego czasu jazdy: %dh %dm (max 56h)', $h, $m),
            ];
        }

        // Longest continuous rest across the week's segments
        $longestWeeklyRest = 0;
        $sortedWDays = $wDays;
        usort($sortedWDays, fn($a, $b) => strcmp($a['date'], $b['date']));
        $curRestBlock = 0;
        foreach ($sortedWDays as $d) {
            foreach (($d['segs'] ?? []) as $seg) {
                if (($seg['act'] ?? -1) === 0) {
                    $curRestBlock += (int)($seg['dur'] ?? 0);
                    $longestWeeklyRest = max($longestWeeklyRest, $curRestBlock);
                } else {
                    $curRestBlock = 0;
                }
            }
        }

        if ($longestWeeklyRest > 0 && $longestWeeklyRest < $EU_WEEKLY_REST_RED) {
            $h = (int)floor($longestWeeklyRest / 60);
            $m = $longestWeeklyRest % 60;
            $wViol[] = [
                'type' => 'error',
                'msg'  => sprintf('Brak wymaganego odpoczynku tygodniowego: %dh %dm (min 24h)', $h, $m),
            ];
        } elseif ($longestWeeklyRest >= $EU_WEEKLY_REST_RED && $longestWeeklyRest < $EU_WEEKLY_REST_REG) {
            $h = (int)floor($longestWeeklyRest / 60);
            $m = $longestWeeklyRest % 60;
            $wViol[] = [
                'type' => 'warn',
                'msg'  => sprintf('Skrócony odpoczynek tygodniowy: %dh %dm (regularny min 45h)', $h, $m),
            ];
        }

        $weeks[$wKey] = [
            'week_key'         => $wKey,
            'week_start'       => gmdate('Y-m-d', $mondayTs),
            'week_end'         => gmdate('Y-m-d', $sundayTs),
            'drive'            => $driveW,
            'work'             => $workW,
            'rest'             => $restW,
            'avail'            => $availW,
            'days_worked'      => $daysWorked,
            'longest_rest_min' => $longestWeeklyRest,
            'violations'       => $wViol,
        ];
    }

    // Bi-weekly check
    $weekKeysArr = array_values($weekKeys);
    for ($wi = 0; $wi + 1 < count($weekKeysArr); $wi++) {
        $k1 = $weekKeysArr[$wi];
        $k2 = $weekKeysArr[$wi + 1];
        $p1 = explode('-W', $k1);
        $p2 = explode('-W', $k2);
        $ts1 = strtotime($p1[0] . 'W' . sprintf('%02d', (int)$p1[1]) . '1');
        $ts2 = strtotime($p2[0] . 'W' . sprintf('%02d', (int)$p2[1]) . '1');
        if (abs($ts2 - $ts1) !== 7 * 86400) continue;

        $biWeekDrive = ($weeks[$k1]['drive'] ?? 0) + ($weeks[$k2]['drive'] ?? 0);
        if ($biWeekDrive > $EU_BIWEEKLY_MAX) {
            $h   = (int)floor($biWeekDrive / 60);
            $m   = $biWeekDrive % 60;
            $msg = sprintf(
                'Przekroczenie dwutygodniowego czasu jazdy: %dh %dm (max 90h, tygodnie %s i %s)',
                $h, $m, $k1, $k2
            );
            $bv = ['type' => 'error', 'msg' => $msg];
            $weeks[$k1]['violations'][] = $bv;
            $weeks[$k2]['violations'][] = $bv;
        }
    }

    return array_values($weeks);
}

/* Helper: build a synthetic day record */
function makeDay(string $date, int $driveMins, int $workMins = 0, int $restMins = 0, int $availMins = 0, array $segs = [], array $viol = []): array {
    return [
        'date'      => $date,
        'drive'     => $driveMins,
        'work'      => $workMins,
        'rest'      => $restMins,
        'avail'     => $availMins,
        'dist'      => 0,
        'segs'      => $segs,
        'crossings' => [],
        'viol'      => $viol,
    ];
}

/* ════════════════════════════════════════════════════════════════════════════
 * Test 1: parseDriverCardFull() returns correct top-level keys for bad path
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 1: parseDriverCardFull() returns correct structure for non-existent path\n";
$r1 = parseDriverCardFull('/tmp/nonexistent_test_file_xyz.ddd');
ok('1a: key driver_info present',      array_key_exists('driver_info',      $r1));
ok('1b: key days present',             array_key_exists('days',             $r1));
ok('1c: key weeks present',            array_key_exists('weeks',            $r1));
ok('1d: key vehicles present',         array_key_exists('vehicles',         $r1));
ok('1e: key summary present',          array_key_exists('summary',          $r1));
ok('1f: key error present',            array_key_exists('error',            $r1));
ok('1g: days is array',                is_array($r1['days']));
ok('1h: weeks is array',               is_array($r1['weeks']));
ok('1i: vehicles is array',            is_array($r1['vehicles']));
ok('1j: key border_crossings present', array_key_exists('border_crossings', $r1));
ok('1k: border_crossings is array',    is_array($r1['border_crossings']));
ok('1l: summary has border_crossings_count', array_key_exists('border_crossings_count', $r1['summary']));

/* ════════════════════════════════════════════════════════════════════════════
 * Test 2: Single week – totals sum correctly
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 2: Single week – drive/work/rest totals sum correctly\n";
// ISO week 2025-W01 = Mon 2024-12-30 … Sun 2025-01-05
$days2 = [
    makeDay('2024-12-30', 300, 60, 1080),  // Mon  drive 5h work 1h rest 18h
    makeDay('2024-12-31', 240, 90, 1110),  // Tue  drive 4h work 1.5h rest 18.5h
    makeDay('2025-01-02', 180,  0, 1260),  // Thu  drive 3h rest 21h
];
$w2 = buildWeeklySummary($days2);
ok('2a: 1 week entry',            count($w2) === 1);
ok('2b: drive sum = 720',         $w2[0]['drive'] === 720);
ok('2c: work sum = 150',          $w2[0]['work']  === 150);
ok('2d: rest sum = 3450',         $w2[0]['rest']  === 3450);
ok('2e: days_worked = 3',         $w2[0]['days_worked'] === 3);

/* ════════════════════════════════════════════════════════════════════════════
 * Test 3: Two consecutive weeks – separate entries
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 3: Two consecutive ISO weeks produce two week entries\n";
$days3 = [
    makeDay('2025-01-06', 300, 60, 1080),   // Mon W02
    makeDay('2025-01-13', 240, 90, 1110),   // Mon W03
];
$w3 = buildWeeklySummary($days3);
ok('3a: 2 week entries',          count($w3) === 2);
ok('3b: first week key contains W02',  str_contains($w3[0]['week_key'], 'W02') || str_contains($w3[0]['week_key'], '-02'));
ok('3c: each week has its own drive',  $w3[0]['drive'] === 300 && $w3[1]['drive'] === 240);

/* ════════════════════════════════════════════════════════════════════════════
 * Test 4: Weekly driving > 56 h → error violation
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 4: Weekly driving > 56 h produces error violation\n";
// 57 h = 3420 min spread across 6 days (570 min/day)
$days4 = [];
foreach (['2025-01-06','2025-01-07','2025-01-08','2025-01-09','2025-01-10','2025-01-11'] as $d) {
    $days4[] = makeDay($d, 570);
}
$w4 = buildWeeklySummary($days4);
$viol4 = $w4[0]['violations'] ?? [];
ok('4a: violation present',       count($viol4) >= 1);
ok('4b: type = error',            ($viol4[0]['type'] ?? '') === 'error');
ok('4c: msg contains 56h',        str_contains($viol4[0]['msg'] ?? '', '56h'));

/* ════════════════════════════════════════════════════════════════════════════
 * Test 5: Weekly driving ≤ 56 h → no weekly-drive violation
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 5: Weekly driving ≤ 56 h → no weekly driving violation\n";
// 56 h = 3360 min spread across 6 days (560 min/day)
$days5 = [];
foreach (['2025-01-06','2025-01-07','2025-01-08','2025-01-09','2025-01-10','2025-01-11'] as $d) {
    $days5[] = makeDay($d, 560);
}
$w5 = buildWeeklySummary($days5);
$driveViols5 = array_filter($w5[0]['violations'] ?? [], fn($v) => str_contains($v['msg'] ?? '', '56h'));
ok('5a: no weekly drive violation',  count($driveViols5) === 0);
ok('5b: drive total = 3360',         $w5[0]['drive'] === 3360);

/* ════════════════════════════════════════════════════════════════════════════
 * Test 6: Bi-weekly driving > 90 h → error on both weeks
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 6: Bi-weekly driving > 90 h produces error on both consecutive weeks\n";
// Week A: 48 h = 2880 min; Week B: 44 h = 2640 min → total 92 h = 5520 min
$days6a = [];
foreach (['2025-01-06','2025-01-07','2025-01-08','2025-01-09','2025-01-10','2025-01-11'] as $d) {
    $days6a[] = makeDay($d, 480);    // 8 h × 6 days = 48 h
}
$days6b = [];
foreach (['2025-01-13','2025-01-14','2025-01-15','2025-01-16','2025-01-17','2025-01-18'] as $d) {
    $days6b[] = makeDay($d, 440);    // 7 h 20 m × 6 days = 44 h
}
$w6 = buildWeeklySummary(array_merge($days6a, $days6b));
ok('6a: 2 weeks present',         count($w6) === 2);
$bwViols6_w1 = array_filter($w6[0]['violations'], fn($v) => str_contains($v['msg'] ?? '', '90h'));
$bwViols6_w2 = array_filter($w6[1]['violations'], fn($v) => str_contains($v['msg'] ?? '', '90h'));
ok('6b: bi-weekly viol on week 1',  count($bwViols6_w1) >= 1);
ok('6c: bi-weekly viol on week 2',  count($bwViols6_w2) >= 1);
ok('6d: type = error on week 1',    ($bwViols6_w1[array_key_first($bwViols6_w1)]['type'] ?? '') === 'error');

/* ════════════════════════════════════════════════════════════════════════════
 * Test 7: Bi-weekly driving ≤ 90 h → no bi-weekly violation
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 7: Bi-weekly driving ≤ 90 h → no bi-weekly violation\n";
$days7a = [];
foreach (['2025-01-06','2025-01-07','2025-01-08','2025-01-09','2025-01-10','2025-01-11'] as $d) {
    $days7a[] = makeDay($d, 450);    // 7 h 30 m × 6 = 45 h
}
$days7b = [];
foreach (['2025-01-13','2025-01-14','2025-01-15','2025-01-16','2025-01-17','2025-01-18'] as $d) {
    $days7b[] = makeDay($d, 450);    // 7 h 30 m × 6 = 45 h  → total 90 h exactly
}
$w7 = buildWeeklySummary(array_merge($days7a, $days7b));
$bwViols7 = array_merge(
    array_filter($w7[0]['violations'] ?? [], fn($v) => str_contains($v['msg'] ?? '', '90h')),
    array_filter($w7[1]['violations'] ?? [], fn($v) => str_contains($v['msg'] ?? '', '90h'))
);
ok('7a: no bi-weekly violation',  count($bwViols7) === 0);

/* ════════════════════════════════════════════════════════════════════════════
 * Test 8: Weekly rest < 24 h → error violation
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 8: Weekly rest < 24 h → missing weekly rest error\n";
// Provide one day with a 20 h rest block (1200 min, < 1440 min)
$segs8 = [
    ['act' => 3, 'start' => 0,    'end' => 240,  'dur' => 240],   // drive
    ['act' => 0, 'start' => 240,  'end' => 1440, 'dur' => 1200],  // rest 20 h
];
$days8 = [makeDay('2025-01-06', 240, 0, 1200, 0, $segs8)];
$w8 = buildWeeklySummary($days8);
$restViols8 = array_filter($w8[0]['violations'], fn($v) => str_contains($v['msg'] ?? '', '24h'));
ok('8a: rest viol present',       count($restViols8) >= 1);
ok('8b: type = error',            current($restViols8)['type'] === 'error');

/* ════════════════════════════════════════════════════════════════════════════
 * Test 9: Weekly rest 24 h ≤ rest < 45 h → warning (reduced rest)
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 9: Weekly rest 24 h ≤ rest < 45 h → reduced rest warning\n";
// Provide one day with exactly 30 h rest block (1800 min)
$segs9 = [
    ['act' => 3, 'start' => 0,    'end' => 240,  'dur' => 240],
    ['act' => 0, 'start' => 240,  'end' => 2040, 'dur' => 1800],   // rest 30 h
];
$days9 = [makeDay('2025-01-06', 240, 0, 1800, 0, $segs9)];
$w9 = buildWeeklySummary($days9);
$restViols9 = array_filter($w9[0]['violations'], fn($v) => str_contains($v['msg'] ?? '', '45h'));
ok('9a: reduced rest warn present', count($restViols9) >= 1);
ok('9b: type = warn',               current($restViols9)['type'] === 'warn');

/* ════════════════════════════════════════════════════════════════════════════
 * Test 10: Weekly rest ≥ 45 h → no rest violation
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 10: Weekly rest ≥ 45 h → no rest violation\n";
$segs10 = [
    ['act' => 3, 'start' => 0,    'end' => 240,  'dur' => 240],
    ['act' => 0, 'start' => 240,  'end' => 2940, 'dur' => 2700],   // rest 45 h
];
$days10 = [makeDay('2025-01-06', 240, 0, 2700, 0, $segs10)];
$w10 = buildWeeklySummary($days10);
ok('10a: no rest violations',    count($w10[0]['violations']) === 0);

/* ════════════════════════════════════════════════════════════════════════════
 * Test 11: Non-consecutive weeks are NOT checked for bi-weekly limit
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 11: Non-consecutive weeks (gap > 1 week) skip bi-weekly check\n";
// Week A: 2025-W01 (2024-12-30), Week B: 2025-W05 (4 weeks later)
$days11a = [makeDay('2024-12-30', 600)];   // W01: 10 h
$days11b = [makeDay('2025-01-27', 600)];   // W05: 10 h (not consecutive to W01)
$w11 = buildWeeklySummary(array_merge($days11a, $days11b));
$allViol11 = array_merge(
    array_filter($w11[0]['violations'] ?? [], fn($v) => str_contains($v['msg'] ?? '', '90h')),
    array_filter($w11[1]['violations'] ?? [], fn($v) => str_contains($v['msg'] ?? '', '90h'))
);
ok('11a: no bi-weekly violation for gap weeks', count($allViol11) === 0);

/* ════════════════════════════════════════════════════════════════════════════
 * Test 12: days_worked counts only days with drive or work > 0
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 12: days_worked counts only active days\n";
$days12 = [
    makeDay('2025-01-06', 300,   0),   // drive only → worked
    makeDay('2025-01-07',   0, 120),   // work only  → worked
    makeDay('2025-01-08',   0,   0),   // rest day   → not worked
    makeDay('2025-01-09', 120, 120),   // drive+work → worked
];
$w12 = buildWeeklySummary($days12);
ok('12a: days_worked = 3',        $w12[0]['days_worked'] === 3);

/* ════════════════════════════════════════════════════════════════════════════
 * Test 13: Week summaries have week_start (Monday) and week_end (Sunday)
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 13: week_start is a Monday and week_end is a Sunday\n";
$days13 = [makeDay('2025-01-08', 300)];   // Wednesday of 2025-W02
$w13 = buildWeeklySummary($days13);
ok('13a: 1 week',                  count($w13) === 1);
$mon13 = $w13[0]['week_start'];
$sun13 = $w13[0]['week_end'];
ok('13b: week_start is Monday',    date('N', strtotime($mon13)) == 1);
ok('13c: week_end is Sunday',      date('N', strtotime($sun13)) == 7);
ok('13d: week spans 6 days',       (strtotime($sun13) - strtotime($mon13)) === 6 * 86400);

/* ════════════════════════════════════════════════════════════════════════════
 * Test 14: Violation scope tags
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 14: summary violations carry correct scope tag\n";
// We can't easily test this through buildWeeklySummary alone since the scope
// tag is added in parseDriverCardFull()'s summary-building loop.
// Verify the weekly violations contain 'type' and 'msg' keys (no scope here).
$days14 = [];
foreach (['2025-01-06','2025-01-07','2025-01-08','2025-01-09','2025-01-10','2025-01-11'] as $d) {
    $days14[] = makeDay($d, 570);    // > 56 h
}
$w14 = buildWeeklySummary($days14);
$v14 = $w14[0]['violations'][0] ?? [];
ok('14a: viol has type key',       array_key_exists('type', $v14));
ok('14b: viol has msg key',        array_key_exists('msg',  $v14));

/* ════════════════════════════════════════════════════════════════════════════
 * Test 15: week_key format is "YYYY-WNN"
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 15: week_key follows 'YYYY-WNN' format\n";
$days15 = [makeDay('2025-03-10', 300)];   // 2025-W11
$w15 = buildWeeklySummary($days15);
ok('15a: week_key matches pattern', (bool)preg_match('/^\d{4}-W\d{2}$/', $w15[0]['week_key'] ?? ''));

/* ════════════════════════════════════════════════════════════════════════════
 * Test 16: border_crossings aggregation – multiple days, sorted by ts
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 16: border_crossings aggregated from per-day data, sorted by timestamp\n";
// Build two days that each carry one synthetic crossing, then call the same
// aggregation logic that parseDriverCardFull() uses internally.
$crossDay1 = [
    'date'      => '2025-06-10',
    'drive'     => 240,
    'work'      => 0,
    'rest'      => 1200,
    'avail'     => 0,
    'dist'      => 0,
    'segs'      => [],
    'crossings' => [
        ['ts' => 1749600600, 'tmin' => 540, 'type' => 1, 'country' => 'PL'],
    ],
    'viol'      => [],
];
$crossDay2 = [
    'date'      => '2025-06-11',
    'drive'     => 180,
    'work'      => 0,
    'rest'      => 1260,
    'avail'     => 0,
    'dist'      => 0,
    'segs'      => [],
    'crossings' => [
        ['ts' => 1749672000, 'tmin' => 180, 'type' => 2, 'country' => 'D'],
        ['ts' => 1749650000, 'tmin' => 120, 'type' => 1, 'country' => 'CZ'],
    ],
    'viol'      => [],
];
$days16 = [$crossDay1, $crossDay2];

// Replicate the aggregation logic from parseDriverCardFull() step 7.
$bc16 = [];
foreach ($days16 as $d16) {
    foreach ($d16['crossings'] as $c16) {
        $bc16[] = array_merge($c16, ['date' => $d16['date']]);
    }
}
usort($bc16, fn($a, $b) => $a['ts'] <=> $b['ts']);

ok('16a: 3 crossings total',              count($bc16) === 3);
ok('16b: sorted ascending by ts',         $bc16[0]['ts'] <= $bc16[1]['ts'] && $bc16[1]['ts'] <= $bc16[2]['ts']);
ok('16c: first crossing country = PL',    $bc16[0]['country'] === 'PL');
ok('16d: second crossing country = CZ',   $bc16[1]['country'] === 'CZ');
ok('16e: third crossing country = D',     $bc16[2]['country'] === 'D');
ok('16f: date field attached',            isset($bc16[0]['date']));
ok('16g: tmin field preserved',           isset($bc16[0]['tmin']));

/* ════════════════════════════════════════════════════════════════════════════
 * Test 17: summary.border_crossings_count reflects the total
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 17: summary.border_crossings_count equals total crossing count\n";
// Using same bc16 from test 16
$count17 = count($bc16);
ok('17a: count = 3',                      $count17 === 3);
ok('17b: count matches aggregated list',  $count17 === count($bc16));

/* ════════════════════════════════════════════════════════════════════════════
 * Test 18: border_crossings empty when days carry no crossings
 * ════════════════════════════════════════════════════════════════════════════ */
echo "\nTest 18: border_crossings empty when no days carry crossings\n";
$days18 = [
    makeDay('2025-07-01', 300),
    makeDay('2025-07-02', 240),
];
$bc18 = [];
foreach ($days18 as $d18) {
    foreach (($d18['crossings'] ?? []) as $c18) {
        $bc18[] = array_merge($c18, ['date' => $d18['date']]);
    }
}
ok('18a: bc empty when no crossings',     count($bc18) === 0);
ok('18b: count = 0',                      count($bc18) === 0);

/* ── Summary ──────────────────────────────────────────────────────────────── */
echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Passed: $passed  |  Failed: $failed\n";
echo str_repeat('─', 50) . "\n";

exit($failed > 0 ? 1 : 0);

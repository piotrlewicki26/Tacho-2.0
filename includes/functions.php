<?php
/**
 * TachoPro 2.0 – Shared utility functions
 */

/**
 * Escape output for HTML context.
 */
function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect helper.
 */
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

/**
 * Format a date string as d.m.Y (Polish format).
 */
function fmtDate(?string $date): string {
    if (!$date) return '—';
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d ? $d->format('d.m.Y') : e($date);
}

/**
 * Return status class (Bootstrap) and label for a date vs today.
 *   - overdue  : date < today
 *   - soon     : date within $warnDays
 *   - ok       : beyond $warnDays
 */
function dateStatus(?string $date, int $warnDays = 30): array {
    if (!$date) return ['class' => 'secondary', 'label' => 'Brak', 'days' => null];
    $today    = new DateTime('today');
    $dt       = new DateTime($date);
    $diff     = (int)$today->diff($dt)->format('%r%a');  // negative = past
    if ($diff < 0) {
        return ['class' => 'danger',  'label' => 'Przeterminowany', 'days' => $diff];
    }
    if ($diff <= $warnDays) {
        return ['class' => 'warning', 'label' => 'Wkrótce wygaśnie', 'days' => $diff];
    }
    return ['class' => 'success', 'label' => 'Aktywna', 'days' => $diff];
}

/**
 * Download status: next required date check (28 days for card, 90 for vehicle).
 */
function downloadStatus(?string $nextRequired): array {
    return dateStatus($nextRequired, 7);
}

/**
 * Paginator: return offset, total pages, and current page.
 */
function paginate(int $total, int $perPage, int $page): array {
    $perPage    = max(1, $perPage);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;
    return compact('total', 'perPage', 'totalPages', 'page', 'offset');
}

/**
 * Generate a pagination HTML snippet.
 */
function paginationHtml(array $p, string $baseUrl): string {
    if ($p['totalPages'] <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    $prev = $p['page'] > 1 ? $p['page'] - 1 : 1;
    $next = $p['page'] < $p['totalPages'] ? $p['page'] + 1 : $p['totalPages'];
    $html .= '<li class="page-item' . ($p['page'] == 1 ? ' disabled' : '') . '"><a class="page-link" href="' . $baseUrl . '&page=' . $prev . '">&laquo;</a></li>';
    $start = max(1, $p['page'] - 2);
    $end   = min($p['totalPages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $active = $i == $p['page'] ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item' . ($p['page'] == $p['totalPages'] ? ' disabled' : '') . '"><a class="page-link" href="' . $baseUrl . '&page=' . $next . '">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Flash message helpers (one-time session messages).
 */
function flashSet(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flashGet(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function flashHtml(): string {
    $f = flashGet();
    if (!$f) return '';
    $type = in_array($f['type'], ['success','danger','warning','info']) ? $f['type'] : 'info';
    return '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
        . e($f['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

/**
 * Generate a cryptographically secure unique company code.
 */
function generateCompanyCode(): string {
    return hash('sha256', random_bytes(32) . microtime(true) . uniqid('', true));
}

/**
 * Sanitise a filename for storage.
 */
function safeFilename(string $name): string {
    $name = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $name);
    return substr($name, 0, 200);
}

/**
 * Format file size as human-readable string.
 */
function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}

/**
 * Return the absolute filesystem path for a stored DDD file.
 * New records carry a stored_subdir (e.g. "CompanyName/Drivers");
 * legacy records (stored_subdir IS NULL) are under uploads/ddd/{company_id}/.
 */
function dddPhysPath(array $f, int $companyId): string {
    if (!empty($f['stored_subdir'])) {
        return __DIR__ . '/../uploads/ddd/' . $f['stored_subdir'] . '/' . $f['stored_name'];
    }
    return __DIR__ . '/../uploads/ddd/' . $companyId . '/' . $f['stored_name'];
}

/**
 * Read up to $len bytes from $data at $offset, mapping every non-printable-ASCII
 * byte to "\0".
 */
function dddReadStr(string $data, int $offset, int $len): string {
    $result = '';
    $end    = min($offset + $len, strlen($data));
    for ($i = $offset; $i < $end; $i++) {
        $b       = ord($data[$i]);
        $result .= ($b >= 32 && $b < 127) ? chr($b) : "\0";
    }
    return $result;
}

/**
 * Trim a raw DDD byte string to a clean UTF-8 label.
 * Printable ASCII passes through; Latin-1 supplement (0xa0–0xff) is
 * converted to UTF-8; control bytes are silently dropped.
 */
function dddNameTrim(string $raw): string
{
    $result = '';
    foreach (str_split($raw) as $ch) {
        $b = ord($ch);
        if ($b >= 0x20 && $b <= 0x7e) {
            $result .= $ch;
        } elseif ($b >= 0xa0) {
            $result .= mb_convert_encoding($ch, 'UTF-8', 'ISO-8859-1');
        }
    }
    return trim($result);
}

/**
 * Parse driver name, card number, and birth date from a driver-card DDD blob.
 *
 * Strategy 1 – JSX tag 0x05 0x20: primary name detection.
 * Strategy 2 – EF_Identification tag 0x01 0x05: card number + fallback name + birth date.
 *
 * @return array{last_name:string, first_name:string, card_number:string, birth_date:?string, card_valid_until:?string}|null
 */
function dddParseDriverInfo(string $data): ?array
{
    $len = strlen($data);

    // ── Strategy 1: tag 0x05 0x20 ────────────────────────────────────────────
    $driverName = null;
    for ($i = 0; $i < $len - 4; $i++) {
        if (ord($data[$i]) !== 0x05 || ord($data[$i + 1]) !== 0x20) {
            continue;
        }
        $bl = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
        if ($bl < 40 || $bl > 3000 || $i + 4 + $bl > $len) {
            continue;
        }
        for ($k = 0; $k < $bl - 72; $k++) {
            $b = ord($data[$i + 4 + $k]);
            if ($b < 65 || $b > 90) {
                continue;
            }
            $sn = trim(str_replace("\0", '', dddReadStr($data, $i + 4 + $k, 36)));
            $fn = trim(str_replace("\0", '', dddReadStr($data, $i + 4 + $k + 36, 36)));
            if (strlen($sn) >= 3 && preg_match('/^[A-Za-z][A-Za-z \-]*$/', $sn)
                && strlen($fn) >= 2 && preg_match('/^[A-Za-z][A-Za-z \-]*$/', $fn)) {
                $driverName = ['last_name' => $sn, 'first_name' => $fn];
                break;
            }
        }
        if ($driverName) {
            break;
        }
    }

    // ── Strategy 2: EF_Identification tag 0x01 0x05 ──────────────────────────
    $cardNumber   = null;
    $birthDate    = null;
    $cardValidUntil = null;
    for ($i = 0; $i < $len - 144; $i++) {
        if (ord($data[$i]) !== 0x01 || ord($data[$i + 1]) !== 0x05) {
            continue;
        }
        if ($i + 6 >= $len) {
            continue;
        }
        $bl = (ord($data[$i + 4]) << 8) | ord($data[$i + 5]);
        if ($bl < 130 || $bl > 500 || $i + 6 + $bl > $len) {
            continue;
        }
        $base    = $i + 6;
        $cardRaw = substr($data, $base + 1, 16);
        $valid   = true;
        for ($k = 0; $k < 16; $k++) {
            $b = ord($cardRaw[$k]);
            if (($b < 0x30 || $b > 0x39) && ($b < 0x41 || $b > 0x5a) && ($b < 0x61 || $b > 0x7a)) {
                $valid = false;
                break;
            }
        }
        if ($valid) {
            $cardNumber = rtrim($cardRaw);
        }
        if (!$driverName) {
            $sn = dddNameTrim(substr($data, $base + 66, 35));
            $fn = dddNameTrim(substr($data, $base + 102, 35));
            if (strlen($sn) >= 2 && strlen($fn) >= 1) {
                $driverName = ['last_name' => $sn, 'first_name' => $fn];
            }
        }
        if ($bl >= 141 && $i + 6 + 141 <= $len) {
            $birthTs   = unpack('N', substr($data, $base + 137, 4))[1];
            $birthYear = (int)gmdate('Y', $birthTs);
            if ($birthYear >= 1930 && $birthYear <= 2005) {
                $birthDate = gmdate('Y-m-d', $birthTs);
            }
        }
        // cardExpiryDate at base+61 (TimeReal = 4-byte big-endian Unix timestamp)
        if ($bl >= 65 && $i + 6 + 65 <= $len) {
            $expiryTs   = unpack('N', substr($data, $base + 61, 4))[1];
            $expiryYear = (int)gmdate('Y', $expiryTs);
            if ($expiryYear >= 2000 && $expiryYear <= 2050) {
                $cardValidUntil = gmdate('Y-m-d', $expiryTs);
            }
        }
        if ($cardNumber !== null || $driverName !== null) {
            break;
        }
    }

    if (!$driverName) {
        return null;
    }

    return [
        'last_name'       => $driverName['last_name'],
        'first_name'      => $driverName['first_name'],
        'card_number'     => $cardNumber ?? '',
        'birth_date'      => $birthDate,
        'card_valid_until' => $cardValidUntil,
    ];
}

/**
 * Parse vehicle registration plate from a vehicle DDD binary blob.
 * Returns the registration string or null if not found.
 *
 * Handles common EU plate formats:
 *  - "AB 12345"  – 2-4 letter prefix, space, alphanumeric suffix (classic)
 *  - "AB12345"   – prefix and suffix with no separator
 *  - "B AB1234"  – single-letter city code + two-part rest (e.g. German plates)
 *  - "AB 123CD"  – mixed-order alphanumeric suffix
 */
function dddParseVehicleReg(string $data): ?string {
    $len  = strlen($data);
    $best = null;
    $bestLen = 0;
    for ($i = 0; $i < $len - 14; $i++) {
        /* Read up to 14 bytes (codePage(1) + regNumber(13)); strip null/FF padding.
         * A 14-byte sliding window means that at some positions the leading bytes are
         * non-content (codePage / padding), causing the visible registration to be
         * truncated.  We therefore collect ALL valid matches and return the longest
         * one (by number of non-space characters), which corresponds to the fully
         * aligned read that captures the complete registration field. */
        $raw = dddReadStr($data, $i, 14);
        $s   = strtoupper(trim(preg_replace('/\s+/', ' ',
               preg_replace('/[^A-Z0-9 ]/', '', str_replace(["\x00", "\xFF"], ' ', $raw)))));
        if ($s === '') continue;
        /* Accept plates that:
         *  – contain only letters, digits and single spaces
         *  – total visible length (no spaces) between 4 and 10 chars
         *  – start with 1–4 letters
         *  – contain at least one digit (avoids pure-word false positives)
         *
         * Pattern A: 1–4 leading letters + optional space + 3–9 alphanumerics
         *            covers "AB12345", "AB 12345", "B AB1234"
         * Pattern B: 1–4 leading letters + space + 1–6 alnum + space + 1–6 alnum
         *            covers 3-token plates like "B AB 1234"
         */
        $noSpc = str_replace(' ', '', $s);
        if (strlen($noSpc) < 4 || strlen($noSpc) > 10) continue;
        if (!preg_match('/^[A-Z]/', $s))               continue;
        if (!preg_match('/[0-9]/', $s))                continue;  /* must contain a digit */
        if (preg_match('/^[A-Z]{1,4}\s?[A-Z0-9]{3,9}$/', $s) ||
            preg_match('/^[A-Z]{1,4}\s[A-Z0-9]{1,6}\s[A-Z0-9]{1,6}$/', $s)) {
            /* Keep the longest valid match (most non-space characters) so that
             * partial reads at misaligned window positions don't win over the
             * full registration discovered at the correct aligned offset. */
            $sLen = strlen($noSpc);
            if ($sLen > $bestLen) {
                $bestLen = $sLen;
                $best    = $s;
            }
        }
    }
    return $best;
}

/**
 * Returns potential penalty amounts (PLN) and legal act reference for a violation.
 * Based on Polish transport law (Ustawa o transporcie drogowym, zał. 3 GITD).
 *
 * @return array{penalty_driver:int, penalty_company:int, article:string}
 */
function violPenalty(string $type, string $msg): array {
    if (strpos($msg, 'Przekroczenie czasu jazdy') !== false) {
        return [
            'penalty_driver'  => 500,
            'penalty_company' => 1000,
            'article'         => 'art. 6 ust. 1 rozp. WE 561/2006, zał. 3 GITD lp. 5.1',
        ];
    }
    if (strpos($msg, 'Wydłużony czas jazdy') !== false) {
        return [
            'penalty_driver'  => 150,
            'penalty_company' => 200,
            'article'         => 'art. 6 ust. 1 rozp. WE 561/2006, zał. 3 GITD lp. 5.2',
        ];
    }
    if (strpos($msg, 'odpoczynek') !== false) {
        return [
            'penalty_driver'  => 250,
            'penalty_company' => 500,
            'article'         => 'art. 8 ust. 2 rozp. WE 561/2006, zał. 3 GITD lp. 6.1',
        ];
    }
    if (strpos($msg, 'ciągłego czasu jazdy') !== false) {
        return [
            'penalty_driver'  => 100,
            'penalty_company' => 200,
            'article'         => 'art. 7 rozp. WE 561/2006, zał. 3 GITD lp. 7.1',
        ];
    }
    return [
        'penalty_driver'  => 0,
        'penalty_company' => 0,
        'article'         => 'rozp. WE 561/2006',
    ];
}

/**
 * DDD driver-card activity parser.
 *
 * Record header layout (EU Reg. 165/2014 Annex 1B/1C):
 *   TimeReal(4) + presenceCounter(2) + distanceKm(2) + activity entries(2 each)
 * Activity entry bit layout:
 *   bit15 = slot (0=driver, 1=co-driver)
 *   bits14-11 = activity (0=REST, 1=AVAIL, 2=WORK, 3=DRIVE)
 *   bits10-0  = time in minutes from midnight
 *
 * Algorithm ported from the reference parseDDD() in truck-delegate-pro.jsx
 * (commit ea1fcf7b808040c2256107ee0b6ba4cd4b3c3589):
 *  - Scans every 2 bytes; does NOT require midnight-UTC timestamps so that real
 *    driver cards with sub-second offset timestamps are not silently dropped.
 *  - presenceCounter range 500–8000 (JSX: pres<500||pres>8000).
 *  - Distance ≤ 1100 km per day (JSX: dist>1100).
 *  - Uses IQR-based outlier removal to handle multi-tachograph cards correctly.
 *
 * @return array{days:array,summary:array}|array{error:string}
 */
function parseDddFile(string $path): array {
    $data = file_get_contents($path);
    if ($data === false) return ['error' => 'Nie można odczytać pliku.'];
    $len  = strlen($data);
    if ($len < 100) return ['error' => 'Plik jest zbyt mały.'];

    $EU_MAX_DAY   = 540;   // 9 h
    $EU_MAX_DAY_X = 600;   // 10 h extended
    $EU_MAX_CONT  = 270;   // 4 h 30 m continuous drive
    $EU_MIN_REST  = 660;   // 11 h daily rest

    $empty = ['days' => [], 'summary' => ['drive'=>0,'work'=>0,'rest'=>0,'avail'=>0,'violations'=>[]]];

    // ── Step 1: Collect candidate record headers ───────────────────────────────
    // Dynamic 5-year window so the parser keeps working for future card downloads.
    // tsMax caps candidates at 90 days into the future to prevent coincidental
    // binary patterns with far-future timestamps from being treated as real records.
    $curYear = (int)gmdate('Y');
    $yrMin   = $curYear - 3;
    $yrMax   = $curYear + 1;
    $tsMax   = time() + 90 * 86400;   // at most 90 days ahead of today
    $cands   = [];
    for ($i = 0; $i < $len - 8; $i += 2) {
        $ts   = unpack('N', substr($data, $i, 4))[1];
        $yr   = (int)gmdate('Y', $ts);
        if ($yr < $yrMin || $yr > $yrMax || $ts > $tsMax) continue;

        $pres = unpack('n', substr($data, $i+4, 2))[1];
        $dist = unpack('n', substr($data, $i+6, 2))[1];
        // JSX bounds: pres 500–8000, dist ≤ 1100 km
        if ($pres < 500 || $pres > 8000 || $dist > 1100) continue;

        $cands[] = ['off' => $i, 'ts' => $ts, 'pres' => $pres, 'dist' => $dist];
    }
    if (!$cands) return $empty;

    // ── Step 2: Group by date – keep all candidates sorted around the median ──────
    // The median presenceCounter candidate is tried first; if its activity entries
    // fail the total-minutes validation (Step 6), the next-closest candidate is
    // tried as a fallback.  This prevents a coincidental binary pattern (false
    // positive) that happens to have the median presenceCounter from silently
    // dropping a real day's record (e.g. February 17th).
    $byDate = [];
    foreach ($cands as $c) {
        $byDate[gmdate('Y-m-d', $c['ts'])][] = $c;
    }
    $dateGroups  = []; // date → [candidates ordered: median first, then neighbours]
    $dedupedMain = []; // one median candidate per date (for IQR computation)
    foreach ($byDate as $date => $arr) {
        usort($arr, fn($a,$b) => $a['pres'] - $b['pres']);
        $cnt = count($arr);
        $mid = (int)($cnt / 2);
        // Build ordered list: median, then alternately one step below / above
        $ordered = [$arr[$mid]];
        for ($d = 1; isset($arr[$mid - $d]) || isset($arr[$mid + $d]); $d++) {
            if (isset($arr[$mid - $d])) $ordered[] = $arr[$mid - $d];
            if (isset($arr[$mid + $d])) $ordered[] = $arr[$mid + $d];
        }
        $dateGroups[$date] = $ordered;
        $dedupedMain[]     = $arr[$mid];
    }

    // ── Step 3: Sort medians by presenceCounter (chronological order) ──────────
    usort($dedupedMain, fn($a,$b) => $a['pres'] - $b['pres']);

    // ── Step 4: IQR outlier filtering ─────────────────────────────────────────
    // Only the lower fence is applied: records with presenceCounter below p_min
    // are genuinely stale data from previous card use periods on different
    // tachographs and should be dropped.  Records above p_max are the MOST
    // RECENT activity – a driver who switched to a vehicle with a higher-counter
    // tachograph will produce records outside the main cluster on the high side.
    // Removing those would silently discard the latest weeks of driving data.
    $presVals = array_column($dedupedMain, 'pres');
    sort($presVals);
    $n    = count($presVals);
    $pMin = PHP_INT_MIN;
    if ($n >= 4) {
        $p25  = $presVals[(int)($n * 0.25)];
        $p75  = $presVals[(int)($n * 0.75)];
        $iqr  = $p75 - $p25;
        $pMin = $p25 - 3 * $iqr;
    }
    // Filter date groups: drop any date whose median presenceCounter is below lower fence
    $filteredGroups = [];
    foreach ($dateGroups as $date => $candidates) {
        if ($candidates[0]['pres'] >= $pMin) {
            $filteredGroups[$date] = $candidates;
        }
    }
    if (!$filteredGroups) return $empty;

    // ── Step 5: Build next-record-offset lookup (using primary/median candidates) ─
    $offsets = array_map(fn($cands) => $cands[0]['off'], $filteredGroups);
    sort($offsets);
    $offMap  = array_flip($offsets);

    // ── Step 6: Parse activity entries per record ──────────────────────────────
    // For each date, try candidates in order (median first); use the first one
    // whose activity entries produce a valid 1350–1460 minute total.
    $days = [];
    foreach ($filteredGroups as $dateKey => $candidates) {
        foreach ($candidates as $cidx => $r) {
            if ($cidx === 0) {
                // Primary candidate: use the next-record boundary for a tighter scan
                $myIdx   = $offMap[$r['off']] ?? -1;
                $nextRec = ($myIdx >= 0 && $myIdx < count($offsets) - 1)
                           ? $offsets[$myIdx + 1]
                           : $r['off'] + 400;
                $bound   = min($nextRec, $r['off'] + 600, $len - 1);
            } else {
                // Fallback candidates: use a fixed 600-byte scan window
                $bound   = min($r['off'] + 600, $len - 1);
            }

            $pts = [];
            for ($j = $r['off'] + 8; $j < $bound - 1; $j += 2) {
                $raw  = unpack('n', substr($data, $j, 2))[1];
                $slot = ($raw >> 15) & 1;
                $act  = ($raw >> 11) & 7;
                $tmin = $raw & 0x7FF;
                if ($slot === 0 && $act <= 3 && $tmin >= 0 && $tmin <= 1440) {
                    $pts[] = ['act' => $act, 'tmin' => $tmin];
                }
            }

            // Strictly-monotonic time filter – only strictly-increasing tmin
            $mono = []; $lt = -1;
            foreach ($pts as $p) {
                if ($p['tmin'] > $lt) { $mono[] = $p; $lt = $p['tmin']; }
            }

            // Build duration slots
            $slots = [];
            $mCnt  = count($mono);
            for ($k = 0; $k < $mCnt; $k++) {
                $end = ($k < $mCnt - 1) ? $mono[$k + 1]['tmin'] : 1440;
                $dur = $end - $mono[$k]['tmin'];
                if ($dur > 0) {
                    $slots[] = ['act' => $mono[$k]['act'], 'start' => $mono[$k]['tmin'], 'end' => $end, 'dur' => $dur];
                }
            }

            // Validate total minutes (accept 1350–1460 to handle minor truncation)
            $total = array_sum(array_column($slots, 'dur'));
            if ($total < 1350 || $total > 1460) continue; // try next candidate

            $driveTotal = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 3), 'dur'));
            $restTotal  = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 0), 'dur'));
            $workTotal  = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 2), 'dur'));
            $availTotal = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 1), 'dur'));

            $viol = [];
            if ($driveTotal > $EU_MAX_DAY_X) {
                $msg = 'Przekroczenie czasu jazdy: '.floor($driveTotal/60).'h '.($driveTotal%60).'m (max '.floor($EU_MAX_DAY_X/60).'h)';
                $viol[] = array_merge(['type'=>'error','msg'=>$msg], violPenalty('error', $msg));
            } elseif ($driveTotal > $EU_MAX_DAY) {
                $msg = 'Wydłużony czas jazdy: '.floor($driveTotal/60).'h '.($driveTotal%60).'m';
                $viol[] = array_merge(['type'=>'warn','msg'=>$msg], violPenalty('warn', $msg));
            }
            if ($restTotal < $EU_MIN_REST && $driveTotal > 60) {
                $msg = 'Niewystarczający odpoczynek: '.floor($restTotal/60).'h '.($restTotal%60).'m (min 11h)';
                $viol[] = array_merge(['type'=>'warn','msg'=>$msg], violPenalty('warn', $msg));
            }
            $cont = 0; $maxCont = 0;
            foreach ($slots as $seg) {
                if ($seg['act'] === 3) { $cont += $seg['dur']; $maxCont = max($maxCont, $cont); }
                elseif ($seg['act'] === 0 && $seg['dur'] >= 15) { $cont = 0; }
            }
            if ($maxCont > $EU_MAX_CONT) {
                $msg = 'Przekroczenie ciągłego czasu jazdy: '.floor($maxCont/60).'h '.($maxCont%60).'m (max 4h30m)';
                $viol[] = array_merge(['type'=>'warn','msg'=>$msg], violPenalty('warn', $msg));
            }

            $days[$dateKey] = [
                'date'  => $dateKey,
                'drive' => $driveTotal,
                'work'  => $workTotal,
                'avail' => $availTotal,
                'rest'  => $restTotal,
                'dist'  => $r['dist'],
                'segs'  => $slots,
                'viol'  => $viol,
            ];
            break; // valid record found – no need to try remaining candidates
        }
    }

    ksort($days);
    $days = array_values($days);

    /* ── Border crossings (EF_CardPlacesOfDailyWorkPeriod 0x0522) ─── */
    /* Derive year window from actual activity data dates so crossings are
     * not missed when the card contains data outside the default ±3-year
     * window (e.g. old files or files downloaded near the year boundary). */
    if ($days) {
        $actYears  = array_map(fn($d) => (int)substr($d['date'], 0, 4), $days);
        /* Cap the year floor at most 2 years before the latest activity date.
         * Using min(actYears)-1 can over-extend the window when spurious activity
         * records from outlier years (e.g. 2024 in a 2026-era card) are present,
         * causing parseBorderCrossings to match stale timestamps in non-place data
         * blocks and return false-positive crossings or miss the real ones. */
        $bcYrMin   = max(1990, max(min($actYears) - 1, max($actYears) - 2));
        $bcYrMax   = max($actYears) + 1;
    } else {
        $bcYrMin = $yrMin;
        $bcYrMax = $yrMax;
    }
    $bcrossings = parseBorderCrossings($data, $bcYrMin, $bcYrMax);
    foreach ($days as &$day) {
        $day['crossings'] = $bcrossings[$day['date']] ?? [];
    }
    unset($day);

    $summary = ['drive' => 0, 'work' => 0, 'rest' => 0, 'avail' => 0, 'violations' => []];
    foreach ($days as $day) {
        $summary['drive'] += $day['drive'];
        $summary['work']  += $day['work'];
        $summary['rest']  += $day['rest'];
        $summary['avail'] += $day['avail'];
        foreach ($day['viol'] as $v) {
            $summary['violations'][] = array_merge($v, ['date' => $day['date']]);
        }
    }

    return ['days' => $days, 'summary' => $summary];
}

/**
 * Parse border-crossing records from a driver-card DDD binary blob.
 *
 * Scans for EF_CardPlacesOfDailyWorkPeriod using multiple known TLV tags:
 *   0x05 0x0E  – Gen 2 / most common Gen 1+ implementation
 *   0x05 0x0B  – Older Gen 1 (Regulation 3821/85)
 *   0x05 0x22  – Some manufacturer-specific implementations
 *
 * EU Tachograph Regulation Annex IC §3.15 record layout:
 *   PlaceRecord (12 bytes) = entryTime(4) + entryTypeDailyWorkPeriod(1)
 *                          + NationNumeric(1) + NationAlpha(3) + OdometerShort(3)
 *   CardPointerPlaceRecord (121 bytes) = noOfUsedPlaceRecords(1) + PlaceRecord×10
 *   TLV body = noOfUsedPointerPlaces(1) + CardPointerPlaceRecord × N
 *
 * Falls back to a linear scan of the TLV block if the structured parse finds
 * nothing, to handle variant record sizes (10-byte Gen 1 without NationAlpha).
 *
 * NationAlpha validation uses a whitelist of known EU plate codes so that
 * coincidental printable-ASCII byte sequences (e.g. "ROB") embedded in other
 * tachograph data blocks cannot be mistaken for a country code.  When
 * NationAlpha is present it is only accepted if NationNumeric is also within
 * the plausible EU range (0 = absent, 1–55 = defined codes); a NationNumeric
 * value above 55 together with any NationAlpha string is a strong indicator
 * of a false-positive record hit inside a non-place data block.
 *
 * Returns: array keyed by 'Y-m-d' date → [{ts, tmin, type, country}]
 */
function parseBorderCrossings(string $data, int $yearMin, int $yearMax): array
{
    $len = strlen($data);

    /* NationNumeric (1 byte) → EU plate code — fallback when NationAlpha is absent */
    static $nationCodes = [
         1 => 'A',    2 => 'AL',   3 => 'AND',  4 => 'ARM',  5 => 'AZ',
         6 => 'B',    7 => 'BG',   8 => 'BIH',  9 => 'BY',  10 => 'CH',
        11 => 'CY',  12 => 'CZ',  13 => 'D',   14 => 'DK',  15 => 'E',
        16 => 'EST', 17 => 'F',   18 => 'FIN', 19 => 'FL',  20 => 'FO',
        21 => 'GB',  22 => 'GE',  23 => 'GR',  24 => 'H',   25 => 'HR',
        26 => 'I',   27 => 'IRL', 28 => 'IS',  29 => 'KZ',  30 => 'L',
        31 => 'LT',  32 => 'LV',  33 => 'M',   34 => 'MC',  35 => 'MD',
        36 => 'MK',  37 => 'N',   38 => 'NL',  39 => 'P',   40 => 'PL',
        41 => 'RO',  42 => 'RSM', 43 => 'RUS', 44 => 'S',   45 => 'SK',
        46 => 'SLO', 47 => 'TM',  48 => 'TR',  49 => 'UA',  50 => 'V',
    ];

    /* Whitelist of valid EU/EEA vehicle registration plate codes.
     * Any NationAlpha string that is not in this set is treated as absent —
     * the parser falls back to NationNumeric.  This prevents printable-ASCII
     * byte sequences that coincidentally pass /^[A-Z]{1,3}$/ (e.g. "ROB",
     * "BF", "GM") from being interpreted as country codes. */
    static $validAlphaCodes = [
        'A'   => true, 'AL'  => true, 'AND' => true, 'ARM' => true, 'AZ'  => true,
        'B'   => true, 'BG'  => true, 'BIH' => true, 'BY'  => true, 'CH'  => true,
        'CY'  => true, 'CZ'  => true, 'D'   => true, 'DK'  => true, 'E'   => true,
        'EST' => true, 'F'   => true, 'FIN' => true, 'FL'  => true, 'FO'  => true,
        'GB'  => true, 'GE'  => true, 'GR'  => true, 'H'   => true, 'HR'  => true,
        'I'   => true, 'IRL' => true, 'IS'  => true, 'KZ'  => true, 'L'   => true,
        'LT'  => true, 'LV'  => true, 'M'   => true, 'MC'  => true, 'MD'  => true,
        'MK'  => true, 'MNE' => true, 'N'   => true, 'NL'  => true, 'P'   => true,
        'PL'  => true, 'RO'  => true, 'RSM' => true, 'RUS' => true, 'S'   => true,
        'SK'  => true, 'SLO' => true, 'TM'  => true, 'TR'  => true, 'UA'  => true,
        'V'   => true,
    ];

    /* Known TLV tag byte-pairs for EF_CardPlacesOfDailyWorkPeriod, tried in order.
     * 0x0522 is the primary standard FID per EU Reg. 165/2014 Annex 1B/1C §3.15.
     * Other values are observed in real Gen 1 / manufacturer-specific dumps. */
    $tryTags = [
        [0x05, 0x22],   /* Standard EF_CardPlacesOfDailyWorkPeriod (Reg 165/2014) */
        [0x05, 0x20],   /* Some Gen 1+ implementations                            */
        [0x05, 0x0E],   /* Some Gen 2 / Stoneridge SE5000                         */
        [0x05, 0x0B],   /* Older Gen 1 (Reg 3821/85 EF_Places)                   */
        [0x05, 0x04],   /* Very old Gen 1                                          */
        [0x05, 0x14],   /* Actia/Smartcard implementations                        */
    ];

    /* PlaceRecord sizes to try: 10 bytes (without NationAlpha — most common in
     * the wild), 12 bytes (with NationAlpha per Reg. 165/2014), 13 bytes (rare
     * extended variant).  10 is listed first because many real-world cards omit
     * NationAlpha (all-null bytes), and scanning a large block with a 12-byte
     * stride produces more spurious hits than a 10-byte stride when the actual
     * records are packed at 10-byte boundaries. */
    $tryRecSizes = [10, 12, 13];

    $crossings = [];

    /* Track the best result (most crossing records) found across all tags and
     * blocks.  Some DDD files contain spurious TLV blocks that coincidentally
     * match an early tag and return only 1–2 fake crossings before the real
     * data block (typically tagged 0x050B and several kilobytes long) is ever
     * reached.  By accumulating the richest result instead of returning on the
     * first hit, we always surface the most complete crossing set. */
    $best      = [];
    $bestScore = 0;

    foreach ($tryTags as [$tb0, $tb1]) {
        for ($i = 0; $i < $len - 6; $i++) {
            if (ord($data[$i]) !== $tb0 || ord($data[$i + 1]) !== $tb1) {
                continue;
            }

            /* 4-byte TLV header: tag(2) + length(2, big-endian) */
            $bl = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
            if ($bl < 10 || $bl > 100000 || $i + 4 + $bl > $len) {
                continue;
            }

            $base = $i + 4;

            /* ── Method 1: structured parse ────────────────────────────────
             * TLV body = noOfUsedPointerPlaces(1) + CardPointerPlaceRecord×N
             * Each CardPointerPlaceRecord = noOfUsedPlaceRecords(1) + PlaceRecord×10 */
            $m1found = false;
            foreach ($tryRecSizes as $recBytes) {
                $ptrBytes = 1 + 10 * $recBytes;   /* CardPointerPlaceRecord size */

                $noPtr = ord($data[$base]);        /* noOfUsedPointerPlaces       */
                /* Accept 0 < noPtr <= 365 (one pointer per day), also try
                 * guessing the count from TLV block length if byte looks wrong. */
                if ($noPtr === 0 || $noPtr > 365) {
                    /* Try derived count from block length */
                    $derivedPtr = (int)(($bl - 1) / $ptrBytes);
                    if ($derivedPtr < 1 || $derivedPtr > 365) {
                        continue;
                    }
                    $noPtr = $derivedPtr;
                } else {
                    /* Plausibility check: when noPtr comes from the raw first byte
                     * (not derived from bl), the expected CardPointerPlaceRecord
                     * structure size must cover at least 70 % of the TLV block.
                     * A block whose structured size is much smaller than bl is very
                     * likely NOT in CardPointerPlaceRecord format — the first byte
                     * is coincidental data.  Skipping here lets Method 2 (linear
                     * stride scan) handle it correctly instead of triggering an
                     * early false-positive return. */
                    $expectedSize = 1 + $noPtr * $ptrBytes;
                    if ($expectedSize < (int)($bl * 0.7)) {
                        continue;
                    }
                }

                $found = [];

                for ($pi = 0; $pi < $noPtr; $pi++) {
                    $pBase = $base + 1 + $pi * $ptrBytes;
                    if ($pBase + $ptrBytes > $base + $bl + 1) {
                        break;
                    }

                    $noRec = ord($data[$pBase]);   /* noOfUsedPlaceRecords (0–10) */
                    if ($noRec === 0) {
                        continue;   /* No place records for this work period */
                    }
                    if ($noRec > 10) {
                        $noRec = 10;  /* Cap to spec max; don't skip on firmware quirk */
                    }

                    for ($ri = 0; $ri < $noRec; $ri++) {
                        $rp = $pBase + 1 + $ri * $recBytes;
                        if ($rp + $recBytes > $len) {
                            break;
                        }

                        $ts   = unpack('N', substr($data, $rp, 4))[1];
                        $year = (int)gmdate('Y', $ts);
                        if ($year < $yearMin || $year > $yearMax) {
                            continue;
                        }

                        $type          = ord($data[$rp + 4]);
                        $nationNumeric = ord($data[$rp + 5]);
                        if ($recBytes >= 12) {
                            $nationAlpha = strtoupper(
                                trim(str_replace("\0", '', substr($data, $rp + 6, 3)))
                            );
                        } else {
                            /* 10-byte record: 2-char NationAlpha at offset 6 */
                            $nationAlpha = strtoupper(
                                trim(str_replace("\0", '', substr($data, $rp + 6, 2)))
                            );
                        }

                        /* Accept NationAlpha only when it is a known EU plate code
                         * AND NationNumeric is plausible (0 = absent, 1–55 = defined).
                         * A high NationNumeric together with any alpha string indicates
                         * a false-positive hit inside a non-place data block. */
                        if ($nationAlpha !== '' && isset($validAlphaCodes[$nationAlpha])
                                && ($nationNumeric === 0 || $nationNumeric <= 55)) {
                            $country = $nationAlpha;
                        } elseif (isset($nationCodes[$nationNumeric])) {
                            $country = $nationCodes[$nationNumeric];
                        } else {
                            continue;
                        }

                        if ($type <= 2) {
                            $date  = gmdate('Y-m-d', $ts);
                            $tmin  = (int)gmdate('H', $ts) * 60 + (int)gmdate('i', $ts);
                            $found[$date][] = [
                                'ts'      => $ts,
                                'tmin'    => $tmin,
                                'type'    => $type,
                                'country' => $country,
                            ];
                        }
                    }
                }

                if (!empty($found)) {
                    $score = array_sum(array_map('count', $found));
                    if ($score > $bestScore) {
                        $best      = $found;
                        $bestScore = $score;
                    }
                    $m1found = true;
                    break; /* best recBytes found — skip remaining sizes */
                }
            }

            if ($m1found) {
                continue; /* skip Method 2 for this block; move to next $i */
            }

            /* ── Method 2: linear fallback scan ────────────────────────────
             * Walk the TLV block stride-aligned looking for valid PlaceRecord
             * patterns when the structured parse found nothing. */
            foreach ($tryRecSizes as $recBytes) {
                $found = [];
                for ($rp = $base; $rp + $recBytes <= $base + $bl; $rp += $recBytes) {
                    $ts   = unpack('N', substr($data, $rp, 4))[1];
                    $year = (int)gmdate('Y', $ts);
                    if ($year < $yearMin || $year > $yearMax) {
                        continue;
                    }

                    $type = ord($data[$rp + 4]);
                    if ($type > 2) {
                        continue;
                    }

                    $nationNumeric = ord($data[$rp + 5]);
                    if ($recBytes >= 12) {
                        $nationAlpha = strtoupper(
                            trim(str_replace("\0", '', substr($data, $rp + 6, 3)))
                        );
                    } else {
                        $nationAlpha = strtoupper(
                            trim(str_replace("\0", '', substr($data, $rp + 6, 2)))
                        );
                    }

                    /* Accept NationAlpha only when it is a known EU plate code
                     * AND NationNumeric is plausible (0 = absent, 1–55 = defined).
                     * A high NationNumeric together with any alpha string indicates
                     * a false-positive hit inside a non-place data block. */
                    if ($nationAlpha !== '' && isset($validAlphaCodes[$nationAlpha])
                            && ($nationNumeric === 0 || $nationNumeric <= 55)) {
                        $country = $nationAlpha;
                    } elseif (isset($nationCodes[$nationNumeric])) {
                        $country = $nationCodes[$nationNumeric];
                    } else {
                        continue;
                    }

                    $date  = gmdate('Y-m-d', $ts);
                    $tmin  = (int)gmdate('H', $ts) * 60 + (int)gmdate('i', $ts);
                    $found[$date][] = [
                        'ts'      => $ts,
                        'tmin'    => $tmin,
                        'type'    => $type,
                        'country' => $country,
                    ];
                }

                if (!empty($found)) {
                    $score = array_sum(array_map('count', $found));
                    if ($score > $bestScore) {
                        $best      = $found;
                        $bestScore = $score;
                    }
                    break; /* best recBytes found — skip remaining sizes */
                }
            }
        }
    }

    if (!empty($best)) {
        return $best;
    }

    /* ── Method 3: whole-file unaligned scan (last resort) ─────────────────────
     * When no TLV block is found (unrecognised manufacturer serialisation),
     * walk the entire binary byte-by-byte looking for valid PlaceRecord
     * sequences: timestamp in range + type(0-2) + known nationNumeric or Alpha.
     * Require ≥2 hits to avoid spurious single-byte coincidences. */
    for ($trySize = 10; $trySize <= 13; $trySize++) {
        $hits = [];
        for ($p = 0; $p + $trySize <= $len; $p++) {
            if ($len - $p < 4) break;
            $ts   = unpack('N', substr($data, $p, 4))[1];
            $year = (int)gmdate('Y', $ts);
            if ($year < $yearMin || $year > $yearMax) continue;

            $type          = ord($data[$p + 4]);
            $nationNumeric = ord($data[$p + 5]);
            if ($type > 2) continue;

            if ($trySize >= 12) {
                $nationAlpha = strtoupper(
                    trim(str_replace("\0", '', substr($data, $p + 6, 3)))
                );
            } else {
                $nationAlpha = strtoupper(
                    trim(str_replace("\0", '', substr($data, $p + 6, 2)))
                );
            }

            /* Accept NationAlpha only when it is a known EU plate code
             * AND NationNumeric is plausible (0 = absent, 1–55 = defined). */
            if ($nationAlpha !== '' && isset($validAlphaCodes[$nationAlpha])
                    && ($nationNumeric === 0 || $nationNumeric <= 55)) {
                $country = $nationAlpha;
            } elseif (isset($nationCodes[$nationNumeric]) && $nationNumeric >= 1) {
                $country = $nationCodes[$nationNumeric];
            } else {
                continue;
            }

            $date = gmdate('Y-m-d', $ts);
            $tmin = (int)gmdate('H', $ts) * 60 + (int)gmdate('i', $ts);
            $hits[$date][] = ['ts' => $ts, 'tmin' => $tmin, 'type' => $type, 'country' => $country];
        }

        /* Allow a single crossing record – a driver may cross only one border
         * during the 28-day card window.  Deduplication still runs to collapse
         * spurious repeated hits at the same (date, tmin, country). */
        $totalHits = array_sum(array_map('count', $hits));
        if ($totalHits >= 1) {
            /* Deduplicate: keep unique (date, tmin, country) triples per day */
            $deduped = [];
            foreach ($hits as $date => $recs) {
                $seen = [];
                foreach ($recs as $rec) {
                    $key = $rec['tmin'] . '|' . $rec['country'] . '|' . $rec['type'];
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $deduped[$date][] = $rec;
                    }
                }
            }
            if (!empty($deduped)) {
                return $deduped;
            }
        }
    }

    return $crossings;
}

/**
 * Vehicle-unit DDD parser – extracts daily distance from tachograph mass-memory files.
 *
 * @return array{days:array,summary:array}|array{error:string}
 */
function parseVehicleDdd(string $path): array {
    $data = file_get_contents($path);
    if ($data === false) return ['error' => 'Nie można odczytać pliku.'];
    $len = strlen($data);
    if ($len < 200) return ['error' => 'Plik jest zbyt mały.'];

    $curYear = (int)gmdate('Y');
    $yrMin   = $curYear - 3;
    $yrMax   = $curYear + 1;
    $tsMax   = time() + 90 * 86400;

    $cands = [];
    for ($i = 0; $i < $len - 8; $i += 2) {
        $ts   = unpack('N', substr($data, $i, 4))[1];
        $yr   = (int)gmdate('Y', $ts);
        if ($yr < $yrMin || $yr > $yrMax || $ts > $tsMax) continue;

        $pres = unpack('n', substr($data, $i+4, 2))[1];
        $dist = unpack('n', substr($data, $i+6, 2))[1];
        // JSX bounds: pres 500–8000, dist ≤ 1100 km
        if ($pres < 500 || $pres > 8000 || $dist > 1100) continue;

        $cands[] = ['off' => $i, 'ts' => $ts, 'pres' => $pres, 'dist' => $dist];
    }

    if (!$cands) return ['days' => [], 'summary' => ['total_km' => 0, 'days_active' => 0, 'drivers' => []]];

    // Deduplicate by date – keep median presenceCounter per date
    $byDate = [];
    foreach ($cands as $c) {
        $byDate[gmdate('Y-m-d', $c['ts'])][] = $c;
    }
    $deduped = [];
    foreach ($byDate as $arr) {
        usort($arr, fn($a,$b) => $a['pres'] - $b['pres']);
        $deduped[] = $arr[(int)(count($arr) / 2)];
    }
    usort($deduped, fn($a,$b) => $a['pres'] - $b['pres']);

    // IQR outlier filtering – lower fence only (see parseDddFile for rationale)
    $presVals = array_column($deduped, 'pres');
    sort($presVals);
    $n = count($presVals);
    if ($n >= 4) {
        $p25 = $presVals[(int)($n * 0.25)];
        $p75 = $presVals[(int)($n * 0.75)];
        $iqr = $p75 - $p25;
        $pMin = $p25 - 3 * $iqr;
        $filtered = array_values(array_filter($deduped, fn($c) => $c['pres'] >= $pMin));
    } else {
        $filtered = $deduped;
    }

    $days    = [];
    $summary = ['total_km' => 0, 'days_active' => 0, 'drivers' => []];
    foreach ($filtered as $r) {
        $dk = gmdate('Y-m-d', $r['ts']);
        if (isset($days[$dk])) continue;
        $days[$dk] = ['date' => $dk, 'km' => $r['dist']];
        $summary['total_km']    += $r['dist'];
        $summary['days_active'] += ($r['dist'] > 0 ? 1 : 0);
    }

    ksort($days);
    return ['days' => array_values($days), 'summary' => $summary];
}

/**
 * Parse EF_CardVehiclesUsed records from a driver-card DDD binary blob.
 *
 * EU Regulation 165/2014 Annex IC – EF_CardVehiclesUsed structure:
 *   TLV tag (2 bytes) + length (2 bytes big-endian) + value:
 *     vehiclePointerNewestRecord: WORD (2 bytes)
 *     noOfVehicleUsed:          WORD (2 bytes)
 *     cardVehicleRecord[]:
 *       vehicleRegistrationNation: nationNumeric (1 byte) + nationAlpha (3 bytes)
 *       vehicleRegistrationNumber: codePage (1 byte) + regNumber (13 bytes)
 *       vehicleFirstUse:  TimeReal (4 bytes big-endian Unix timestamp)
 *       vehicleLastUse:   TimeReal (4 bytes big-endian Unix timestamp)
 *       vehicleOdometerBegin: OdometerShort (3 bytes big-endian, km)
 *       vehicleOdometerEnd:   OdometerShort (3 bytes big-endian, km)
 *       = 32 bytes per record
 *
 * Tries common TLV tags 0x0504, 0x0528 and validates all records before
 * accepting a block.  Falls back to a whole-file scan when no TLV block
 * passes validation.
 *
 * @return array[] List of vehicle usage records sorted by first_use date:
 *   ['reg','nation','first_use','last_use','odo_begin','odo_end','distance']
 */
function parseDriverCardVehicles(string $data): array
{
    $len = strlen($data);
    if ($len < 40) return [];

    /* Accept all records whose timestamps are within a plausible range.
     * EU tachograph driver cards store vehicle usage going back many years
     * (the card records the last ~84 different vehicles used, regardless of age).
     * Using a 12-month lower bound was incorrectly filtering out vehicles that
     * hadn't been used recently but were legitimately stored on the card.
     * We now accept any record whose timestamps fall within a 20-year window. */
    $tsMin   = strtotime('-20 years');
    $tsMax   = time() + 90 * 86400;

    /* NationNumeric → EU plate code (same table as parseBorderCrossings) */
    static $nationCodes = [
         1 => 'A',    2 => 'AL',   3 => 'AND',  4 => 'ARM',  5 => 'AZ',
         6 => 'B',    7 => 'BG',   8 => 'BIH',  9 => 'BY',  10 => 'CH',
        11 => 'CY',  12 => 'CZ',  13 => 'D',   14 => 'DK',  15 => 'E',
        16 => 'EST', 17 => 'F',   18 => 'FIN', 19 => 'FL',  20 => 'FO',
        21 => 'GB',  22 => 'GE',  23 => 'GR',  24 => 'H',   25 => 'HR',
        26 => 'I',   27 => 'IRL', 28 => 'IS',  29 => 'KZ',  30 => 'L',
        31 => 'LT',  32 => 'LV',  33 => 'M',   34 => 'MC',  35 => 'MD',
        36 => 'MK',  37 => 'N',   38 => 'NL',  39 => 'P',   40 => 'PL',
        41 => 'RO',  42 => 'RSM', 43 => 'RUS', 44 => 'S',   45 => 'SK',
        46 => 'SLO', 47 => 'TM',  48 => 'TR',  49 => 'UA',  50 => 'V',
    ];

    /* Helper: try to parse N vehicle records starting at $pos within $data[0..$limit-1].
     *
     * $recSize: 32 for Gen-2 cards (Reg. 165/2014, NationAlpha present),
     *           29 for Gen-1 cards (Reg. 3821/85 / 1360/2002, no NationAlpha field),
     *           31 for proprietary format (odoBegin first, no NationAlpha, 2-byte counter),
     *           24 for compact variant (no odometer, 1-byte padding before timestamps).
     *
     * Gen-2 record layout (32 bytes):
     *   +0  nationNumeric (1 byte)
     *   +1  nationAlpha   (3 bytes, IA5, 0x00/0xFF-padded)
     *   +4  codePage      (1 byte)
     *   +5  regNumber     (13 bytes, IA5, 0x00/0xFF-padded)
     *   +18 vehicleFirstUse  (TimeReal 4 bytes)
     *   +22 vehicleLastUse   (TimeReal 4 bytes)
     *   +26 vehicleOdometerBegin (3 bytes)
     *   +29 vehicleOdometerEnd   (3 bytes)
     *
     * Gen-1 record layout (29 bytes):
     *   +0  nationNumeric (1 byte)               [no NationAlpha]
     *   +1  codePage      (1 byte)
     *   +2  regNumber     (13 bytes, IA5, 0x00/0xFF-padded)
     *   +15 vehicleFirstUse  (TimeReal 4 bytes)
     *   +19 vehicleLastUse   (TimeReal 4 bytes)
     *   +23 vehicleOdometerBegin (3 bytes)
     *   +26 vehicleOdometerEnd   (3 bytes)
     *
     * Proprietary 31-byte record layout (some manufacturers, no NationAlpha, odo first):
     *   +0  vehicleOdometerBegin (3 bytes)
     *   +3  vehicleFirstUse  (TimeReal 4 bytes)
     *   +7  vehicleLastUse   (TimeReal 4 bytes)
     *   +11 nationNumeric (1 byte)
     *   +12 codePage      (1 byte)
     *   +13 regNumber     (13 bytes, IA5, 0x00/0xFF-padded)
     *   +26 circularBufferCounter (2 bytes, ignored)
     *   +28 vehicleOdometerEnd   (3 bytes)
     *
     * Compact 24-byte record layout (no odometer, found in tag 0x050b):
     *   +0  nationNumeric (1 byte)
     *   +1  codePage      (1 byte)
     *   +2  regNumber     (13 bytes, IA5, 0x00/0xFF-padded)
     *   +15 padding/unknown (1 byte)
     *   +16 vehicleFirstUse  (TimeReal 4 bytes)
     *   +20 vehicleLastUse   (TimeReal 4 bytes)
     *
     * Returns validated records array (empty = block is not vehicle data). */
    $parseBlock = function (int $pos, int $maxRecs, int $limit, int $recSize = 32, bool $allowEpochFirstUse = false) use ($data, $len, $tsMin, $tsMax, $nationCodes): array {
        $parsed = [];
        $isGen1  = ($recSize === 29);
        $isAlt31 = ($recSize === 31);
        $isGen24 = ($recSize === 24);
        /* Field offsets derived from record layout */
        if ($isGen24) {
            /* Compact 24-byte: nation(1)+cp(1)+reg(13)+pad(1)+firstUse(4)+lastUse(4), no odo */
            $nationNumOff = 0;   $regOff = 2;   $tsOff = 16;
            $odoBeginOff  = -1;  $odoEndOff = -1;
        } elseif ($isAlt31) {
            /* Proprietary 31-byte: odoBegin(3)+firstUse(4)+lastUse(4)+nation(1)+cp(1)+reg(13)+ctr(2)+odoEnd(3) */
            $nationNumOff = 11;  $regOff = 13;  $tsOff = 3;
            $odoBeginOff  = 0;   $odoEndOff = 28;
        } elseif ($isGen1) {
            /* Gen-1: nation(1)+cp(1)+reg(13)+firstUse(4)+lastUse(4)+odoBegin(3)+odoEnd(3) */
            $nationNumOff = 0;   $regOff = 2;   $tsOff = 15;
            $odoBeginOff  = 23;  $odoEndOff = 26;
        } else {
            /* Gen-2: nation(1)+nationAlpha(3)+cp(1)+reg(13)+firstUse(4)+lastUse(4)+odoBegin(3)+odoEnd(3) */
            $nationNumOff = 0;   $regOff = 5;   $tsOff = 18;
            $odoBeginOff  = 26;  $odoEndOff = 29;
        }

        for ($v = 0; $v < $maxRecs && $v < 200; $v++) {
            if ($pos + $recSize > $limit) break;

            $nationNum   = ord($data[$pos + $nationNumOff]);
            $nationAlpha = '';
            if (!$isGen1 && !$isAlt31 && !$isGen24) {
                $nationRaw   = substr($data, $pos + 1, 3);
                $nationAlpha = strtoupper(trim(str_replace(["\x00", "\xFF"], '', $nationRaw)));
            }

            $regRaw = substr($data, $pos + $regOff, 13);
            $reg    = strtoupper(trim(str_replace(["\x00", "\xFF"], ' ', $regRaw)));
            $reg    = preg_replace('/[^A-Z0-9 \-]/', '', $reg);
            $reg    = preg_replace('/\s+/', ' ', $reg);  /* collapse padding-induced extra spaces */
            $reg    = trim($reg);

            $firstUse = unpack('N', substr($data, $pos + $tsOff,     4))[1];
            $lastUse  = unpack('N', substr($data, $pos + $tsOff + 4, 4))[1];

            if ($odoBeginOff >= 0) {
                $odoB = (ord($data[$pos + $odoBeginOff])     << 16) | (ord($data[$pos + $odoBeginOff + 1]) << 8) | ord($data[$pos + $odoBeginOff + 2]);
                $odoE = (ord($data[$pos + $odoEndOff])       << 16) | (ord($data[$pos + $odoEndOff   + 1]) << 8) | ord($data[$pos + $odoEndOff   + 2]);
            } else {
                $odoB = 0;
                $odoE = 0;
            }

            $pos += $recSize;

            /* Accept the record if timestamps are within a plausible range.
             *
             * lastUse must always be a valid timestamp (rejects empty slots and
             * extremely old records).
             *
             * firstUse = 0 (epoch / not recorded by VU) is conditionally allowed:
             * some vehicle units do not write vehicleFirstUse and leave it at zero.
             * This is only accepted when $allowEpochFirstUse is true (Phase-1 TLV
             * scan) because the TLV structural context makes false-positives
             * unlikely.  Phase-2 (blind byte scan) keeps strict firstUse validation
             * to avoid false positives from null-padded regions of the file.
             *
             * A non-zero firstUse that is still older than tsMin (> 20 years) is
             * rejected as it indicates corrupted or garbage data. */
            if ($lastUse  < $tsMin)                                         continue;  // empty slot / implausibly old last-use
            if ($lastUse  > $tsMax)                                         continue;  // implausible future timestamp
            if ($firstUse === 0 && !$allowEpochFirstUse)                    continue;  // epoch not allowed in blind scan
            if ($firstUse > 0 && $firstUse < $tsMin)                        continue;  // ancient non-epoch first-use
            if ($firstUse > $tsMax)                                         continue;  // implausible future timestamp
            if ($firstUse > 0 && $lastUse < $firstUse)                      continue;  // invalid: last before first
            if (strlen($reg) < 2)                          continue;
            /* Registration must contain at least one letter (rules out pure-digit noise) */
            if (!preg_match('/[A-Z]/', $reg))              continue;
            /* Registration must contain at least one digit – all EU tachograph vehicle
             * plates include numeric characters.  This rejects purely alphabetic
             * strings that are artefacts of misaligned binary reads. */
            if (!preg_match('/[0-9]/', $reg))              continue;
            if ($odoBeginOff >= 0 && ($odoB > 9_999_999 || $odoE > 9_999_999)) continue;
            /* Reject strings whose middle space-separated token is a lone letter –
             * e.g. "AIA M 3": valid plates never have an isolated single letter
             * sandwiched between other tokens.  This pattern indicates text/noise data
             * accidentally decoded as a registration number. */
            $regTokens = preg_split('/\s+/', $reg);
            if (count($regTokens) >= 3) {
                $midOk = true;
                for ($ti = 1, $tc = count($regTokens) - 1; $ti < $tc; $ti++) {
                    if (strlen($regTokens[$ti]) === 1 && ctype_alpha($regTokens[$ti])) {
                        $midOk = false;
                        break;
                    }
                }
                if (!$midOk) continue;
            }

            /* Determine nation string */
            $nation = '';
            if ($nationAlpha !== '' && preg_match('/^[A-Z]{1,3}$/', $nationAlpha)) {
                $nation = $nationAlpha;
            } elseif ($nationNum >= 1 && $nationNum <= 50) {
                $nation = $nationCodes[$nationNum] ?? '';
            }

            /* When the VU left firstUse at zero (epoch), use lastUse as the
             * display date so the output never shows '1970-01-01'. */
            $displayFirstUse = ($firstUse > 0) ? $firstUse : $lastUse;

            $parsed[] = [
                'reg'        => $reg,
                'nation'     => $nation,
                'first_use'  => gmdate('Y-m-d', $displayFirstUse),
                'last_use'   => gmdate('Y-m-d', $lastUse),
                'odo_begin'  => $odoB,
                'odo_end'    => $odoE,
                'distance'   => ($odoE >= $odoB) ? ($odoE - $odoB) : 0,
            ];
        }
        return $parsed;
    };

    /* ── Phase 1: Structured TLV scan ───────────────────────────────────────── */
    /* Known TLV tag byte-pairs for EF_CardVehiclesUsed (Gen 1: 0x0504, Gen 1 variant: 0x0528,
     * compact 24-byte format: 0x050b) */
    $tryTags = [[0x05, 0x04], [0x05, 0x28], [0x05, 0x0b]];

    $phase1Results = [];

    foreach ($tryTags as [$t1, $t2]) {
        for ($i = 0; $i < $len - 36; $i++) {
            if (ord($data[$i]) !== $t1 || ord($data[$i + 1]) !== $t2) continue;

            $bl = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
            /* Minimum: 4-byte header + at least one compact-24 record = 28 */
            if ($bl < 28 || $bl > 65000 || $i + 4 + $bl > $len) continue;

            $base    = $i + 4;
            /* EF_CardVehiclesUsed header layout (4 bytes):
             *   +0 +1  vehiclePointerNewestRecord  (index of newest slot, 0-based)
             *   +2 +3  noOfVehicleUsed             (count of valid entries)
             * Records start at $base + 4.
             *
             * IMPORTANT: We scan ALL slots in the buffer (totalRecs), not just
             * noOfVehicleUsed slots from slot 0.  This correctly handles wrapped
             * circular buffers where newer entries are at higher slot indices than
             * the pointer value, which the old code missed entirely. */
            $vPtr    = (ord($data[$base])     << 8) | ord($data[$base + 1]);
            $noOfVeh = (ord($data[$base + 2]) << 8) | ord($data[$base + 3]);

            /* Try Gen-2 (32-byte records) first, then Gen-1 (29-byte records) */
            $foundRecs = false;
            foreach ([32, 29] as $recSize) {
                $totalRecs = (int)(($bl - 4) / $recSize);
                if ($totalRecs < 1) continue;
                /* Sanity: pointer and count should be within buffer bounds */
                if ($vPtr >= $totalRecs && $noOfVeh > $totalRecs) continue;

                /* Parse ALL slots – timestamp validation discards empty/null entries.
                 * This finds the newest entries even when the circular buffer has wrapped.
                 * Pass allowEpochFirstUse=true: within a TLV block the structural context
                 * is reliable, so VU-unset firstUse=0 records are accepted. */
                $parsed = $parseBlock($base + 4, $totalRecs, $i + 4 + $bl, $recSize, true);

                if (!empty($parsed)) {
                    foreach ($parsed as $r) {
                        $key = $r['reg'] . '|' . $r['first_use'];
                        if (!isset($phase1Results[$key]) || $r['distance'] > ($phase1Results[$key]['distance'] ?? 0)) {
                            $phase1Results[$key] = $r;
                        }
                    }
                    $foundRecs = true;
                    break; /* found valid records for this recSize; skip 29-byte for same tag */
                }
            }

            /* Fallback: some manufacturers omit or garble the standard header and store
             * records directly at the start of the TLV value.  Try all sizes.
             * Within the TLV block, pass allowEpochFirstUse=true. */
            if (!$foundRecs) {
                foreach ([32, 31, 29, 24] as $recSize) {
                    $totalNoHdr = (int)($bl / $recSize);
                    if ($totalNoHdr < 2) continue;
                    $parsed = $parseBlock($base, $totalNoHdr, $i + 4 + $bl, $recSize, true);
                    if (count($parsed) >= 2) {
                        foreach ($parsed as $r) {
                            $key = $r['reg'] . '|' . $r['first_use'];
                            if (!isset($phase1Results[$key]) || $r['distance'] > ($phase1Results[$key]['distance'] ?? 0)) {
                                $phase1Results[$key] = $r;
                            }
                        }
                        break;
                    }
                }
            }
        }
    }

    if (!empty($phase1Results)) {
        $out = array_values($phase1Results);
        usort($out, fn($a, $b) => strcmp($a['first_use'], $b['first_use']));
        return $out;
    }

    /* ── Phase 2: Whole-file fallback scan ───────────────────────────────────── */
    /* Some manufacturers don't use standard TLV tags.  Scan the entire file for
     * contiguous groups of valid vehicle records.  Try Gen-2 (32-byte) records
     * first; if nothing found, try Gen-1 (29-byte), proprietary 31-byte, and
     * compact 24-byte records.
     * Require ≥ 2 consecutive valid records to reduce false positives while still
     * finding drivers who have used only one or two vehicles.  The letter-in-
     * registration validation already eliminates the bulk of random-data noise. */
    foreach ([32, 29, 31, 24] as $recSize) {
        $result = [];
        $seen   = [];
        for ($i = 0; $i <= $len - 2 * $recSize; $i++) {
            /* Require two consecutive valid records before committing */
            $recs = $parseBlock($i, 2, $len, $recSize);
            if (count($recs) < 2) continue;

            /* Extend as far as consecutive records remain valid */
            $recs = $parseBlock($i, 200, $len, $recSize);
            if (count($recs) < 2) continue;

            foreach ($recs as $r) {
                $key = $r['reg'] . '_' . $r['first_use'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $result[]   = $r;
            }
            /* Skip past this block */
            $i += count($recs) * $recSize - 1;
        }

        if (!empty($result)) {
            usort($result, fn($a, $b) => strcmp($a['first_use'], $b['first_use']));
            return $result;
        }
    }

    return [];
}

/**
 * Backfill driver_activity_calendar for a specific driver by copying data
 * from ddd_activity_days (joined with ddd_files).  Runs on every page load
 * so newly uploaded DDD files are automatically reflected in the calendar.
 *
 * @param  \PDO $db
 * @param  int  $companyId
 * @param  int  $driverId
 * @return int  Number of rows now in the calendar for this driver
 */
function backfillDriverActivityCalendar(\PDO $db, int $companyId, int $driverId): int
{
    if (!$driverId) return 0;

    // ── Step 1: Re-parse driver DDD files that have no ddd_activity_days rows ──
    // This handles the case where migration_020 deleted truncated activity rows so
    // the fixed parser can rebuild the full activity window on the next page visit.
    try {
        $missingStmt = $db->prepare(
            "SELECT f.* FROM ddd_files f
             WHERE f.company_id=? AND f.driver_id=?
               AND f.file_type='driver' AND f.is_deleted=0
               AND NOT EXISTS (
                   SELECT 1 FROM ddd_activity_days d WHERE d.file_id = f.id
               )"
        );
        $missingStmt->execute([$companyId, $driverId]);
        $missingFiles = $missingStmt->fetchAll();

        if ($missingFiles) {
            $insDay = $db->prepare(
                'INSERT IGNORE INTO ddd_activity_days
                 (file_id, date, drive_min, work_min, avail_min, rest_min, dist_km,
                  violations, segments, border_crossings)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($missingFiles as $fRow) {
                $fp = dddPhysPath($fRow, $companyId);
                if (!is_file($fp)) continue;
                $parseResult  = parseDddFile($fp);
                $reparsedDays = $parseResult['days'] ?? [];
                if (empty($reparsedDays)) continue;
                foreach ($reparsedDays as $day) {
                    $insDay->execute([
                        $fRow['id'],
                        $day['date'],
                        $day['drive']  ?? 0,
                        $day['work']   ?? 0,
                        $day['avail']  ?? 0,
                        $day['rest']   ?? 0,
                        $day['dist']   ?? 0,
                        json_encode($day['viol']      ?? []),
                        json_encode($day['segs']      ?? []),
                        !empty($day['crossings']) ? json_encode($day['crossings']) : json_encode(0),
                    ]);
                }
                // Update period_start / period_end in ddd_files
                $freshDates = array_column($reparsedDays, 'date');
                sort($freshDates);
                $db->prepare('UPDATE ddd_files SET period_start=?, period_end=? WHERE id=?')
                   ->execute([$freshDates[0], end($freshDates), $fRow['id']]);
            }
        }
    } catch (\Throwable $e) {
        error_log('backfillDriverActivityCalendar: re-parse error for driver ' . $driverId . ': ' . $e->getMessage());
    }

    // Count available source rows
    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM ddd_activity_days d
         JOIN ddd_files f ON f.id = d.file_id
         WHERE f.company_id=? AND f.driver_id=?
           AND f.file_type='driver' AND f.is_deleted=0"
    );
    $countStmt->execute([$companyId, $driverId]);
    if ((int)$countStmt->fetchColumn() === 0) return 0;

    // Run the backfill (identical to the INSERT in migrate_018, scoped to one driver)
    try {
        $db->prepare(
            "INSERT INTO driver_activity_calendar
               (company_id, driver_id, date, drive_min, work_min, avail_min, rest_min,
                dist_km, violations, segments, border_crossings, source_file_id)
             SELECT f.company_id, f.driver_id, d.date,
                    d.drive_min, d.work_min, d.avail_min, d.rest_min,
                    d.dist_km, d.violations, d.segments, d.border_crossings, d.file_id
             FROM ddd_activity_days d
             JOIN ddd_files f ON f.id = d.file_id
             WHERE f.company_id=? AND f.driver_id=?
               AND f.file_type='driver' AND f.is_deleted=0
             ORDER BY (d.drive_min + d.work_min + d.avail_min + d.rest_min) DESC
             ON DUPLICATE KEY UPDATE
               drive_min        = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                     > drive_min+work_min+avail_min+rest_min,
                                     VALUES(drive_min), drive_min),
               work_min         = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                     > drive_min+work_min+avail_min+rest_min,
                                     VALUES(work_min), work_min),
               avail_min        = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                     > drive_min+work_min+avail_min+rest_min,
                                     VALUES(avail_min), avail_min),
               rest_min         = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                     > drive_min+work_min+avail_min+rest_min,
                                     VALUES(rest_min), rest_min),
               dist_km          = GREATEST(dist_km, VALUES(dist_km)),
               violations       = IF(VALUES(violations) IS NOT NULL AND VALUES(violations) != '[]',
                                     VALUES(violations), violations),
               segments         = IF(VALUES(segments)   IS NOT NULL AND VALUES(segments)   != '[]',
                                     VALUES(segments),   segments),
               border_crossings = IF(VALUES(border_crossings) IS NOT NULL
                                     AND VALUES(border_crossings) NOT IN ('0','[]','null','false'),
                                     VALUES(border_crossings), border_crossings),
               source_file_id   = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                     > drive_min+work_min+avail_min+rest_min,
                                     VALUES(source_file_id), source_file_id)"
        )->execute([$companyId, $driverId]);
    } catch (\Throwable $e) {
        error_log('backfillDriverActivityCalendar: INSERT error for driver ' . $driverId . ': ' . $e->getMessage());
    }

    // Return the number of rows now in the calendar for this driver
    $check = $db->prepare(
        'SELECT COUNT(*) FROM driver_activity_calendar WHERE driver_id=?'
    );
    $check->execute([$driverId]);
    return (int)$check->fetchColumn();
}

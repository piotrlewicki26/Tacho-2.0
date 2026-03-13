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
    return ['class' => 'success', 'label' => 'Aktualny', 'days' => $diff];
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
    $curYear = (int)gmdate('Y');
    $yrMin   = $curYear - 3;
    $yrMax   = $curYear + 1;
    $cands   = [];
    for ($i = 0; $i < $len - 8; $i += 2) {
        $ts   = unpack('N', substr($data, $i, 4))[1];
        $yr   = (int)gmdate('Y', $ts);
        if ($yr < $yrMin || $yr > $yrMax) continue;

        $pres = unpack('n', substr($data, $i+4, 2))[1];
        $dist = unpack('n', substr($data, $i+6, 2))[1];
        // JSX bounds: pres 500–8000, dist ≤ 1100 km
        if ($pres < 500 || $pres > 8000 || $dist > 1100) continue;

        $cands[] = ['off' => $i, 'ts' => $ts, 'pres' => $pres, 'dist' => $dist];
    }
    if (!$cands) return $empty;

    // ── Step 2: Deduplicate by date – keep median presenceCounter per date ─────
    $byDate = [];
    foreach ($cands as $c) {
        $byDate[gmdate('Y-m-d', $c['ts'])][] = $c;
    }
    $deduped = [];
    foreach ($byDate as $arr) {
        usort($arr, fn($a,$b) => $a['pres'] - $b['pres']);
        $deduped[] = $arr[(int)(count($arr) / 2)];
    }

    // ── Step 3: Sort by presenceCounter (chronological order) ─────────────────
    usort($deduped, fn($a,$b) => $a['pres'] - $b['pres']);

    // ── Step 4: IQR outlier filtering ─────────────────────────────────────────
    $presVals = array_column($deduped, 'pres');
    sort($presVals);
    $n = count($presVals);
    if ($n >= 4) {
        $p25 = $presVals[(int)($n * 0.25)];
        $p75 = $presVals[(int)($n * 0.75)];
        $iqr = $p75 - $p25;
        $pMin = $p25 - 3 * $iqr;
        $pMax = $p75 + 3 * $iqr;
        $filtered = array_values(array_filter($deduped, fn($c) => $c['pres'] >= $pMin && $c['pres'] <= $pMax));
    } else {
        $filtered = $deduped;
    }
    if (!$filtered) return $empty;

    // ── Step 5: Build next-record-offset lookup ────────────────────────────────
    $offsets = array_column($filtered, 'off');
    sort($offsets);
    $offMap  = array_flip($offsets);

    // ── Step 6: Parse activity entries per record ──────────────────────────────
    $days = [];
    foreach ($filtered as $r) {
        $myIdx   = $offMap[$r['off']] ?? -1;
        $nextRec = ($myIdx >= 0 && $myIdx < count($offsets) - 1)
                   ? $offsets[$myIdx + 1]
                   : $r['off'] + 400;
        $bound   = min($nextRec, $r['off'] + 600, $len - 1);

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
        if ($total < 1350 || $total > 1460) continue;

        $dateKey    = gmdate('Y-m-d', $r['ts']);
        $driveTotal = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 3), 'dur'));
        $restTotal  = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 0), 'dur'));
        $workTotal  = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 2), 'dur'));
        $availTotal = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 1), 'dur'));

        $viol = [];
        if ($driveTotal > $EU_MAX_DAY_X) {
            $viol[] = ['type'=>'error','msg'=>'Przekroczenie czasu jazdy: '.floor($driveTotal/60).'h '.($driveTotal%60).'m (max '.floor($EU_MAX_DAY_X/60).'h)'];
        } elseif ($driveTotal > $EU_MAX_DAY) {
            $viol[] = ['type'=>'warn','msg'=>'Wydłużony czas jazdy: '.floor($driveTotal/60).'h '.($driveTotal%60).'m'];
        }
        if ($restTotal < $EU_MIN_REST && $driveTotal > 60) {
            $viol[] = ['type'=>'warn','msg'=>'Niewystarczający odpoczynek: '.floor($restTotal/60).'h '.($restTotal%60).'m (min 11h)'];
        }
        $cont = 0; $maxCont = 0;
        foreach ($slots as $seg) {
            if ($seg['act'] === 3) { $cont += $seg['dur']; $maxCont = max($maxCont, $cont); }
            elseif ($seg['act'] === 0 && $seg['dur'] >= 15) { $cont = 0; }
        }
        if ($maxCont > $EU_MAX_CONT) {
            $viol[] = ['type'=>'warn','msg'=>'Przekroczenie ciągłego czasu jazdy: '.floor($maxCont/60).'h '.($maxCont%60).'m (max 4h30m)'];
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
    }

    ksort($days);
    $days = array_values($days);

    /* ── Border crossings (EF_CardPlacesOfDailyWorkPeriod 0x0522) ─── */
    $bcrossings = parseBorderCrossings($data, $yrMin, $yrMax);
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
 * Scans for EF_CardPlacesOfDailyWorkPeriod (TLV tag 0x05 0x22).
 *
 * EU Tachograph Regulation Annex IC §3.15 record layout:
 *   PlaceRecord (12 bytes) = entryTime(4) + entryTypeDailyWorkPeriod(1)
 *                          + NationNumeric(1) + NationAlpha(3) + OdometerShort(3)
 *   CardPlaceDailyWorkPeriod (121 bytes) = noOfUsedPlaceRecords(1) + PlaceRecord×10
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

    /* EU spec fixed sizes */
    $recBytes = 12;   // bytes per PlaceRecord
    $ptrBytes = 121;  // bytes per CardPlaceDailyWorkPeriod (1 + 10*12)

    $crossings = [];

    for ($i = 0; $i < $len - 6; $i++) {
        /* EF_CardPlacesOfDailyWorkPeriod TLV tag: 0x05 0x22 */
        if (ord($data[$i]) !== 0x05 || ord($data[$i + 1]) !== 0x22) {
            continue;
        }

        /* 4-byte TLV header: tag(2) + length(2, big-endian) */
        $bl = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
        if ($bl < $ptrBytes || $bl > 50000 || $i + 4 + $bl > $len) {
            continue;
        }

        $base  = $i + 4;
        $noPtr = ord($data[$base]);       // noOfUsedPointerPlaces
        if ($noPtr === 0 || $noPtr > 100) {
            continue;
        }

        $found = [];

        for ($pi = 0; $pi < $noPtr; $pi++) {
            /* Each CardPlaceDailyWorkPeriod has a fixed stride of 121 bytes */
            $pBase = $base + 1 + $pi * $ptrBytes;
            if ($pBase + $ptrBytes > $base + $bl + 1) {
                break;
            }

            $noRec = ord($data[$pBase]);  // noOfUsedPlaceRecords (0–10)
            if ($noRec === 0 || $noRec > 10) {
                continue;
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

                $type          = ord($data[$rp + 4]);           // EntryTypeDailyWorkPeriod
                $nationNumeric = ord($data[$rp + 5]);            // NationNumeric
                $nationAlpha   = strtoupper(                      // NationAlpha (IA5String×3)
                    trim(str_replace("\0", '', substr($data, $rp + 6, 3)))
                );

                /* Prefer NationAlpha (directly readable), fall back to numeric map */
                if (preg_match('/^[A-Z]{1,3}$/', $nationAlpha)) {
                    $country = $nationAlpha;
                } elseif (isset($nationCodes[$nationNumeric])) {
                    $country = $nationCodes[$nationNumeric];
                } else {
                    continue;
                }

                if ($type <= 2) {   // 0=begin, 1=end, 2=beginAndEnd
                    $date          = gmdate('Y-m-d', $ts);
                    $tmin          = (int)gmdate('H', $ts) * 60 + (int)gmdate('i', $ts);
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
            $crossings = $found;
            break; /* use first valid block found */
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

    $cands = [];
    for ($i = 0; $i < $len - 8; $i += 2) {
        $ts   = unpack('N', substr($data, $i, 4))[1];
        $yr   = (int)gmdate('Y', $ts);
        if ($yr < $yrMin || $yr > $yrMax) continue;

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

    // IQR outlier filtering
    $presVals = array_column($deduped, 'pres');
    sort($presVals);
    $n = count($presVals);
    if ($n >= 4) {
        $p25 = $presVals[(int)($n * 0.25)];
        $p75 = $presVals[(int)($n * 0.75)];
        $iqr = $p75 - $p25;
        $pMin = $p25 - 3 * $iqr;
        $pMax = $p75 + 3 * $iqr;
        $filtered = array_values(array_filter($deduped, fn($c) => $c['pres'] >= $pMin && $c['pres'] <= $pMax));
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

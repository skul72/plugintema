<?php

if (!defined('ABSPATH')) {
    exit;
}

function ptsb_parse_time_hm($time): array {
    if (preg_match('/^(\d{1,2}):(\d{2})$/', (string) $time, $m)) {
        $h = min(23, max(0, (int) $m[1]));
        $m = min(59, max(0, (int) $m[2]));
        return [$h, $m];
    }
    return [0, 0];
}

function ptsb_times_sort_unique($times): array {
    $out = [];
    foreach ((array) $times as $time) {
        $time = trim((string) $time);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            $out[] = sprintf('%02d:%02d', min(23, max(0, (int) $m[1])), min(59, max(0, (int) $m[2])));
        }
    }

    $out = array_values(array_unique($out));
    sort($out, SORT_STRING);

    return $out;
}

function ptsb_time_to_min($time) {
    [$h, $m] = ptsb_parse_time_hm($time);
    return ($h * 60) + $m;
}

function ptsb_min_to_time($minutes) {
    $minutes = max(0, min(1439, (int) round($minutes)));
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function ptsb_evenly_distribute($qty, $start = '00:00', $end = '23:59'): array {
    $qty = max(1, (int) $qty);
    $a   = ptsb_time_to_min($start);
    $b   = ptsb_time_to_min($end);
    if ($b < $a) {
        $b = $a;
    }

    $span = max(1, $b - $a);
    if ($qty === 1) {
        return [ptsb_min_to_time(($a + $b) / 2)];
    }

    $step = $span / ($qty - 1);
    $times = [];
    for ($i = 0; $i < $qty; $i++) {
        $times[] = ptsb_min_to_time($a + ($step * $i));
    }

    return ptsb_times_sort_unique($times);
}

function ptsb_today_slots_by_mode($mode, $mcfg, DateTimeImmutable $refDay): array {
    $mode = strtolower((string) $mode);
    $cfg  = is_array($mcfg) ? $mcfg : [];

    switch ($mode) {
        case 'weekly':
            $dayIdx = (int) $refDay->format('w');
            $days   = array_map('intval', $cfg['days'] ?? []);
            if (!in_array($dayIdx, $days, true)) {
                return [];
            }
            $times = $cfg['times'] ?? [];
            if (!$times && !empty($cfg['time'])) {
                $times = [$cfg['time']];
            }
            return ptsb_times_sort_unique($times);

        case 'every_n':
            $n      = max(1, min(30, (int) ($cfg['n'] ?? 1)));
            $startS = $cfg['start'] ?? $refDay->format('Y-m-d');
            try {
                $start = new DateTimeImmutable($startS . ' 00:00:00', ptsb_tz());
            } catch (Throwable $e) {
                $start = $refDay->setTime(0, 0);
            }
            $diffDays = (int) $start->diff($refDay->setTime(0, 0))->days;
            if ($diffDays % $n !== 0) {
                return [];
            }
            $times = $cfg['times'] ?? [];
            if (!$times && !empty($cfg['time'])) {
                $times = [$cfg['time']];
            }
            return ptsb_times_sort_unique($times);

        case 'interval':
            $every = $cfg['every'] ?? ['value' => 60, 'unit' => 'minute'];
            $value = max(1, (int) ($every['value'] ?? 60));
            $unit  = strtolower((string) ($every['unit'] ?? 'minute'));

            if ($unit === 'day') {
                $stepMin = $value * 1440;
            } elseif ($unit === 'hour') {
                $stepMin = $value * 60;
            } else {
                $stepMin = $value;
            }

            $winDisabled = !empty($cfg['win']['disabled']);
            $ws = $winDisabled ? '00:00' : (string) ($cfg['win']['start'] ?? '00:00');
            $we = $winDisabled ? '23:59' : (string) ($cfg['win']['end'] ?? '23:59');

            $a = ptsb_time_to_min($ws);
            $b = ptsb_time_to_min($we);
            if ($b < $a) {
                $b = $a;
            }

            $out = [];
            $m   = $a;
            while ($m <= $b) {
                $out[] = ptsb_min_to_time($m);
                $m += $stepMin;
            }

            return ptsb_times_sort_unique($out);

        case 'daily':
        default:
            $times = $cfg['times'] ?? [];
            return ptsb_times_sort_unique($times);
    }
}

function ptsb_next_occurrences_adv($auto, $n = 5): array {
    $auto = is_array($auto) ? $auto : [];
    if (empty($auto['enabled'])) {
        return [];
    }

    $mode = $auto['mode'] ?? 'daily';
    $mcfg = $auto['cfg'] ?? [];
    $now  = ptsb_now_brt();

    $out = [];
    for ($d = 0; $d < 60 && count($out) < $n; $d++) {
        $ref = $now->setTime(0, 0)->modify("+$d day");
        $slots = ptsb_today_slots_by_mode($mode, $mcfg, $ref);
        foreach ($slots as $time) {
            [$H, $M] = ptsb_parse_time_hm($time);
            $dt = $ref->setTime($H, $M);
            if ($dt <= $now) {
                continue;
            }
            $out[] = $dt;
            if (count($out) >= $n) {
                break 2;
            }
        }
    }

    return $out;
}

function ptsb_skipmap_get(): array {
    $map = get_option('ptsb_skip_slots', []);
    return is_array($map) ? $map : [];
}

function ptsb_skipmap_save(array $map): void {
    update_option('ptsb_skip_slots', $map, true);
}

function ptsb_skip_key(DateTimeImmutable $dt): string {
    return $dt->format('Y-m-d H:i');
}

function ptsb_skipmap_gc(): void {
    $map = ptsb_skipmap_get();
    if (!$map) {
        return;
    }

    $now = time();
    foreach ($map as $key => $flag) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $key, $m)) {
            unset($map[$key]);
            continue;
        }
        try {
            $dt = new DateTimeImmutable($key . ':00', ptsb_tz());
        } catch (Throwable $e) {
            unset($map[$key]);
            continue;
        }
        if (($now - $dt->getTimestamp()) > 86400) {
            unset($map[$key]);
        }
    }

    ptsb_skipmap_save($map);
}

function ptsb_uuid4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function ptsb_cycles_get(): array {
    $cycles = get_option('ptsb_cycles', []);
    return is_array($cycles) ? $cycles : [];
}

function ptsb_cycles_save(array $cycles): void {
    update_option('ptsb_cycles', array_values($cycles), true);
}

function ptsb_cycles_state_get(): array {
    $state = get_option('ptsb_cycles_state', []);
    if (!is_array($state)) {
        $state = [];
    }
    $state += ['by_cycle' => [], 'queued' => ['time' => '']];
    if (!is_array($state['by_cycle'])) {
        $state['by_cycle'] = [];
    }
    if (!is_array($state['queued'])) {
        $state['queued'] = ['time' => ''];
    }

    return $state;
}

function ptsb_cycles_state_save(array $state): void {
    update_option('ptsb_cycles_state', $state, true);
}

function ptsb_cycles_global_get(): array {
    $defaults = ['merge_dupes' => true, 'policy' => 'queue'];
    $cfg = get_option('ptsb_cycles_global', []);
    if (!is_array($cfg)) {
        $cfg = [];
    }

    return $cfg + $defaults;
}

function ptsb_cycles_global_save(array $global): void {
    update_option('ptsb_cycles_global', $global, true);
}

function ptsb_cycle_today_slots(array $cycle, DateTimeImmutable $refDay): array {
    if (empty($cycle['enabled'])) {
        return [];
    }

    $mode = $cycle['mode'] ?? 'daily';
    $cfg  = $cycle['cfg'] ?? [];

    return ptsb_today_slots_by_mode($mode, $cfg, $refDay);
}

function ptsb_cycles_occurrences_for_date(array $cycles, DateTimeImmutable $day): array {
    $now  = ptsb_now_brt();
    $list = [];
    $map  = [];

    foreach ($cycles as $cycle) {
        if (empty($cycle['enabled'])) {
            continue;
        }
        $slots = ptsb_cycle_today_slots($cycle, $day);
        foreach ($slots as $time) {
            if ($day->format('Y-m-d') === $now->format('Y-m-d')) {
                [$H, $M] = ptsb_parse_time_hm($time);
                if ($day->setTime($H, $M) <= $now) {
                    continue;
                }
            }
            if (!isset($map[$time])) {
                $map[$time] = ['letters' => [], 'names' => []];
            }
            $map[$time]['names'][] = (string) ($cycle['name'] ?? 'Rotina');
            foreach ((array) ($cycle['letters'] ?? []) as $letter) {
                $map[$time]['letters'][strtoupper($letter)] = true;
            }
        }
    }

    $times = array_keys($map);
    sort($times, SORT_STRING);
    foreach ($times as $time) {
        [$H, $M] = ptsb_parse_time_hm($time);
        $dt      = $day->setTime($H, $M);
        $list[]  = [
            'dt'      => $dt,
            'letters' => array_keys($map[$time]['letters']),
            'names'   => $map[$time]['names'],
        ];
    }

    return $list;
}

function ptsb_cycles_next_occurrences(array $cycles, $n = 6): array {
    $now = ptsb_now_brt();
    $list = [];
    for ($d = 0; $d < 60 && count($list) < $n; $d++) {
        $base = $now->setTime(0, 0)->modify("+$d day");
        $map  = [];
        foreach ($cycles as $cycle) {
            if (empty($cycle['enabled'])) {
                continue;
            }
            $slots = ptsb_cycle_today_slots($cycle, $base);
            foreach ($slots as $time) {
                if (
                    $d === 0
                    && $base->format('Y-m-d') === $now->format('Y-m-d')
                    && $base->setTime(...ptsb_parse_time_hm($time)) <= $now
                ) {
                    continue;
                }
                if (!isset($map[$time])) {
                    $map[$time] = ['letters' => [], 'names' => []];
                }
                $map[$time]['names'][] = (string) ($cycle['name'] ?? 'Rotina');
                foreach ((array) ($cycle['letters'] ?? []) as $letter) {
                    $map[$time]['letters'][strtoupper($letter)] = true;
                }
            }
        }
        $times = array_keys($map);
        sort($times, SORT_STRING);
        foreach ($times as $time) {
            $dt      = $base->setTime(...ptsb_parse_time_hm($time));
            $letters = array_keys($map[$time]['letters']);
            $names   = $map[$time]['names'];
            $list[]  = ['dt' => $dt, 'letters' => $letters, 'names' => $names];
            if (count($list) >= $n) {
                break 2;
            }
        }
    }

    return $list;
}

function ptsb_guess_cycle_mode_from_filename(string $file): ?string {
    $cycles = ptsb_cycles_get();
    if (!$cycles) {
        return null;
    }

    $cfg      = ptsb_cfg();
    $bestLen  = 0;
    $bestMode = null;
    foreach ($cycles as $cycle) {
        $slug = ptsb_slug_prefix((string) ($cycle['name'] ?? ''));
        if ($slug === '') {
            continue;
        }
        foreach ([$cfg['prefix'] . $slug, $slug] as $candidate) {
            if ($candidate !== '' && strpos($file, $candidate) === 0) {
                $length = strlen($candidate);
                if ($length > $bestLen) {
                    $bestLen  = $length;
                    $bestMode = (string) ($cycle['mode'] ?? 'daily');
                }
            }
        }
    }

    return $bestMode;
}

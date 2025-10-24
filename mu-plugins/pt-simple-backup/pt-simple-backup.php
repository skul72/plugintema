<?php

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------
 * Utilidades gerais
 * -----------------------------------------------------*/
function ptsb_can_shell(): bool {
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
}

function ptsb_is_readable($path): bool {
    return @is_file($path) && @is_readable($path);
}

function ptsb_tz(): DateTimeZone {
    $cfg = ptsb_cfg();
    try {
        return new DateTimeZone($cfg['tz_string']);
    } catch (Throwable $e) {
        return new DateTimeZone('America/Sao_Paulo');
    }
}

function ptsb_now_brt(): DateTimeImmutable {
    return new DateTimeImmutable('now', ptsb_tz());
}

function ptsb_fmt_local_dt($iso): string {
    try {
        $tz  = ptsb_tz();
        $dt  = new DateTimeImmutable($iso);
        $dt2 = $dt->setTimezone($tz);
        return $dt2->format('d/m/Y - H:i:s');
    } catch (Throwable $e) {
        return (string) $iso;
    }
}

function ptsb_hsize($bytes): string {
    $b = (float) $bytes;
    if ($b >= 1073741824) {
        return number_format_i18n($b / 1073741824, 2) . ' GB';
    }

    return number_format_i18n(max($b / 1048576, 0.01), 2) . ' MB';
}

function ptsb_backups_totals_cached(): array {
    $key     = 'ptsb_totals_v1';
    $cached  = get_transient($key);
    if (is_array($cached) && isset($cached['count'], $cached['bytes'])) {
        return $cached;
    }

    $rows  = ptsb_list_remote_files();
    $count = count($rows);
    $bytes = 0;
    foreach ($rows as $row) {
        $bytes += (int) ($row['size'] ?? 0);
    }

    $out = ['count' => $count, 'bytes' => $bytes];
    set_transient($key, $out, 10 * MINUTE_IN_SECONDS);

    return $out;
}

function ptsb_tar_to_json(string $tar): string {
    return preg_replace('/\\.tar\\.gz$/i', '.json', $tar);
}

function ptsb_slug_prefix(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    if (function_exists('sanitize_title')) {
        $slug = sanitize_title($name);
    } else {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)));
        $slug = trim($slug, '-');
    }

    return $slug ? ($slug . '-') : '';
}

function ptsb_to_utf8($string): string {
    if ($string === null) {
        return '';
    }

    if (function_exists('seems_utf8') && seems_utf8($string)) {
        return $string;
    }

    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $enc = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($enc && $enc !== 'UTF-8') {
            return mb_convert_encoding($string, 'UTF-8', $enc);
        }
    }

    $out = @iconv('UTF-8', 'UTF-8//IGNORE', $string);

    return $out !== false ? $out : $string;
}

function ptsb_log($message): void {
    $cfg = ptsb_cfg();
    ptsb_log_rotate_if_needed();
    $line = '[' . ptsb_now_brt()->format('d-m-Y-H:i') . '] ' . strip_tags($message) . "\n";
    @file_put_contents($cfg['log'], $line, FILE_APPEND);
}

function ptsb_log_rotate_if_needed(): void {
    $cfg   = ptsb_cfg();
    $log   = (string) ($cfg['log'] ?? '');
    $keep  = max(1, (int) ($cfg['log_keep'] ?? 5));
    $maxMb = (float) ($cfg['log_max_mb'] ?? 3);
    $limit = max(1, (int) round($maxMb * 1048576));

    if ($log === '' || !@file_exists($log)) {
        return;
    }

    @clearstatcache(true, $log);
    $size = @filesize($log);
    if ($size === false || $size < $limit) {
        return;
    }

    for ($i = $keep; $i >= 1; $i--) {
        $from = $log . '.' . $i;
        $to   = $log . '.' . ($i + 1);
        if (@file_exists($to)) {
            @unlink($to);
        }
        if (@file_exists($from)) {
            @rename($from, $to);
        }
    }

    $running = @file_exists((string) $cfg['lock']);
    if ($running) {
        @copy($log, $log . '.1');
        if ($fp = @fopen($log, 'c')) {
            @ftruncate($fp, 0);
            @fclose($fp);
        }
    } else {
        @rename($log, $log . '.1');
        if (!@file_exists($log)) {
            @file_put_contents($log, '');
        }
    }

    $overflow = $log . '.' . ($keep + 1);
    if (@file_exists($overflow)) {
        @unlink($overflow);
    }
}

function ptsb_log_clear_all(): void {
    $cfg  = ptsb_cfg();
    $log  = (string) ($cfg['log'] ?? '');
    $keep = max(1, (int) ($cfg['log_keep'] ?? 5));
    if ($log === '') {
        return;
    }

    $running = @file_exists((string) $cfg['lock']);

    for ($i = 1; $i <= ($keep + 5); $i++) {
        $path = $log . '.' . $i;
        if (@file_exists($path)) {
            @unlink($path);
        }
    }

    if ($running) {
        if ($fp = @fopen($log, 'c')) {
            @ftruncate($fp, 0);
            @fclose($fp);
        }
    } else {
        @unlink($log);
        @file_put_contents($log, '');
    }
}

function ptsb_mask_email($email, $keep = 7) {
    $email = trim((string) $email);
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }

    [$left, $domain] = explode('@', $email, 2);
    $keep = max(1, min((int) $keep, strlen($left)));
    $mask = str_repeat('*', max(0, strlen($left) - $keep));

    return substr($left, 0, $keep) . $mask . '@' . $domain;
}

function ptsb_hsize_compact($bytes): string {
    $bytes = (float) $bytes;
    if ($bytes >= 1073741824) {
        return number_format_i18n($bytes / 1073741824, 1) . 'G';
    }
    if ($bytes >= 1048576) {
        return number_format_i18n($bytes / 1048576, 1) . 'M';
    }
    if ($bytes >= 1024) {
        return number_format_i18n($bytes / 1024, 1) . 'K';
    }
    return number_format_i18n($bytes, 0) . 'B';
}

function ptsb_size_to_bytes($numStr, $unit) {
    $num  = (float) str_replace(',', '.', (string) $numStr);
    $unit = strtolower((string) $unit);
    $map  = [
        'b'   => 1,
        'kb'  => 1024,
        'kib' => 1024,
        'mb'  => 1048576,
        'mib' => 1048576,
        'gb'  => 1073741824,
        'gib' => 1073741824,
        'tb'  => 1099511627776,
        'tib' => 1099511627776,
    ];

    return (int) round($num * ($map[$unit] ?? 1));
}

function ptsb_manifest_read(string $tarFile): array {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell() || $tarFile === '') {
        return [];
    }

    $skipCache = defined('PTSB_SKIP_MANIFEST_CACHE') && PTSB_SKIP_MANIFEST_CACHE;
    $key       = 'ptsb_m_' . md5($tarFile);
    if (!$skipCache) {
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $jsonPath = ptsb_tar_to_json($tarFile);
    $env      = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 ';
    $out      = shell_exec($env . ' rclone cat ' . escapeshellarg($cfg['remote'] . $jsonPath) . ' 2>/dev/null');

    $data = json_decode((string) $out, true);
    if (!is_array($data)) {
        $data = [];
    }

    if (!$skipCache) {
        set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
    }

    return $data;
}

function ptsb_manifest_write(string $tarFile, array $add, bool $merge = true): bool {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell() || $tarFile === '') {
        return false;
    }

    $jsonPath = ptsb_tar_to_json($tarFile);
    $current  = $merge ? ptsb_manifest_read($tarFile) : [];
    if (!is_array($current)) {
        $current = [];
    }

    $data    = array_merge($current, $add);
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $tmp = @tempnam(sys_get_temp_dir(), 'ptsb');
    if ($tmp === false) {
        return false;
    }

    @file_put_contents($tmp, $payload);

    $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' cat ' . escapeshellarg($tmp)
         . ' | rclone rcat ' . escapeshellarg($cfg['remote'] . $jsonPath) . ' 2>/dev/null';

    shell_exec($cmd);
    @unlink($tmp);

    delete_transient('ptsb_m_' . md5($tarFile));

    return true;
}

function ptsb_parts_to_labels($partsStr): array {
    $map = [
        'db'      => 'Banco',
        'plugins' => 'Plugins',
        'themes'  => 'Temas',
        'uploads' => 'Mídia',
        'langs'   => 'Traduções',
        'config'  => 'Config',
        'core'    => 'Core',
        'scripts' => 'Scripts',
        'others'  => 'Outros',
    ];

    $out = [];
    foreach (array_filter(array_map('trim', explode(',', strtolower((string) $partsStr)))) as $p) {
        if (isset($map[$p])) {
            $out[] = $map[$p];
        }
    }

    return $out;
}

function ptsb_letters_from_parts($partsStr): array {
    $parts = array_filter(array_map('trim', explode(',', strtolower((string) $partsStr))));
    return [
        'p' => in_array('plugins', $parts, true),
        't' => in_array('themes', $parts, true),
        'w' => in_array('core', $parts, true),
        's' => in_array('scripts', $parts, true),
        'm' => in_array('uploads', $parts, true),
        'o' => in_array('others', $parts, true) || in_array('langs', $parts, true) || in_array('config', $parts, true),
        'd' => in_array('db', $parts, true),
    ];
}

function ptsb_ui_default_codes(): array {
    $defaults = ['d', 'p', 't', 'w', 's', 'm', 'o'];
    return apply_filters('ptsb_default_ui_codes', $defaults);
}

function ptsb_map_ui_codes_to_parts(array $codes): array {
    $codes = array_unique(array_map('strtolower', $codes));
    $parts = [];

    if (in_array('d', $codes, true)) {
        $parts[] = 'db';
    }
    if (in_array('p', $codes, true)) {
        $parts[] = 'plugins';
    }
    if (in_array('t', $codes, true)) {
        $parts[] = 'themes';
    }
    if (in_array('m', $codes, true)) {
        $parts[] = 'uploads';
    }
    if (in_array('w', $codes, true)) {
        $parts[] = 'core';
    }
    if (in_array('s', $codes, true)) {
        $parts[] = 'scripts';
    }
    if (in_array('o', $codes, true)) {
        $parts[] = 'others';
        $parts[] = 'config';
        $parts[] = 'langs';
    }

    $parts = array_values(array_unique($parts));

    return apply_filters('ptsb_map_ui_codes_to_parts', $parts, $codes);
}

function ptsb_drive_info(): array {
    $cfg  = ptsb_cfg();
    $info = ['email' => '', 'used' => null, 'total' => null];
    if (!ptsb_can_shell()) {
        return $info;
    }

    $env      = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 ';
    $remote   = $cfg['remote'];
    $remName  = rtrim($remote, ':');
    $aboutJson = shell_exec($env . ' rclone about ' . escapeshellarg($remote) . ' --json 2>/dev/null');
    $json = json_decode((string) $aboutJson, true);
    if (is_array($json)) {
        if (isset($json['used'])) {
            $info['used'] = (int) $json['used'];
        }
        if (isset($json['total'])) {
            $info['total'] = (int) $json['total'];
        }
    } else {
        $txt = (string) shell_exec($env . ' rclone about ' . escapeshellarg($remote) . ' 2>/dev/null');
        if (preg_match('/Used:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m)) {
            $info['used'] = ptsb_size_to_bytes($m[1], $m[2]);
        }
        if (preg_match('/Total:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m)) {
            $info['total'] = ptsb_size_to_bytes($m[1], $m[2]);
        }
    }

    $userinfo = (string) shell_exec($env . ' rclone backend userinfo ' . escapeshellarg($remote) . ' 2>/dev/null');
    if (trim($userinfo) === '') {
        $userinfo = (string) shell_exec($env . ' rclone config userinfo ' . escapeshellarg($remName) . ' 2>/dev/null');
    }

    if ($userinfo !== '') {
        $data = json_decode($userinfo, true);
        if (is_array($data)) {
            if (!empty($data['email'])) {
                $info['email'] = $data['email'];
            } elseif (!empty($data['user']['email'])) {
                $info['email'] = $data['user']['email'];
            } elseif (!empty($data['user']['emailAddress'])) {
                $info['email'] = $data['user']['emailAddress'];
            }
        } elseif (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $userinfo, $m)) {
            $info['email'] = $m[0];
        }
    }

    return $info;
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
    $cfg  = ptsb_cfg();
    $auto = is_array($auto) ? $auto : [];
    $enabled = !empty($auto['enabled']);
    if (!$enabled) {
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

/* -------------------------------------------------------
 * Helpers adicionais usados na migração/retentiva
 * -----------------------------------------------------*/
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

function ptsb_manifest_keep_days(array $manifest, ?int $default = null): ?int {
    if (isset($manifest['keep_days'])) {
        $days = (int) $manifest['keep_days'];
        if ($days >= 0) {
            return $days;
        }
    }
    if (isset($manifest['keep'])) {
        $days = (int) $manifest['keep'];
        if ($days >= 0) {
            return $days;
        }
    }
    return $default;
}

function ptsb_retention_calc(string $iso, int $keepDays): array {
    try {
        $created = new DateTimeImmutable($iso);
    } catch (Throwable $e) {
        $created = ptsb_now_brt();
    }

    $now     = ptsb_now_brt();
    $diffSec = max(0, $now->getTimestamp() - $created->getTimestamp());
    $elapsed = (int) floor($diffSec / 86400);
    $x       = min($keepDays, $elapsed + 1);
    $pct     = (int) round(($x / max(1, $keepDays)) * 100);

    return ['x' => $x, 'y' => $keepDays, 'pct' => $pct];
}

function ptsb_parts_to_letters($partsStr): array {
    $letters = [];
    foreach (array_filter(array_map('trim', explode(',', strtolower((string) $partsStr)))) as $part) {
        if ($part === 'db') {
            $letters['D'] = true;
        }
        if ($part === 'plugins') {
            $letters['P'] = true;
        }
        if ($part === 'themes') {
            $letters['T'] = true;
        }
        if ($part === 'core') {
            $letters['W'] = true;
        }
        if ($part === 'scripts') {
            $letters['S'] = true;
        }
        if ($part === 'uploads') {
            $letters['M'] = true;
        }
        if (in_array($part, ['others', 'langs', 'config'], true)) {
            $letters['O'] = true;
        }
    }

    return array_keys($letters);
}

function ptsb_letters_to_parts_csv(array $letters): string {
    $parts = [];
    foreach ($letters as $letter) {
        switch (strtoupper(trim($letter))) {
            case 'D':
                $parts[] = 'db';
                break;
            case 'P':
                $parts[] = 'plugins';
                break;
            case 'T':
                $parts[] = 'themes';
                break;
            case 'W':
                $parts[] = 'core';
                break;
            case 'S':
                $parts[] = 'scripts';
                break;
            case 'M':
                $parts[] = 'uploads';
                break;
            case 'O':
                $parts[] = 'others';
                $parts[] = 'langs';
                $parts[] = 'config';
                break;
        }
    }

    return implode(',', array_values(array_unique($parts)));
}

/* -------------------------------------------------------
 * Disparo do backup principal
 * -----------------------------------------------------*/
function ptsb_start_backup($partsCsv = null, $overridePrefix = null, $overrideDays = null): void {
    $cfg = ptsb_cfg();
    $set = ptsb_settings();
    if (!ptsb_can_shell()) {
        return;
    }
    if (file_exists($cfg['lock'])) {
        return;
    }

    ptsb_log_rotate_if_needed();

    if ($partsCsv === null) {
        $last    = get_option('ptsb_last_parts_ui', implode(',', ptsb_ui_default_codes()));
        $letters = array_values(array_intersect(
            array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $last)))),
            ['D', 'P', 'T', 'W', 'S', 'M', 'O']
        ));
        if (!$letters) {
            $letters = array_map('strtoupper', ptsb_ui_default_codes());
        }
        if (function_exists('ptsb_letters_to_parts_csv')) {
            $partsCsv = ptsb_letters_to_parts_csv($letters);
        } else {
            $partsCsv = implode(',', ptsb_map_ui_codes_to_parts(array_map('strtolower', $letters)));
        }
    }

    if (!$partsCsv) {
        $partsCsv = apply_filters('ptsb_default_parts', 'db,plugins,themes,uploads,langs,config,scripts');
    }

    $prefix = ($overridePrefix !== null && $overridePrefix !== '') ? $overridePrefix : $cfg['prefix'];

    if ($overrideDays !== null) {
        $keepDays = max(0, (int) $overrideDays);
    } else {
        $keepDays = (int) $set['keep_days'];
    }

    $env = 'PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . 'REMOTE='         . escapeshellarg($cfg['remote']) . ' '
         . 'WP_PATH='        . escapeshellarg(ABSPATH)        . ' '
         . 'PREFIX='         . escapeshellarg($prefix)         . ' '
         . 'KEEP_DAYS='      . escapeshellarg($keepDays)      . ' '
         . 'KEEP='           . escapeshellarg($keepDays)      . ' '
         . 'RETENTION_DAYS=' . escapeshellarg($keepDays)      . ' '
         . 'RETENTION='      . escapeshellarg($keepDays)      . ' '
         . 'KEEP_FOREVER='   . escapeshellarg($keepDays === 0 ? 1 : 0) . ' '
         . 'PARTS='          . escapeshellarg($partsCsv);

    update_option('ptsb_last_run_parts', (string) $partsCsv, true);

    $cmd = '/usr/bin/nohup /usr/bin/env ' . $env . ' ' . escapeshellarg($cfg['script_backup'])
         . ' >> ' . escapeshellarg($cfg['log']) . ' 2>&1 & echo $!';

    shell_exec($cmd);
}

function ptsb_log_has_success_marker(): bool {
    $cfg  = ptsb_cfg();
    $tail = (string) ptsb_tail_log_raw($cfg['log'], 800);

    if ($tail === '') {
        if (!get_transient('ptsb_notify_rl_tail_empty')) {
            set_transient('ptsb_notify_rl_tail_empty', 1, 60);
            ptsb_log('[notify] tail vazio — permitindo notificação.');
        }
        return true;
    }

    $patterns = [
        '/Backup finished successfully\.?/i',
        '/Backup finalizado com sucesso\.?/i',
        '/Uploaded and removing local bundle/i',
        '/Upload(?:ed)?\s+completed/i',
        '/All done/i',
    ];

    foreach ($patterns as $regex) {
        if (preg_match($regex, $tail)) {
            return true;
        }
    }

    if (!get_transient('ptsb_notify_rl_no_marker')) {
        set_transient('ptsb_notify_rl_no_marker', 1, 60);
        ptsb_log('[notify] sem marcador de sucesso nas últimas linhas — aguardando.');
    }

    return false;
}

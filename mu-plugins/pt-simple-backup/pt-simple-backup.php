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

/* -------------------------------------------------------
 * Helpers adicionais usados na migração/retentiva
 * -----------------------------------------------------*/
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

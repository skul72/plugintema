<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('PTSB_PLUGIN_DIR')) {
    define('PTSB_PLUGIN_DIR', __DIR__);
}
if (!defined('PTSB_INC_DIR')) {
    define('PTSB_INC_DIR', PTSB_PLUGIN_DIR . '/inc');
}
if (!defined('PTSB_BOOTSTRAP_READY')) {
    define('PTSB_BOOTSTRAP_READY', true);
}

if (!function_exists('ptsb_require_module')) {
    function ptsb_require_module(string $file): void {
        $path = PTSB_INC_DIR . '/' . ltrim($file, '/');
        if (is_readable($path)) {
            require_once $path;
        }
    }
}

/* -------------------------------------------------------
 * Configuração base (filtros permitem personalização)
 * -----------------------------------------------------*/
if (!function_exists('ptsb_cfg')) {
    function ptsb_cfg(): array {
        $cfg = [
            'remote'         => 'gdrive_backup:',
            'prefix'         => 'wpb-',
            'log'            => '/home/plugintema.com/Scripts/backup-wp.log',
            'lock'           => '/tmp/wpbackup.lock',
            'script_backup'  => '/home/plugintema.com/Scripts/wp-backup-to-gdrive.sh',
            'script_restore' => '/home/plugintema.com/Scripts/wp-restore-from-archive.sh',
            'download_dir'   => '/home/plugintema.com/Backups/downloads',
            'drive_url'      => 'https://drive.google.com/drive/u/0/folders/18wIaInN0d0ftKhsi1BndrKmkVuOQkFoO',
            'keep_days_def'  => 12,
            'tz_string'      => 'America/Sao_Paulo',
            'cron_hook'      => 'ptsb_cron_tick',
            'cron_sched'     => 'ptsb_minutely',
            'max_per_day'    => 6,
            'min_gap_min'    => 10,
            'miss_window'    => 15,
            'queue_timeout'  => 5400,
            'log_max_mb'     => 3,
            'log_keep'       => 5,
        ];

        $cfg = apply_filters('ptsb_config', $cfg);
        $cfg['remote'] = apply_filters('ptsb_remote', $cfg['remote']);
        $cfg['prefix'] = apply_filters('ptsb_prefix', $cfg['prefix']);

        return $cfg;
    }
}

if (!function_exists('ptsb_settings')) {
    function ptsb_settings(): array {
        $cfg  = ptsb_cfg();
        $days = max(1, (int) get_option('ptsb_keep_days', $cfg['keep_days_def']));

        return ['keep_days' => min($days, 3650)];
    }
}

if (!function_exists('ptsb_plan_mark_keep_next')) {
    function ptsb_plan_mark_keep_next($prefix): void {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            $prefix = ptsb_cfg()['prefix'];
        }

        update_option('ptsb_mark_keep_plan', ['prefix' => $prefix, 'set_at' => time()], true);
    }
}

if (!function_exists('ptsb_apply_keep_sidecar')) {
    function ptsb_apply_keep_sidecar($file) {
        $cfg = ptsb_cfg();
        if (!ptsb_can_shell() || $file === '') {
            return false;
        }

        $touch = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
               . ' rclone touch ' . escapeshellarg($cfg['remote'] . $file . '.keep') . ' --no-create-dirs';
        $rcat  = 'printf "" | /usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
               . ' rclone rcat ' . escapeshellarg($cfg['remote'] . $file . '.keep');

        shell_exec($touch . ' || ' . $rcat);

        return true;
    }
}

if (!function_exists('ptsb_auto_get')) {
    function ptsb_auto_get(): array {
        $cfg   = ptsb_cfg();
        $en    = (bool) get_option('ptsb_auto_enabled', false);
        $qty   = max(1, min((int) get_option('ptsb_auto_qty', 1), $cfg['max_per_day']));
        $times = get_option('ptsb_auto_times', []);
        if (!is_array($times)) {
            $times = [];
        }
        $times = array_values(array_filter(array_map('strval', $times)));

        $mode = get_option('ptsb_auto_mode', 'daily');
        $mcfg = get_option('ptsb_auto_cfg', []);
        if (!is_array($mcfg)) {
            $mcfg = [];
        }

        $state = get_option('ptsb_auto_state', []);
        if (!is_array($state)) {
            $state = [];
        }
        $state += ['last_by_slot' => [], 'queued_slot' => '', 'queued_at' => 0];
        if (!is_array($state['last_by_slot'])) {
            $state['last_by_slot'] = [];
        }

        return [
            'enabled' => $en,
            'qty'     => $qty,
            'times'   => $times,
            'mode'    => $mode,
            'cfg'     => $mcfg,
            'state'   => $state,
        ];
    }
}

if (!function_exists('ptsb_auto_save')) {
    function ptsb_auto_save($enabled, $qty, $times, $state = null, $mode = null, $mcfg = null): void {
        $cfg = ptsb_cfg();
        update_option('ptsb_auto_enabled', (bool) $enabled, true);
        update_option('ptsb_auto_qty', max(1, min((int) $qty, $cfg['max_per_day'])), true);
        update_option('ptsb_auto_times', array_values($times), true);

        if ($mode !== null) {
            update_option('ptsb_auto_mode', $mode, true);
        }
        if ($mcfg !== null) {
            update_option('ptsb_auto_cfg', $mcfg, true);
        }
        if ($state !== null) {
            update_option('ptsb_auto_state', $state, true);
        }
    }
}

if (!function_exists('ptsb_list_remote_files')) {
    function ptsb_list_remote_files(): array {
        $cfg = ptsb_cfg();
        if (!ptsb_can_shell()) {
            return [];
        }

        $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
             . ' rclone lsf ' . escapeshellarg($cfg['remote'])
             . ' --files-only --format "tsp" --separator ";" --time-format RFC3339 '
             . ' --include ' . escapeshellarg('*.tar.gz') . ' --fast-list';

        $out  = shell_exec($cmd);
        $rows = [];
        foreach (array_filter(array_map('trim', explode("\n", (string) $out))) as $line) {
            $parts = explode(';', $line, 3);
            if (count($parts) === 3) {
                $rows[] = ['time' => $parts[0], 'size' => $parts[1], 'file' => $parts[2]];
            }
        }

        usort($rows, fn($a, $b) => strcmp($b['time'], $a['time']));

        return $rows;
    }
}

if (!function_exists('ptsb_keep_map')) {
    function ptsb_keep_map(): array {
        $cfg = ptsb_cfg();
        if (!ptsb_can_shell()) {
            return [];
        }

        $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
             . ' rclone lsf ' . escapeshellarg($cfg['remote'])
             . ' --files-only --format "p" --separator ";" '
             . ' --include ' . escapeshellarg('*.tar.gz.keep') . ' --fast-list';

        $out = shell_exec($cmd);
        $map = [];
        foreach (array_filter(array_map('trim', explode("\n", (string) $out))) as $path) {
            $file = preg_replace('/\\.keep$/', '', $path);
            if ($file !== '') {
                $map[$file] = true;
            }
        }

        return $map;
    }
}

/* -------------------------------------------------------
 * Utilidades compartilhadas entre os módulos
 * -----------------------------------------------------*/
ptsb_require_module('log.php');
ptsb_require_module('parts.php');
ptsb_require_module('rclone.php');
ptsb_require_module('schedule.php');
ptsb_require_module('actions.php');
ptsb_require_module('ajax.php');
ptsb_require_module('ui.php');

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

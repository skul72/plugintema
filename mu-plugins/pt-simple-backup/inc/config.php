<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Carrega a configuração padrão do plugin e permite overrides via filtros legacy.
 *
 * Filtros disponíveis:
 * - ptsb_config: altera o array completo
 * - ptsb_remote: ajusta o remote do rclone (ex.: 'meudrive:')
 * - ptsb_prefix: ajusta o prefixo dos arquivos (ex.: 'site-')
 */
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

<?php

if (!defined('ABSPATH')) {
    exit;
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

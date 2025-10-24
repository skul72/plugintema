<?php
if (!defined('ABSPATH')) {
    exit;
}

function ptsb_log($msg) {
    $cfg = ptsb_cfg();
    ptsb_log_rotate_if_needed();
    $line = '[' . ptsb_now_brt()->format('d-m-Y-H:i') . '] ' . strip_tags($msg) . "\n";
    @file_put_contents($cfg['log'], $line, FILE_APPEND);
}

function ptsb_log_rotate_if_needed(): void {
    $cfg   = ptsb_cfg();
    $log   = (string)($cfg['log'] ?? '');
    $keep  = max(1, (int)($cfg['log_keep']    ?? 5));
    $maxMb = (float)  ($cfg['log_max_mb']     ?? 3);
    $limit = max(1, (int)round($maxMb * 1048576));

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

    $running = @file_exists((string)$cfg['lock']);
    if ($running) {
        @copy($log, $log . '.1');
        if ($fp = @fopen($log, 'c')) {
            @ftruncate($fp, 0);
            @fclose($fp);
        }
    } else {
        @rename($log, $log . '.1');
        if (!@file_exists($log)) {
            @file_put_contents($log, "");
        }
    }

    $overflow = $log . '.' . ($keep + 1);
    if (@file_exists($overflow)) {
        @unlink($overflow);
    }
}

function ptsb_log_clear_all(): void {
    $cfg  = ptsb_cfg();
    $log  = (string)($cfg['log'] ?? '');
    $keep = max(1, (int)($cfg['log_keep'] ?? 5));
    if ($log === '') {
        return;
    }

    $running = @file_exists((string)$cfg['lock']);

    for ($i = 1; $i <= ($keep + 5); $i++) {
        $p = $log . '.' . $i;
        if (@file_exists($p)) {
            @unlink($p);
        }
    }

    if ($running) {
        if ($fp = @fopen($log, 'c')) {
            @ftruncate($fp, 0);
            @fclose($fp);
        }
    } else {
        @unlink($log);
        @file_put_contents($log, "");
    }
}

function ptsb_tail_log_raw($path, $n = 50) {
    if (!@file_exists($path)) {
        return "Log nao encontrado em: $path";
    }
    if (ptsb_can_shell()) {
        $txt = shell_exec('tail -n ' . intval($n) . ' ' . escapeshellarg($path));
        if ($txt !== null && $txt !== false && $txt !== '') {
            return ptsb_to_utf8((string)$txt);
        }
    }
    $f = @fopen($path, 'rb');
    if (!$f) {
        return "Sem acesso de leitura ao log: $path";
    }
    $lines = [];
    $buffer = '';
    fseek($f, 0, SEEK_END);
    $filesize = ftell($f);
    $chunk = 4096;
    while ($filesize > 0 && count($lines) <= $n) {
        $seek = max($filesize - $chunk, 0);
        $read = $filesize - $seek;
        fseek($f, $seek);
        $buffer = fread($f, $read) . $buffer;
        $filesize = $seek;
        $lines = explode("\n", $buffer);
    }
    fclose($f);
    $lines = array_slice($lines, -$n);
    return ptsb_to_utf8(implode("\n", $lines));
}


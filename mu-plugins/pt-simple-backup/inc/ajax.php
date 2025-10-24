<?php

if (!defined('ABSPATH')) {
    exit;
}

function ptsb_tail_log_raw($path, $n = 50) {
    if (!@file_exists($path)) {
        return "Log nao encontrado em: $path";
    }

    if (ptsb_can_shell()) {
        $txt = shell_exec('tail -n ' . intval($n) . ' ' . escapeshellarg($path));
        if ($txt !== null && $txt !== false && $txt !== '') {
            return ptsb_to_utf8((string) $txt);
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

add_action('wp_ajax_ptsb_status', function () {
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ptsb_nonce')) {
        wp_send_json_error('bad nonce', 403);
    }

    $cfg = ptsb_cfg();
    $tail = ptsb_tail_log_raw($cfg['log'], 50);

    $percent = 0;
    $stage = 'idle';

    if ($tail) {
        $lines = explode("\n", $tail);
        $start_ix = 0;

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (strpos($lines[$i], '=== Start WP backup') !== false) {
                $start_ix = $i;
                break;
            }
        }

        $section = implode("\n", array_slice($lines, $start_ix));
        $map = [
            'Dumping DB'                         => 15,
            'Dumping database'                   => 15,
            'Archiving selected parts'           => 35,
            'Creating final bundle'              => 55,
            'Uploading to'                       => 75,
            'Uploaded and removing local bundle' => 85,
            'Applying retention'                 => 95,
            'Backup finished successfully.'      => 100,
            'Backup finalizado com sucesso.'     => 100,
        ];

        foreach ($map as $k => $p) {
            if (strpos($section, $k) !== false) {
                $percent = max($percent, $p);
                $stage = $k;
            }
        }
    }

    $running = file_exists($cfg['lock']) && $percent < 100;

    wp_send_json_success([
        'running' => (bool) $running,
        'percent' => (int) $percent,
        'stage'   => (string) $stage,
        'log'     => (string) $tail,
    ]);
});

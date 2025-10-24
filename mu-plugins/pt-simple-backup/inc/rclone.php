<?php
if (!defined('ABSPATH')) {
    exit;
}

function ptsb_manifest_read(string $tarFile): array {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) {
        return [];
    }

    $key       = 'ptsb_m_' . md5($tarFile);
    $skipCache = defined('PTSB_SKIP_MANIFEST_CACHE') && PTSB_SKIP_MANIFEST_CACHE;

    if (!$skipCache) {
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $jsonPath = ptsb_tar_to_json($tarFile);
    $env      = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 ';
    $out      = shell_exec($env . ' rclone cat ' . escapeshellarg($cfg['remote'] . $jsonPath) . ' 2>/dev/null');

    $data = json_decode((string)$out, true);
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
    $cur = $merge ? ptsb_manifest_read($tarFile) : [];
    if (!is_array($cur)) {
        $cur = [];
    }

    $data    = array_merge($cur, $add);
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

function ptsb_list_remote_files() {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) {
        return [];
    }
    $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' rclone lsf ' . escapeshellarg($cfg['remote'])
         . ' --files-only --format "tsp" --separator ";" --time-format RFC3339 '
         . ' --include ' . escapeshellarg('*.tar.gz') . ' --fast-list';
    $out = shell_exec($cmd);
    $rows = [];
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $ln) {
        $parts = explode(';', $ln, 3);
        if (count($parts) === 3) {
            $rows[] = ['time' => $parts[0], 'size' => $parts[1], 'file' => $parts[2]];
        }
    }
    usort($rows, fn($a, $b) => strcmp($b['time'], $a['time']));
    return $rows;
}

function ptsb_keep_map() {
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
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $p) {
        $base = preg_replace('/\.keep$/', '', $p);
        if ($base) {
            $map[$base] = true;
        }
    }
    return $map;
}

function ptsb_drive_info() {
    $cfg  = ptsb_cfg();
    $info = ['email' => '', 'used' => null, 'total' => null];
    if (!ptsb_can_shell()) {
        return $info;
    }

    $env      = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 ';
    $remote   = $cfg['remote'];
    $rem_name = rtrim($remote, ':');

    $aboutJson = shell_exec($env . ' rclone about ' . escapeshellarg($remote) . ' --json 2>/dev/null');
    $j = json_decode((string)$aboutJson, true);
    if (is_array($j)) {
        if (isset($j['used'])) {
            $info['used'] = (int)$j['used'];
        }
        if (isset($j['total'])) {
            $info['total'] = (int)$j['total'];
        }
    } else {
        $txt = (string)shell_exec($env . ' rclone about ' . escapeshellarg($remote) . ' 2>/dev/null');
        if (preg_match('/Used:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m)) {
            $info['used'] = ptsb_size_to_bytes($m[1], $m[2]);
        }
        if (preg_match('/Total:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m)) {
            $info['total'] = ptsb_size_to_bytes($m[1], $m[2]);
        }
    }

    $u = (string)shell_exec($env . ' rclone backend userinfo ' . escapeshellarg($remote) . ' 2>/dev/null');
    if (trim($u) === '') {
        $u = (string)shell_exec($env . ' rclone config userinfo ' . escapeshellarg($rem_name) . ' 2>/dev/null');
    }
    if ($u !== '') {
        $ju = json_decode($u, true);
        if (is_array($ju)) {
            if (!empty($ju['email'])) {
                $info['email'] = $ju['email'];
            } elseif (!empty($ju['user']['email'])) {
                $info['email'] = $ju['user']['email'];
            } elseif (!empty($ju['user']['emailAddress'])) {
                $info['email'] = $ju['user']['emailAddress'];
            }
        } else {
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $u, $m)) {
                $info['email'] = $m[0];
            }
        }
    }
    return $info;
}


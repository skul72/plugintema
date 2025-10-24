<?php

if (!defined('ABSPATH')) {
    exit;
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

<?php

if (!defined('ABSPATH')) {
    exit;
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

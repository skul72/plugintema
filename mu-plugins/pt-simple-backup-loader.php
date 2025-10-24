<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('PTSB_PLUGIN_DIR')) {
    define('PTSB_PLUGIN_DIR', __DIR__ . '/pt-simple-backup');
}
if (!defined('PTSB_INC_DIR')) {
    define('PTSB_INC_DIR', PTSB_PLUGIN_DIR . '/inc');
}
if (!defined('PTSB_ASSETS_DIR')) {
    define('PTSB_ASSETS_DIR', PTSB_PLUGIN_DIR . '/assets');
}

$ptsb_main = PTSB_PLUGIN_DIR . '/pt-simple-backup.php';
if (is_readable($ptsb_main)) {
    require_once $ptsb_main;
}

$ptsb_includes = [
    'config.php',
    'log.php',
    'parts.php',
    'rclone.php',
    'schedule.php',
    'actions.php',
    'ajax.php',
    'ui.php',
];

foreach ($ptsb_includes as $ptsb_file) {
    $path = PTSB_INC_DIR . '/' . $ptsb_file;
    if (is_readable($path)) {
        require_once $path;
    }
}

add_action('admin_menu', function () {
    $callback = function () {
        if (function_exists('ptsb_render_backup_page')) {
            ptsb_render_backup_page();
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Backup</h1>';
        echo '<p>Interface temporariamente indisponível.</p>';
        echo '</div>';
    };

    add_management_page(
        'Backup',
        'Backup',
        'manage_options',
        'pt-simple-backup',
        $callback
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'tools_page_pt-simple-backup') {
        return;
    }

    wp_enqueue_style('dashicons');
});

add_action('admin_head-tools_page_pt-simple-backup', function () {
    $css = PTSB_ASSETS_DIR . '/admin.css';
    if (is_readable($css)) {
        include $css;
    }
});

add_action('admin_footer-tools_page_pt-simple-backup', function () {
    $js = PTSB_ASSETS_DIR . '/admin.js';
    if (is_readable($js)) {
        include $js;
    }
});

add_action('load-tools_page_pt-simple-backup', function () {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCDN')) {
        define('DONOTCDN', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }

    $force = isset($_GET['force']) && (int) $_GET['force'] === 1;
    if ($force && !defined('PTSB_SKIP_MANIFEST_CACHE')) {
        define('PTSB_SKIP_MANIFEST_CACHE', true);
    }

    if (defined('LSCWP_VERSION')) {
        do_action('litespeed_control_set_nocache');
    }

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
});

<?php
/**
 * Plugin Name: PT Simple Backup (Loader)
 * Description: Carrega o MU plugin localizado em /wp-content/mu-plugins/pt-simple-backup/.
 * Author: Plugin Tema
 * Version: 0.1.0
 * Requires at least: 5.2
 */

if (!defined('ABSPATH')) { exit; }

/**
 * Caminhos base do diretório de MU plugins
 * WPMU_PLUGIN_DIR e WPMU_PLUGIN_URL são constantes nativas do WP.
 */
$base_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

/**
 * Arquivo bootstrap dentro da subpasta:
 *   wp-content/mu-plugins/
 *     +- pt-simple-backup.php          (este loader)
 *     +- pt-simple-backup/
 *         +- pt-simple-backup.php      (bootstrap)
 *         +- inc/
 *         +- assets/
 */
$bootstrap = rtrim($base_dir, '/\\') . '/pt-simple-backup/pt-simple-backup.php';

if (file_exists($bootstrap)) {
    require_once $bootstrap;
} else {
    // Mostra aviso somente a administradores
    if (is_admin()) {
        add_action('admin_notices', function () use ($bootstrap) {
            echo '<div class="notice notice-error"><p><strong>PT Simple Backup:</strong> '
               . 'não foi possível carregar o bootstrap em <code>'
               . esc_html($bootstrap)
               . '</code>.<br>Verifique se a pasta <code>pt-simple-backup/</code> existe em '
               . '<code>wp-content/mu-plugins/</code> e contém o arquivo <code>pt-simple-backup.php</code>.'
               . '</p></div>';
        });
    }
}

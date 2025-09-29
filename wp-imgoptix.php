<?php
/**
 * Plugin Name: WP ImgOptix
 * Plugin URI:  https://github.com/prangishviliAbe/WP-ImgOptix.git
 * Description: Selective image optimizer for WordPress. Features bulk and per-image optimization, on-upload optimization (optional), compression presets, per-item backups, a modern admin UI with grid/list views, progress and per-card diagnostics, retry/backoff with 503 pause, and GD/Imagick fallbacks. Includes client-side concurrency controls and reduce-server-pressure mode.
 * Version:     1.0.0
 * Author:      Abe Prangishvili
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-imgoptix.php';

function run_imgoptix_plugin() {
    $plugin = new \ImgOptix\ImgOptix( __FILE__ );
    $plugin->run();
}

run_imgoptix_plugin();

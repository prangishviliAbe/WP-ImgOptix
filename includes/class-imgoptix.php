<?php
namespace ImgOptix;

if ( ! defined( 'WPINC' ) ) {
    die;
}

class ImgOptix {
    private $plugin_file;
    private $option_name = 'imgoptix_settings';
    private $plugin_url;

    public function __construct( $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_url = plugin_dir_url( $this->plugin_file );
    }

    public function run() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'maybe_optimize_on_upload' ), 20, 2 );
        add_action( 'admin_post_imgoptix_bulk_optimize', array( $this, 'handle_bulk_optimize' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_imgoptix_optimize', array( $this, 'ajax_optimize_attachment' ) );
        add_action( 'wp_ajax_imgoptix_list_attachments', array( $this, 'ajax_list_attachments' ) );
    }

    public function add_admin_menu() {
        // Add a top-level menu so the plugin appears in a prominent place in the admin sidebar
        // Position 60 places it near Media; adjust if you prefer a different position
        add_menu_page( 'ImgOptix', 'ImgOptix', 'manage_options', 'imgoptix', array( $this, 'settings_page' ), 'dashicons-images-alt2', 60 );
    }

    public function register_settings() {
        register_setting( $this->option_name, $this->option_name );

        add_settings_section(
            'imgoptix_main',
            'Main Settings',
            function() { echo '<p>Configure ImgOptix behavior.</p>'; },
            $this->option_name
        );

        add_settings_field(
            'optimize_on_upload',
            'Optimize on upload',
            function() {
                $opts = get_option( $this->option_name, array( 'optimize_on_upload' => 1 ) );
                $checked = ! empty( $opts['optimize_on_upload'] ) ? 'checked' : '';
                echo "<input type=\"checkbox\" name=\"{$this->option_name}[optimize_on_upload]\" value=\"1\" $checked />";
            },
            $this->option_name,
            'imgoptix_main'
        );

        add_settings_field(
            'compression_level',
            'Compression level',
            function() {
                $opts = get_option( $this->option_name, array( 'compression_level' => 'medium' ) );
                $level = isset( $opts['compression_level'] ) ? $opts['compression_level'] : 'medium';
                ?>
                <select name="<?php echo esc_attr( $this->option_name ); ?>[compression_level]">
                    <option value="light" <?php selected( $level, 'light' ); ?>>Light (higher quality)</option>
                    <option value="medium" <?php selected( $level, 'medium' ); ?>>Medium (balanced)</option>
                    <option value="aggressive" <?php selected( $level, 'aggressive' ); ?>>Aggressive (smaller files)</option>
                </select>
                <?php
            },
            $this->option_name,
            'imgoptix_main'
        );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $opts = get_option( $this->option_name, array( 'optimize_on_upload' => 1 ) );
        ?>
        <div class="wrap imgoptix-settings-wrap">
            <div class="imgoptix-header" style="display:flex;align-items:center;gap:18px;justify-content:space-between;">
                <div>
                    <h1 style="margin:0">ImgOptix</h1>
                    <p style="margin:0;color:#666">Selective image optimizer with presets, backups and modern admin UI.</p>
                </div>
            </div>

            <form method="post" action="options.php" class="imgoptix-settings" style="margin-top:18px;">
                <?php settings_fields( $this->option_name ); ?>

                <div class="imgoptix-settings-top" style="display:flex;gap:18px;align-items:flex-start;">
                    <div class="setting-card" style="width:100%;background:#fff;border:1px solid #e6e6e6;padding:16px;border-radius:8px;">
                        <h2 style="font-size:16px;margin-top:0">Settings</h2>
                        <?php $opts = get_option( $this->option_name, array( 'optimize_on_upload' => 1, 'compression_level' => 'medium' ) ); ?>
                        <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-top:8px;">
                            <div style="flex:0 0 auto;">
                                <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[optimize_on_upload]" value="1" <?php $checked = ! empty( $opts['optimize_on_upload'] ) ? 'checked' : ''; echo $checked; ?> /> Optimize on upload</label>
                            </div>
                            <div style="flex:0 0 220px;">
                                <label style="display:block;margin-bottom:6px;font-weight:600">Compression level</label>
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[compression_level]" style="width:220px;padding:8px;border:1px solid #ddd;border-radius:6px;">
                                    <option value="light" <?php selected( $opts['compression_level'], 'light' ); ?>>Light</option>
                                    <option value="medium" <?php selected( $opts['compression_level'], 'medium' ); ?>>Medium</option>
                                    <option value="aggressive" <?php selected( $opts['compression_level'], 'aggressive' ); ?>>Aggressive (smaller files)</option>
                                </select>
                            </div>
                            <div style="flex:1"></div>
                            <div style="flex:0 0 auto;">
                                <?php submit_button( 'Save Settings', 'primary', 'imgoptix_save_settings' ); ?>
                            </div>
                        </div>
                        <p style="margin-top:6px;color:#777;font-size:13px">Choose default compression behavior for uploads and bulk operations. You can override per-run in the gallery toolbar.</p>
                    </div>
                </div>

                <!-- Bulk area (full width) -->
                <div id="imgoptix-bulk-area" style="width:100%;margin-top:18px;">
                    <div class="setting-card" style="background:#fff;border:1px solid #e6e6e6;padding:12px;border-radius:8px;margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                            <div>
                                <h2 style="font-size:16px;margin:0">Bulk Optimize</h2>
                                <p style="margin:0;color:#666;font-size:13px">Load, sort and optimize attachments in bulk.</p>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <label style="display:flex;align-items:center;gap:6px;color:#666;font-size:13px">View</label>
                                <div style="display:flex;gap:6px;">
                                    <button type="button" class="button" id="imgoptix-view-grid" title="Grid view">Grid</button>
                                    <button type="button" class="button" id="imgoptix-view-list" title="List view">List</button>
                                </div>
                                <label style="display:flex;align-items:center;gap:6px;color:#666;font-size:13px;margin-left:8px">Sort</label>
                                <select id="imgoptix-sort-by" style="padding:6px;border-radius:6px;border:1px solid #ddd;">
                                    <option value="size_desc">Size (largest first)</option>
                                    <option value="size_asc">Size (smallest first)</option>
                                    <option value="name_asc">Name (A–Z)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <p style="margin-top:6px;">
                        <button class="button button-primary" id="imgoptix-load-images">Load Images</button>
                        <button class="button" id="imgoptix-select-all">Select all</button>
                        <button class="button" id="imgoptix-deselect-all">Deselect all</button>
                        <label style="margin-left:12px"><input type="checkbox" id="imgoptix-backup-originals" /> Keep backup copies</label>
                    </p>

                    <div id="imgoptix-grid-wrap" style="margin-top:12px;">
                        <div id="imgoptix-toolbar" class="imgoptix-toolbar" style="margin-bottom:10px; display:flex; gap:8px; align-items:center;">
                            <button class="button button-primary" id="imgoptix-optimize-top">Optimize Selected</button>
                            <label style="display:flex;align-items:center;gap:8px;margin-left:8px;">
                                <span style="font-size:13px;color:#666">Level</span>
                                <select id="imgoptix-compression-level" style="padding:6px;border-radius:6px;border:1px solid #ddd;">
                                    <option value="light">Light</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="aggressive">Aggressive</option>
                                </select>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;margin-left:8px;">
                                <span style="font-size:13px;color:#666">Workers</span>
                                <select id="imgoptix-concurrency" style="padding:6px;border-radius:6px;border:1px solid #ddd;">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3" selected>3</option>
                                    <option value="4">4</option>
                                    <option value="6">6</option>
                                </select>
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;margin-left:8px;color:#666;font-size:13px"><input type="checkbox" id="imgoptix-reduce-pressure" /> Reduce server pressure</label>
                            <div id="imgoptix-selected-count" style="margin-left:12px;color:#666">0 selected</div>
                        </div>

                        <div id="imgoptix-grid" class="imgoptix-grid">
                            <div class="imgoptix-empty">Click "Load Images" to populate the gallery.</div>
                        </div>
                    </div>

                    <p style="margin-top:12px;">
                        <button class="button button-primary" id="imgoptix-optimize-selected">Optimize Selected</button>
                        <span id="imgoptix-summary" style="margin-left:12px"></span>
                    </p>

                    <div id="imgoptix-progress-wrap" style="margin-top:12px; display:none">
                        <div id="imgoptix-progress" style="background:#eee; height:14px; border-radius:3px; overflow:hidden; max-width:720px;">
                            <div id="imgoptix-progress-bar" style="height:100%; width:0; background:linear-gradient(90deg,#26a69a,#2bbbad);"></div>
                        </div>
                        <div id="imgoptix-progress-text" style="margin-top:6px"></div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    public function enqueue_admin_assets( $hook ) {
        // Only load on our plugin page. The menu is added with add_plugins_page so the hook is usually 'plugins_page_imgoptix'
        if ( false === strpos( $hook, 'imgoptix' ) && false === strpos( $hook, 'plugins_page' ) ) {
            return;
        }

        // Use file modification time as version to bust caches when we update assets.
        $js_path  = plugin_dir_path( $this->plugin_file ) . 'assets/js/admin.js';
        $css_path = plugin_dir_path( $this->plugin_file ) . 'assets/css/admin.css';
        $js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : '0.1.0';
        $css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : '0.1.0';

        wp_enqueue_style( 'imgoptix-admin-v2', $this->plugin_url . 'assets/css/admin.css', array(), $css_ver );
    wp_enqueue_script( 'imgoptix-admin-v2', $this->plugin_url . 'assets/js/admin.v2.js', array( 'jquery' ), $js_ver, true );
        wp_localize_script( 'imgoptix-admin-v2', 'ImgOptix', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'imgoptix_ajax' ),
        ) );
    }

    public function ajax_optimize_attachment() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }

        check_ajax_referer( 'imgoptix_ajax', 'nonce' );

        $attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
        if ( ! $attachment_id ) {
            wp_send_json_error( array( 'message' => 'Missing attachment_id' ), 400 );
        }

        $args = array();
        if ( isset( $_POST['max_width'] ) ) {
            $args['max_width'] = intval( $_POST['max_width'] );
        }
        if ( isset( $_POST['backup'] ) ) {
            $args['backup'] = intval( $_POST['backup'] );
        }

        $result = $this->perform_optimization( $attachment_id, $args );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: return a paginated list of image attachments with thumbnail and filesize
     */
    public function ajax_list_attachments() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Not logged in' ), 403 );
        }

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions to list media (requires upload_files capability)' ), 403 );
        }

        $per_page = isset( $_REQUEST['per_page'] ) ? max( 1, intval( $_REQUEST['per_page'] ) ) : 100;
        $paged = isset( $_REQUEST['page'] ) ? max( 1, intval( $_REQUEST['page'] ) ) : 1;

        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            // include common statuses; some installations may store attachments differently
            'post_status'    => array( 'inherit', 'private', 'publish' ),
            // allow plugins/themes to modify the query
            'suppress_filters' => false,
        );

        $query = new \WP_Query( $args );
        if ( is_wp_error( $query ) ) {
            wp_send_json_error( array( 'message' => 'Query error' ) );
        }
        $items = array();
        foreach ( $query->posts as $id ) {
            $thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
            $thumb_url = $thumb ? $thumb[0] : wp_get_attachment_url( $id );
            $title = get_the_title( $id );
            $path = get_attached_file( $id );
            $size = ( $path && file_exists( $path ) ) ? filesize( $path ) : 0;

            $items[] = array(
                'id'        => $id,
                'url'       => $thumb_url,
                'title'     => $title,
                'size'      => $size,
                'human'     => $this->human_filesize( $size ),
            );
        }

        // If query found nothing, try a direct DB fallback (bypass possible filters)
        $fallback_used = false;
        if ( empty( $items ) ) {
            global $wpdb;
            $sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_mime_type LIKE %s ORDER BY ID DESC LIMIT %d", 'attachment', 'image/%', $per_page );
            $ids = $wpdb->get_col( $sql );
            if ( ! empty( $ids ) ) {
                $fallback_used = true;
                foreach ( $ids as $id ) {
                    $thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
                    $thumb_url = $thumb ? $thumb[0] : wp_get_attachment_url( $id );
                    $title = get_the_title( $id );
                    $path = get_attached_file( $id );
                    $size = ( $path && file_exists( $path ) ) ? filesize( $path ) : 0;

                    $items[] = array(
                        'id'    => $id,
                        'url'   => $thumb_url,
                        'title' => $title,
                        'size'  => $size,
                        'human' => $this->human_filesize( $size ),
                    );
                }
            }
        }

        $response = array(
            'items'      => $items,
            'total'      => isset( $query->found_posts ) ? intval( $query->found_posts ) : count( $items ),
            'per_page'   => $per_page,
            'paged'      => $paged,
        );

        // Optional debug info for admins
        if ( isset( $_REQUEST['debug'] ) && current_user_can( 'manage_options' ) ) {
            $response['debug'] = array(
                'query_args' => $args,
                'found_posts' => isset( $query->found_posts ) ? intval( $query->found_posts ) : 0,
                'returned' => count( $items ),
                'fallback_used' => $fallback_used,
            );
        }

        wp_send_json_success( $response );
    }

    /**
     * Perform optimization and return sizes and status.
     * Returns array: orig_size, new_size, saved (bool)
     */
    /**
     * Perform optimization and return sizes and status.
     * Supports optional resizing via $args['max_width'] and backup via $args['backup'].
     */
    private function perform_optimization( $attachment_id, $args = array() ) {
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            return new \WP_Error( 'no_file', 'Attachment file not found on disk' );
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
            return new \WP_Error( 'unsupported_mime', 'Unsupported image MIME type: ' . $mime );
        }

        $orig_size = filesize( $path );

        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return new \WP_Error( 'editor_error', 'Unable to load image editor: ' . $editor->get_error_message() );
        }

        // Optionally resize if max_width passed and image is wider than that
        $max_width = isset( $args['max_width'] ) && intval( $args['max_width'] ) > 0 ? intval( $args['max_width'] ) : 0;

        // Create backup if requested
        $backup_done = false;
        if ( ! empty( $args['backup'] ) ) {
            $backup_path = $path . '.backup-' . time();
            if ( ! @copy( $path, $backup_path ) ) {
                // backup failed but continue, report in result
                $backup_done = false;
            } else {
                $backup_done = true;
            }
        }

        // If resizing requested, load image size and resize if necessary
        if ( $max_width ) {
            $size = $editor->get_size();
            if ( $size && isset( $size['width'] ) && $size['width'] > $max_width ) {
                $editor->resize( $max_width, null );
            }
        }

        // Determine compression level from settings or args (args override)
        $opts = get_option( $this->option_name, array( 'compression_level' => 'medium' ) );
        $level = isset( $opts['compression_level'] ) ? $opts['compression_level'] : 'medium';
        if ( isset( $args['compression_level'] ) ) {
            $level = $args['compression_level'];
        }

        // Map logical levels to numeric quality/compression settings
        // For JPEG/WEBP: quality 0-100 (higher = better quality)
        // For PNG: compression level 0-9 (higher = smaller files)
        switch ( $level ) {
            case 'light':
                $jpeg_quality = 90;
                $png_compress = 4;
                $webp_quality = 90;
                break;
            case 'aggressive':
                $jpeg_quality = 75;
                $png_compress = 8;
                $webp_quality = 75;
                break;
            case 'medium':
            default:
                $jpeg_quality = 85;
                $png_compress = 6;
                $webp_quality = 85;
                break;
        }

        if ( $mime === 'image/jpeg' ) {
            $editor->set_quality( $jpeg_quality );
        } else if ( $mime === 'image/png' ) {
            // Some editors use set_quality for PNG, but we keep numeric mapping for GD fallback
            if ( method_exists( $editor, 'set_quality' ) ) {
                $editor->set_quality( 90 ); // keep default for WP editor; GD will respect $png_compress
            }
        } else if ( $mime === 'image/webp' ) {
            $editor->set_quality( $webp_quality );
        }

        $saved = $editor->save( $path );
        if ( is_wp_error( $saved ) ) {
            return new \WP_Error( 'save_failed', 'Image editor failed to save: ' . $saved->get_error_message() );
        }

        clearstatcache( true, $path );
        $new_size = filesize( $path );

        $method_used = 'wp_editor';
        $attempts = array( 'wp_editor' => $new_size );

        // If the WP editor didn't reduce size, try a GD re-encode fallback for JPEG/PNG/WEBP
        if ( $new_size >= $orig_size ) {
            // try GD for common types
            try {
                if ( $mime === 'image/jpeg' && function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' ) ) {
                    $img = @imagecreatefromjpeg( $path );
                    if ( $img ) {
                        // overwrite with quality 85
                        @imagejpeg( $img, $path, $jpeg_quality );
                        imagedestroy( $img );
                        clearstatcache( true, $path );
                        $new_size2 = filesize( $path );
                        $attempts['gd_jpeg'] = $new_size2;
                        if ( $new_size2 < $new_size ) {
                            $method_used = 'gd_jpeg';
                            $new_size = $new_size2;
                        }
                    }
                } elseif ( $mime === 'image/png' && function_exists( 'imagecreatefrompng' ) && function_exists( 'imagepng' ) ) {
                    $img = @imagecreatefrompng( $path );
                    if ( $img ) {
                        // PNG compression level (0-9)
                        @imagepng( $img, $path, $png_compress );
                        imagedestroy( $img );
                        clearstatcache( true, $path );
                        $new_size2 = filesize( $path );
                        $attempts['gd_png'] = $new_size2;
                        if ( $new_size2 < $new_size ) {
                            $method_used = 'gd_png';
                            $new_size = $new_size2;
                        }
                    }
                } elseif ( $mime === 'image/webp' && function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagewebp' ) ) {
                    $img = @imagecreatefromwebp( $path );
                    if ( $img ) {
                        @imagewebp( $img, $path, $webp_quality );
                        imagedestroy( $img );
                        clearstatcache( true, $path );
                        $new_size2 = filesize( $path );
                        $attempts['gd_webp'] = $new_size2;
                        if ( $new_size2 < $new_size ) {
                            $method_used = 'gd_webp';
                            $new_size = $new_size2;
                        }
                    }
                }
            } catch ( \Throwable $t ) {
                // ignore and continue
                $attempts['gd_exception'] = $t->getMessage();
            }
        }

        // If we achieved a smaller file, update metadata
        if ( $new_size < $orig_size ) {
            wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
        }

        return array(
            'attachment_id' => $attachment_id,
            'orig_size'     => $orig_size,
            'new_size'      => $new_size,
            'saved'         => ( $new_size < $orig_size ),
            'human_orig'    => $this->human_filesize( $orig_size ),
            'human_new'     => $this->human_filesize( $new_size ),
            'method'        => $method_used,
            'attempts'      => $attempts,
        );
    }

    public function maybe_optimize_on_upload( $metadata, $attachment_id ) {
        // Read stored options. If the specific flag is not explicitly set to a truthy value,
        // do not run optimization. This makes the settings checkbox reliably disable upload optimization.
        $opts = get_option( $this->option_name );
        $enabled = false;
        if ( is_array( $opts ) && isset( $opts['optimize_on_upload'] ) ) {
            $enabled = ! empty( $opts['optimize_on_upload'] );
        }

        if ( ! $enabled ) {
            return $metadata;
        }

        $this->optimize_attachment( $attachment_id );
        return $metadata;
    }

    public function handle_bulk_optimize() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'imgoptix_bulk' );

        // Queue all image attachments
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );
        $query = new \WP_Query( $args );
        $ids = $query->posts;
        $count = 0;
        foreach ( $ids as $id ) {
            if ( $this->optimize_attachment( $id ) ) {
                $count++;
            }
        }

        wp_safe_redirect( add_query_arg( 'imgoptix_result', $count, wp_get_referer() ?: admin_url() ) );
        exit;
    }

    private function optimize_attachment( $attachment_id ) {
        $res = $this->perform_optimization( $attachment_id );
        if ( ! $res ) {
            return false;
        }

        return (bool) $res['saved'];
    }

    private function human_filesize( $bytes, $decimals = 2 ) {
        $size = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $factor = floor( ( strlen( $bytes ) - 1 ) / 3 );
        if ( $factor == 0 ) {
            return $bytes . ' ' . $size[0];
        }
        return sprintf( "%.{$decimals}f %s", $bytes / pow( 1024, $factor ), $size[$factor] );
    }
}

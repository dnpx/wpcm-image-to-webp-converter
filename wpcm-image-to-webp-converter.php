<?php
/*
Plugin Name: WPCM Image to WebP Converter
Plugin URI: https://github.com/your-repo/wpcm-image-to-webp-converter
Description: Converte imagens (incluindo AVIF) para WebP, renomeia, otimiza e reconverte em lote. Admin amigável, logs, CLI e segurança avançada.
Version: 4.3
Author: Daniel Oliveira da Paixao
Author URI: https://your-website.com
License: GPL v2 or later
Text Domain: wpcm-image-to-webp-converter
Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;

final class WPCM_ImageToWebP {
    private static $instance = null;
    private $options;
    private $default_options = [
        'max_dimension'    => 1200,
        'quality'          => 85,
        'enable_logging'   => true,
        'delete_originals' => true,
        'file_prefix'      => 'wpcm_',
    ];

    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        $this->load_options();
        $this->define_hooks();
    }
    private function load_options() {
        $this->options = get_option('wpcm_settings', $this->default_options);
        $this->options = wp_parse_args($this->options, $this->default_options);
    }
    private function define_hooks() {
        add_filter('wp_handle_upload', [$this, 'process_upload']);
        add_action('admin_init', [$this, 'register_plugin_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_notices', [$this, 'check_requirements_notice']);
        register_activation_hook(__FILE__, [$this, 'plugin_activation']);
        register_uninstall_hook(__FILE__, ['WPCM_ImageToWebP', 'plugin_uninstall']);
        add_filter('upload_mimes', [$this, 'add_avif_mime_type']);
        add_filter('wp_check_filetype_and_ext', [$this, 'check_filetype_and_ext'], 10, 4);
        add_action('wp_ajax_wpcm_bulk_reconvert_batch', [$this, 'ajax_bulk_reconvert_batch']);
        add_action('wp_ajax_wpcm_clear_log', [$this, 'ajax_clear_log']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('add_attachment', [$this, 'update_attachment_title_on_upload']);
        // O filtro wp_insert_attachment_data foi removido!
    }
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_wpcm-image-to-webp') return;
        $asset_base = plugin_dir_url(__FILE__) . 'assets/';
        wp_enqueue_script('wpcm-admin-js', $asset_base . 'js/admin.js', ['jquery'], '1.0', true);
        wp_enqueue_style('wpcm-admin-css', $asset_base . 'css/admin.css', [], '1.0');
        wp_localize_script('wpcm-admin-js', 'WPCM_AJAX', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce_batch' => wp_create_nonce('wpcm_bulk_reconvert_batch'),
            'nonce_log' => wp_create_nonce('wpcm_clear_log'),
            'strings' => [
                'start' => __('Iniciando reconversão...', 'wpcm-image-to-webp-converter'),
                'processing' => __('Processando imagens...', 'wpcm-image-to-webp-converter'),
                'done' => __('Reconversão concluída!', 'wpcm-image-to-webp-converter'),
                'fail' => __('Falha:', 'wpcm-image-to-webp-converter'),
                'cleanlog' => __('Limpar log?', 'wpcm-image-to-webp-converter'),
            ]
        ]);
    }
    public function add_admin_menu() {
        add_options_page(
            __('WPCM Converter Settings', 'wpcm-image-to-webp-converter'),
            __('WPCM Converter', 'wpcm-image-to-webp-converter'),
            'manage_options',
            'wpcm-image-to-webp',
            [$this, 'render_settings_page']
        );
    }
    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $log_contents = $this->get_log_contents();
        ?>
        <div class="wrap wpcm-admin-wrap">
            <h1><?php esc_html_e('WPCM Image to WebP Converter Settings', 'wpcm-image-to-webp-converter'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wpcm_settings_group');
                do_settings_sections('wpcm-image-to-webp');
                submit_button();
                ?>
            </form>
            <hr>
            <h2><?php esc_html_e('Ferramentas', 'wpcm-image-to-webp-converter'); ?></h2>
            <button id="wpcm-bulk-reconvert" class="button button-primary"><?php esc_html_e('Reconversão em lote (toda mídia)', 'wpcm-image-to-webp-converter'); ?></button>
            <span id="wpcm-bulk-status" style="margin-left:16px"></span>
            <br><br>
            <h2><?php esc_html_e('Log de conversões', 'wpcm-image-to-webp-converter'); ?></h2>
            <button id="wpcm-clear-log" class="button"><?php esc_html_e('Limpar Log', 'wpcm-image-to-webp-converter'); ?></button>
            <pre id="wpcm-log"><?php echo esc_html($log_contents ?: __('Nenhum log.', 'wpcm-image-to-webp-converter')); ?></pre>
        </div>
        <?php
    }
    public function get_log_contents() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/wpcm-logs/conversion.log';
        return file_exists($log_file) ? file_get_contents($log_file) : '';
    }
    public function ajax_clear_log() {
        check_ajax_referer('wpcm_clear_log');
        if (!current_user_can('manage_options')) wp_die();
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/wpcm-logs/conversion.log';
        @file_put_contents($log_file, '');
        wp_send_json_success();
    }
    public function ajax_bulk_reconvert_batch() {
        check_ajax_referer('wpcm_bulk_reconvert_batch');
        if (!current_user_can('manage_options')) wp_die();
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'fields' => 'ids',
        ];
        $attachments = get_posts($args);
        $processed = 0;
        foreach ($attachments as $aid) {
            $file = get_attached_file($aid);
            $type = wp_check_filetype($file);
            if (in_array($type['type'], ['image/jpeg','image/png','image/webp','image/avif'])) {
                try {
                    $res = $this->reconvert_image($file, $aid);
                    if ($res) $processed++;
                } catch (Exception $e) { }
            }
        }
        $has_more = (count($attachments) === $limit);
        wp_send_json_success([
            'processed' => $processed,
            'has_more' => $has_more,
            'offset' => $offset + $limit,
            'logs' => $this->get_log_contents(),
        ]);
    }
    private function reconvert_image($filepath, $attachment_id = null) {
        if (!file_exists($filepath)) return false;
        $type = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($type === 'webp') return false;
        $img = $this->create_image_resource($filepath, $type);
        if (!$img) return false;
        $img = $this->resize_if_needed($img);
        $newpath = $this->generate_unique_filepath($filepath, 'webp');
        if ($this->save_webp_image($img, $newpath)) {
            imagedestroy($img);
            @unlink($filepath);
            if ($attachment_id) {
                update_attached_file($attachment_id, $newpath);
                $this->update_attachment_title_to_filename($attachment_id, $newpath);
            }
            $this->log_message("Reconversão: $filepath -> $newpath");
            return true;
        }
        imagedestroy($img);
        return false;
    }
    public function register_plugin_settings() {
        register_setting('wpcm_settings_group', 'wpcm_settings', [$this, 'sanitize_settings']);
        add_settings_section('wpcm_main_section', __('Conversion Settings', 'wpcm-image-to-webp-converter'), null, 'wpcm-image-to-webp');
        add_settings_field('file_prefix', __('File Prefix', 'wpcm-image-to-webp-converter'), [$this, 'render_field_file_prefix'], 'wpcm-image-to-webp', 'wpcm_main_section');
        add_settings_field('max_dimension', __('Max Image Dimension (px)', 'wpcm-image-to-webp-converter'), [$this, 'render_field_max_dimension'], 'wpcm-image-to-webp', 'wpcm_main_section');
        add_settings_field('quality', __('WebP Quality', 'wpcm-image-to-webp-converter'), [$this, 'render_field_quality'], 'wpcm-image-to-webp', 'wpcm_main_section');
        add_settings_field('delete_originals', __('Delete Original Images', 'wpcm-image-to-webp-converter'), [$this, 'render_field_delete_originals'], 'wpcm-image-to-webp', 'wpcm_main_section');
        add_settings_field('enable_logging', __('Enable Logging', 'wpcm-image-to-webp-converter'), [$this, 'render_field_enable_logging'], 'wpcm-image-to-webp', 'wpcm_main_section');
    }
    public function sanitize_settings($input) {
        $defaults = $this->default_options;
        $sanitized = [];
        $sanitized['max_dimension'] = isset($input['max_dimension']) ? absint($input['max_dimension']) : $defaults['max_dimension'];
        $sanitized['quality'] = isset($input['quality']) ? min(100,max(1,absint($input['quality']))) : $defaults['quality'];
        $sanitized['delete_originals'] = !empty($input['delete_originals']) ? 1 : 0;
        $sanitized['enable_logging'] = !empty($input['enable_logging']) ? 1 : 0;
        $prefix = isset($input['file_prefix']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $input['file_prefix']) : '';
        $sanitized['file_prefix'] = $prefix ?: $defaults['file_prefix'];
        return $sanitized;
    }
    public function render_field_file_prefix() {
        printf('<input type="text" class="regular-text" id="file_prefix" name="wpcm_settings[file_prefix]" value="%s" />', esc_attr($this->options['file_prefix']));
        echo '<p class="description">' . esc_html__('Set a custom prefix for renamed files (e.g., "mysite_"). Only letters, numbers, underscore, and hyphen are allowed.', 'wpcm-image-to-webp-converter') . '</p>';
    }
    public function render_field_max_dimension() {
        printf('<input type="number" id="max_dimension" name="wpcm_settings[max_dimension]" value="%d" />', esc_attr($this->options['max_dimension']));
        echo '<p class="description">' . esc_html__('Images wider or taller than this will be resized. Set to 0 to disable.', 'wpcm-image-to-webp-converter') . '</p>';
    }
    public function render_field_quality() {
        printf('<input type="number" id="quality" name="wpcm_settings[quality]" min="1" max="100" value="%d" />', esc_attr($this->options['quality']));
        echo '<p class="description">' . esc_html__('Quality for WebP images (1-100). 85 is recommended.', 'wpcm-image-to-webp-converter') . '</p>';
    }
    public function render_field_delete_originals() {
        printf('<input type="checkbox" id="delete_originals" name="wpcm_settings[delete_originals]" value="1" %s />', checked(1, $this->options['delete_originals'], false));
        echo '<label for="delete_originals"> ' . esc_html__('Delete the original JPG/PNG/AVIF files after successful conversion.', 'wpcm-image-to-webp-converter') . '</label>';
    }
    public function render_field_enable_logging() {
        printf('<input type="checkbox" id="enable_logging" name="wpcm_settings[enable_logging]" value="1" %s />', checked(1, $this->options['enable_logging'], false));
        echo '<label for="enable_logging"> ' . esc_html__('Log conversion details to a file in /wp-content/uploads/wpcm-logs/.', 'wpcm-image-to-webp-converter') . '</label>';
    }
    public function plugin_activation() {
        if (!extension_loaded('gd') && !class_exists('Imagick')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('This plugin requires the GD or Imagick PHP extension. Please contact your hosting provider.', 'wpcm-image-to-webp-converter'));
        }
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wpcm-logs';
        if (!file_exists($log_dir)) wp_mkdir_p($log_dir);
        if (get_option('wpcm_file_counter') === false) add_option('wpcm_file_counter', 1);
    }
    public static function plugin_uninstall() {
        delete_option('wpcm_settings');
        delete_option('wpcm_file_counter');
        $upload_dir = wp_upload_dir();
        @unlink($upload_dir['basedir'] . '/wpcm-logs/conversion.log');
    }
    public function process_upload($file) {
        $valid_image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        $valid_video_types = ['video/mp4'];
        try {
            if (in_array($file['type'], $valid_video_types)) return $this->rename_video($file);
            if (!in_array($file['type'], $valid_image_types)) return $file;
            $this->log_message("Starting processing for: {$file['name']}");
            $this->adjust_memory_limit();
            $extension = strtolower(pathinfo($file['file'], PATHINFO_EXTENSION));
            if ($extension === 'avif' && function_exists('imagecreatefromavif')) return $this->process_image($file, 'avif');
            $result = $this->process_image($file);

            // <<< ESSENCIAL: GUARDA O NOVO NOME PARA O HOOK add_attachment >>>
            $GLOBALS['wpcm_last_renamed_file'] = isset($result['file']) ? $result['file'] : null;

            return $result;
        } catch (Exception $e) {
            $this->log_message("ERROR during processing: " . $e->getMessage());
            return $file;
        }
    }
    private function process_image($file, $source_format = null) {
        $image_path = $file['file'];
        $image_resource = $this->create_image_resource($image_path, $source_format);
        if (!$image_resource) {
            $this->log_message("Falha ao criar resource para $image_path.");
            return $file;
        }
        $image_resource = $this->resize_if_needed($image_resource);
        $webp_path = $this->generate_unique_filepath($image_path, 'webp');
        if ($this->save_webp_image($image_resource, $webp_path)) {
            imagedestroy($image_resource);
            if (!empty($this->options['delete_originals'])) @unlink($image_path);
            $file['file'] = $webp_path;
            $file['url'] = str_replace(basename($image_path), basename($webp_path), $file['url']);
            $file['type'] = 'image/webp';
            $file['name'] = basename($webp_path);
            $this->log_message("Convertido para WebP: " . basename($webp_path));
            return $file;
        }
        imagedestroy($image_resource);
        $this->log_message("Falha ao salvar WebP: $webp_path");
        return $file;
    }
    private function resize_if_needed($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $max_dim = (int) $this->options['max_dimension'];
        if ($max_dim > 0 && ($width > $max_dim || $height > $max_dim)) {
            $ratio = $width / $height;
            if ($width > $height) {
                $new_width = $max_dim;
                $new_height = $max_dim / $ratio;
            } else {
                $new_height = $max_dim;
                $new_width = $max_dim * $ratio;
            }
            $new_image = imagecreatetruecolor((int)$new_width, (int)$new_height);
            imagecopyresampled($new_image, $image, 0, 0, 0, 0, (int)$new_width, (int)$new_height, $width, $height);
            imagedestroy($image);
            $this->log_message(sprintf("Redimensionada para %dx%d", $new_width, $new_height));
            return $new_image;
        }
        return $image;
    }
    private function create_image_resource($image_path, $source_format = null) {
        $type = $source_format ?? strtolower(pathinfo($image_path, PATHINFO_EXTENSION));
        switch ($type) {
            case 'jpeg': case 'jpg': return @imagecreatefromjpeg($image_path);
            case 'png':
                $image = @imagecreatefrompng($image_path);
                if ($image) {
                    imagepalettetotruecolor($image);
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                }
                return $image;
            case 'webp': return @imagecreatefromwebp($image_path);
            case 'avif': if (function_exists('imagecreatefromavif')) return @imagecreatefromavif($image_path);
                return false;
            default: return false;
        }
    }
    private function save_webp_image($image, $path) {
        return imagewebp($image, $path, (int) $this->options['quality']);
    }
    private function generate_unique_filepath($original_file, $new_extension) {
        $dir = dirname($original_file);
        $filename = $this->generate_unique_name($new_extension);
        return $dir . '/' . $filename;
    }
    private function rename_video($file) {
        $path_info = pathinfo($file['file']);
        $new_name = $this->generate_unique_name('mp4');
        $new_path = $path_info['dirname'] . '/' . $new_name;
        if (@rename($file['file'], $new_path)) {
            $file['file'] = $new_path;
            $file['url'] = str_replace(basename($file['name']), $new_name, $file['url']);
            $file['name'] = $new_name;
            $this->log_message("Video renamed to: " . $new_name);
        }
        return $file;
    }
    private function generate_unique_name($extension) {
        $counter = get_option('wpcm_file_counter', 1);
        $formatted_counter = str_pad((string)$counter, 3, '0', STR_PAD_LEFT);
        update_option('wpcm_file_counter', $counter >= 999 ? 1 : $counter + 1);
        $prefix = !empty($this->options['file_prefix']) ? $this->options['file_prefix'] : 'file_';
        $base_filename = $prefix . $formatted_counter . 'img';
        $filename = $base_filename . '.' . $extension;
        $upload_dir = wp_upload_dir();
        if (file_exists($upload_dir['path'] . '/' . $filename)) {
            $random_suffix = strtolower(substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 3));
            $filename = $base_filename . '-' . $random_suffix . '.' . $extension;
            $this->log_message("Filename collision detected. New name with suffix: $filename");
        }
        return sanitize_file_name($filename);
    }
    private function log_message($message) {
        if (empty($this->options['enable_logging'])) return;
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/wpcm-logs/conversion.log';
        $timestamp = current_time('mysql');
        @file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }
    private function adjust_memory_limit() {
        if (wp_is_ini_value_changeable('memory_limit')) {
            $current_limit_bytes = wp_convert_hr_to_bytes(ini_get('memory_limit'));
            $required_bytes = wp_convert_hr_to_bytes('256M');
            if ($current_limit_bytes < $required_bytes) {
                ini_set('memory_limit', '256M');
                $this->log_message("Memory limit adjusted to 256M.");
            }
        }
    }
    public function check_requirements_notice() {
        if (!extension_loaded('gd') && !class_exists('Imagick')) {
            $class = 'notice notice-error';
            $message = __('WPCM Image to WebP Converter requires the GD or Imagick PHP extension to function. Please contact your host.', 'wpcm-image-to-webp-converter');
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        }
    }
    public function add_avif_mime_type($mimes) {
        $mimes['avif'] = 'image/avif';
        return $mimes;
    }
    public function check_filetype_and_ext($data, $file, $filename, $mimes) {
        if (empty($data['type']) && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'avif') {
            $data['type'] = 'image/avif';
            $data['ext'] = 'avif';
        }
        return $data;
    }

    // Corrige título no upload usando o novo nome renomeado (função robusta)
    public function update_attachment_title_on_upload($post_ID) {
        $mime = get_post_mime_type($post_ID);
        if (strpos($mime, 'image/') !== 0) return;

        $file = isset($GLOBALS['wpcm_last_renamed_file']) ? $GLOBALS['wpcm_last_renamed_file'] : get_attached_file($post_ID);
        if (!$file) return;

        $filename = pathinfo($file, PATHINFO_FILENAME);
        $current = get_post_field('post_title', $post_ID);
        if ($current !== $filename) {
            wp_update_post([
                'ID' => $post_ID,
                'post_title' => $filename
            ]);
        }
    }

    private function update_attachment_title_to_filename($post_ID, $file_path) {
        $filename = pathinfo($file_path, PATHINFO_FILENAME);
        wp_update_post([
            'ID' => $post_ID,
            'post_title' => $filename
        ]);
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wpcm-webp', function($args, $assoc_args) {
        $instance = WPCM_ImageToWebP::get_instance();
        WP_CLI::log("Bulk reconversion starting...");
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];
        $attachments = get_posts($args);
        $success = 0; $fail = 0;
        foreach ($attachments as $aid) {
            $file = get_attached_file($aid);
            $type = wp_check_filetype($file);
            if (in_array($type['type'], ['image/jpeg','image/png','image/webp','image/avif'])) {
                try {
                    $res = $instance->reconvert_image($file, $aid);
                    if ($res) $success++; else $fail++;
                } catch (Exception $e) { $fail++; }
            }
        }
        WP_CLI::success("Concluído: $success convertidos, $fail falharam.");
    });
}

function wpcm_imgtowebp_init() { return WPCM_ImageToWebP::get_instance(); }
add_action('plugins_loaded', 'wpcm_imgtowebp_init');

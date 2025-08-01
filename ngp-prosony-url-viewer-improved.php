<?php
/**
 * Plugin Name: NGP ProSony URL Viewer
 * Description: Displays all ProSony URLs with a filter for category name in URL, pagination, CSV export, and search.
 * Version: 1.2
 * Author: NGP Team
 */

if (!defined('ABSPATH')) exit;

class NGP_ProSony_URL_Viewer {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'handle_csv_export'));
    }
    
    public function register_menu() {
        add_menu_page(
            'ProSony URL Viewer',
            'ProSony URL Viewer',
            'manage_options',
            'ngp-prosony-url-viewer',
            array($this, 'render_page'),
            'dashicons-admin-links',
            90
        );
    }
    
    public function handle_csv_export() {
        if (isset($_GET['page']) && $_GET['page'] === 'ngp-prosony-url-viewer' && 
            isset($_GET['download_csv']) && $_GET['download_csv'] == 1 &&
            current_user_can('manage_options')) {
            
            $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
            $selected_category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
            
            $filtered_posts = $this->get_filtered_posts($search, $selected_category);
            
            // Set headers for CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="prosony-urls-' . date('Y-m-d') . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            $output = fopen('php://output', 'w');
            
            // Add BOM for proper UTF-8 encoding in Excel
            fwrite($output, "\xEF\xBB\xBF");
            
            fputcsv($output, ['Post ID', 'Title', 'Logical Name', 'ProSony URL']);
            foreach ($filtered_posts as $item) {
                fputcsv($output, [$item['id'], $item['title'], $item['logical'], $item['url']]);
            }
            fclose($output);
            exit;
        }
    }
    
    private function get_filtered_posts($search = '', $selected_category = '', $limit = -1) {
        // Check if required class exists
        if (!class_exists('NGP\AcfPermanantLinkCall')) {
            $plugin_path = WP_PLUGIN_DIR . '/ngp-acf-permalink/library/class-ngp-permalink-call.php';
            if (!file_exists($plugin_path)) {
                return [];
            }
            require_once $plugin_path;
        }
        
        if (!class_exists('NGP\AcfPermanantLinkCall')) {
            return [];
        }
        
        $caller = NGP\AcfPermanantLinkCall::getInstance();
        
        // Get all published posts with better performance
        $args = array(
            'post_type'      => array('page', 'pdf', 'partnerassets'),
            'post_status'    => 'publish',
            'posts_per_page' => ($limit > 0) ? $limit : -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        
        $all_post_ids = get_posts($args);
        $filtered_posts = [];
        
        foreach ($all_post_ids as $post_id) {
            $title = get_the_title($post_id);
            $logical = get_field('logicalName', $post_id);
            
            try {
                $url = $caller->resolveLocaleAndGetUrl($post_id, null);
            } catch (Exception $e) {
                error_log('NGP ProSony URL Viewer: Error getting URL for post ' . $post_id . ': ' . $e->getMessage());
                continue;
            }
            
            if (!$url) continue;
            
            // Apply category filter by checking if category name is part of URL
            if (!empty($selected_category) && stripos($url, $selected_category) === false) {
                continue;
            }
            
            // Apply search filter
            if (!empty($search)) {
                $search_text = strtolower($title . ' ' . $logical);
                if (stripos($search_text, strtolower($search)) === false) {
                    continue;
                }
            }
            
            $filtered_posts[] = [
                'id'      => $post_id,
                'title'   => $title,
                'logical' => $logical,
                'url'     => $url
            ];
        }
        
        return $filtered_posts;
    }
    
    public function render_page() {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $selected_category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
        
        $filtered_posts = $this->get_filtered_posts($search, $selected_category);
        
        $total_count = count($filtered_posts);
        $total_pages = ceil($total_count / $per_page);
        $paged_items = array_slice($filtered_posts, ($paged - 1) * $per_page, $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php $this->render_search_form($search, $selected_category); ?>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <span class="displaying-num"><?php printf(_n('%s item', '%s items', $total_count), number_format_i18n($total_count)); ?></span>
                </div>
                <?php if ($total_pages > 1): ?>
                    <?php $this->render_pagination($paged, $total_pages, $search, $selected_category); ?>
                <?php endif; ?>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-cb check-column">#</th>
                        <th scope="col" class="manage-column">Post ID</th>
                        <th scope="col" class="manage-column">Title</th>
                        <th scope="col" class="manage-column">Logical Name</th>
                        <th scope="col" class="manage-column">ProSony URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paged_items)): ?>
                        <tr>
                            <td colspan="5"><?php _e('No URLs found.'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php
                        $index = ($paged - 1) * $per_page + 1;
                        foreach ($paged_items as $item):
                        ?>
                        <tr>
                            <td><?php echo esc_html($index++); ?></td>
                            <td><?php echo esc_html($item['id']); ?></td>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url(get_edit_post_link($item['id'])); ?>" target="_blank">
                                        <?php echo esc_html($item['title']); ?>
                                    </a>
                                </strong>
                            </td>
                            <td><?php echo esc_html($item['logical']); ?></td>
                            <td>
                                <a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html($item['url']); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <?php $this->render_pagination($paged, $total_pages, $search, $selected_category); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_search_form($search, $selected_category) {
        $categories = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false
        ));
        ?>
        <form method="get" class="search-form" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="ngp-prosony-url-viewer" />
            
            <p class="search-box">
                <label class="screen-reader-text" for="post-search-input">Search URLs:</label>
                <input type="search" id="post-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search title or logical name..." />
                
                <select name="category" id="category-filter">
                    <option value=""><?php _e('All Categories'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo esc_attr($cat->slug); ?>" <?php selected($selected_category, $cat->slug); ?>>
                            <?php echo esc_html($cat->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <input type="submit" name="search" id="search-submit" class="button" value="<?php _e('Search URLs'); ?>" />
                
                <a href="<?php echo esc_url(add_query_arg(array(
                    'page' => 'ngp-prosony-url-viewer',
                    'download_csv' => '1',
                    's' => urlencode($search),
                    'category' => urlencode($selected_category)
                ), admin_url('admin.php'))); ?>" class="button button-secondary">
                    <?php _e('Export CSV'); ?>
                </a>
                
                <?php if (!empty($search) || !empty($selected_category)): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ngp-prosony-url-viewer')); ?>" class="button">
                        <?php _e('Clear Filters'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }
    
    private function render_pagination($paged, $total_pages, $search, $selected_category) {
        $base_url = admin_url('admin.php?page=ngp-prosony-url-viewer');
        if (!empty($search)) {
            $base_url = add_query_arg('s', urlencode($search), $base_url);
        }
        if (!empty($selected_category)) {
            $base_url = add_query_arg('category', urlencode($selected_category), $base_url);
        }
        
        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . sprintf(_n('%s page', '%s pages', $total_pages), number_format_i18n($total_pages)) . '</span>';
        
        $page_links = paginate_links(array(
            'base' => add_query_arg('paged', '%#%', $base_url),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $total_pages,
            'current' => $paged,
            'type' => 'array'
        ));
        
        if ($page_links) {
            echo '<span class="pagination-links">' . join('', $page_links) . '</span>';
        }
        echo '</div>';
    }
}

// Initialize the plugin
NGP_ProSony_URL_Viewer::get_instance();
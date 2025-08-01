<?php
/**
 * Plugin Name: NGP ProSony URL Viewer
 * Description: Displays all ProSony URLs with a filter for category name in URL, pagination, CSV export, and search.
 * Version: 1.3
 * Author: NGP Team
 * Requires at least: 4.8
 * Tested up to: 6.0
 * Requires PHP: 7.2
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
            
            // Increase time limit for CSV export
            set_time_limit(300); // 5 minutes
            
            $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
            $selected_category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
            
            // Set headers for CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="prosony-urls-' . date('Y-m-d') . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            $output = fopen('php://output', 'w');
            
            // Add BOM for proper UTF-8 encoding in Excel
            fwrite($output, "\xEF\xBB\xBF");
            
            fputcsv($output, array('Post ID', 'Title', 'Logical Name', 'ProSony URL'));
            
            // Process in batches to avoid memory issues
            $this->export_csv_in_batches($output, $search, $selected_category);
            
            fclose($output);
            exit;
        }
    }
    
    private function export_csv_in_batches($output, $search, $selected_category, $batch_size = 50) {
        $offset = 0;
        
        do {
            $batch_posts = $this->get_posts_batch($search, $selected_category, $batch_size, $offset);
            
            foreach ($batch_posts as $item) {
                fputcsv($output, array($item['id'], $item['title'], $item['logical'], $item['url']));
            }
            
            $offset += $batch_size;
            
            // Flush output buffer to prevent timeouts
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
        } while (count($batch_posts) === $batch_size);
    }
    
    private function get_posts_batch($search = '', $selected_category = '', $limit = 20, $offset = 0) {
        // Check if required class exists
        if (!class_exists('NGP\AcfPermanantLinkCall')) {
            $plugin_path = WP_PLUGIN_DIR . '/ngp-acf-permalink/library/class-ngp-permalink-call.php';
            if (!file_exists($plugin_path)) {
                return array();
            }
            require_once $plugin_path;
        }
        
        if (!class_exists('NGP\AcfPermanantLinkCall')) {
            return array();
        }
        
        $caller = NGP\AcfPermanantLinkCall::getInstance();
        
        // Build query args with database-level filtering
        $args = array(
            'post_type'      => array('page', 'pdf', 'partnerassets'),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        
        // Add search to query if provided
        if (!empty($search)) {
            $args['s'] = $search;
        }
        
        // Add category filter if provided
        if (!empty($selected_category)) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'category',
                    'field'    => 'slug',
                    'terms'    => $selected_category,
                )
            );
        }
        
        $post_ids = get_posts($args);
        $filtered_posts = array();
        
        foreach ($post_ids as $post_id) {
            $title = get_the_title($post_id);
            $logical = get_field('logicalName', $post_id);
            
            try {
                $url = $caller->resolveLocaleAndGetUrl($post_id, null);
            } catch (Exception $e) {
                error_log('NGP ProSony URL Viewer: Error getting URL for post ' . $post_id . ': ' . $e->getMessage());
                continue;
            }
            
            if (!$url) continue;
            
            // Additional category filter by URL content (if category filter is by URL content, not taxonomy)
            if (!empty($selected_category) && stripos($url, $selected_category) === false) {
                continue;
            }
            
            $filtered_posts[] = array(
                'id'      => $post_id,
                'title'   => $title,
                'logical' => $logical,
                'url'     => $url
            );
        }
        
        return $filtered_posts;
    }
    
    private function get_total_count($search = '', $selected_category = '') {
        // Get count more efficiently
        $args = array(
            'post_type'      => array('page', 'pdf', 'partnerassets'),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        
        // Add search to query if provided
        if (!empty($search)) {
            $args['s'] = $search;
        }
        
        // Add category filter if provided
        if (!empty($selected_category)) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'category',
                    'field'    => 'slug',
                    'terms'    => $selected_category,
                )
            );
        }
        
        $query = new WP_Query($args);
        return $query->found_posts;
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
        
        // Get current page items
        $offset = ($paged - 1) * $per_page;
        $current_items = $this->get_posts_batch($search, $selected_category, $per_page, $offset);
        
        // Get total count for pagination (use cached value if possible)
        $cache_key = 'ngp_prosony_count_' . md5($search . $selected_category);
        $total_count = get_transient($cache_key);
        
        if (false === $total_count) {
            $total_count = $this->get_total_count($search, $selected_category);
            // Cache for 5 minutes
            set_transient($cache_key, $total_count, 300);
        }
        
        $total_pages = ceil($total_count / $per_page);
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        
        $this->render_search_form($search, $selected_category);
        
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        if ($total_count == 1) {
            echo '<span class="displaying-num">1 item</span>';
        } else {
            echo '<span class="displaying-num">' . number_format_i18n($total_count) . ' items</span>';
        }
        echo '</div>';
        if ($total_pages > 1) {
            $this->render_pagination($paged, $total_pages, $search, $selected_category);
        }
        echo '</div>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th scope="col" class="manage-column column-cb check-column">#</th>';
        echo '<th scope="col" class="manage-column">Post ID</th>';
        echo '<th scope="col" class="manage-column">Title</th>';
        echo '<th scope="col" class="manage-column">Logical Name</th>';
        echo '<th scope="col" class="manage-column">ProSony URL</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        if (empty($current_items)) {
            echo '<tr><td colspan="5">No URLs found.</td></tr>';
        } else {
            $index = ($paged - 1) * $per_page + 1;
            foreach ($current_items as $item) {
                echo '<tr>';
                echo '<td>' . esc_html($index++) . '</td>';
                echo '<td>' . esc_html($item['id']) . '</td>';
                echo '<td>';
                echo '<strong>';
                echo '<a href="' . esc_url(get_edit_post_link($item['id'])) . '" target="_blank">';
                echo esc_html($item['title']);
                echo '</a>';
                echo '</strong>';
                echo '</td>';
                echo '<td>' . esc_html($item['logical']) . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url($item['url']) . '" target="_blank" rel="noopener">';
                echo esc_html($item['url']);
                echo '</a>';
                echo '</td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody>';
        echo '</table>';
        
        if ($total_pages > 1) {
            echo '<div class="tablenav bottom">';
            $this->render_pagination($paged, $total_pages, $search, $selected_category);
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    private function render_search_form($search, $selected_category) {
        $categories = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false
        ));
        
        // Handle potential WP_Error from get_terms
        if (is_wp_error($categories)) {
            $categories = array();
        }
        
        echo '<form method="get" class="search-form" style="margin-bottom: 20px;">';
        echo '<input type="hidden" name="page" value="ngp-prosony-url-viewer" />';
        
        echo '<p class="search-box">';
        echo '<label class="screen-reader-text" for="post-search-input">Search URLs:</label>';
        echo '<input type="search" id="post-search-input" name="s" value="' . esc_attr($search) . '" placeholder="Search title or logical name..." />';
        
        echo '<select name="category" id="category-filter">';
        echo '<option value="">All Categories</option>';
        foreach ($categories as $cat) {
            $selected = ($selected_category === $cat->slug) ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr($cat->slug) . '" ' . $selected . '>';
            echo esc_html($cat->name);
            echo '</option>';
        }
        echo '</select>';
        
        echo ' <input type="submit" name="search" id="search-submit" class="button" value="Search URLs" />';
        
        $export_url = add_query_arg(array(
            'page' => 'ngp-prosony-url-viewer',
            'download_csv' => '1',
            's' => urlencode($search),
            'category' => urlencode($selected_category)
        ), admin_url('admin.php'));
        
        echo ' <a href="' . esc_url($export_url) . '" class="button button-secondary">Export CSV</a>';
        
        if (!empty($search) || !empty($selected_category)) {
            echo ' <a href="' . esc_url(admin_url('admin.php?page=ngp-prosony-url-viewer')) . '" class="button">Clear Filters</a>';
        }
        
        echo '</p>';
        echo '</form>';
        
        // Add loading indicator
        echo '<div id="loading-indicator" style="display: none; margin: 10px 0;">';
        echo '<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>';
        echo 'Loading data...';
        echo '</div>';
        
        // Add JavaScript for loading indicator
        echo '<script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            var form = document.querySelector(".search-form");
            var loadingIndicator = document.getElementById("loading-indicator");
            
            if (form && loadingIndicator) {
                form.addEventListener("submit", function() {
                    loadingIndicator.style.display = "block";
                });
            }
            
            // Show loading for pagination links
            var paginationLinks = document.querySelectorAll(".pagination-links a");
            paginationLinks.forEach(function(link) {
                link.addEventListener("click", function() {
                    if (loadingIndicator) {
                        loadingIndicator.style.display = "block";
                    }
                });
            });
        });
        </script>';
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
        
        // Show range info
        $start = ($paged - 1) * 20 + 1;
        $end = min($paged * 20, $this->get_total_count($search, $selected_category));
        echo '<span class="displaying-num">Showing ' . $start . '-' . $end . '</span>';
        
        // Use manual pagination for WordPress 4.8 compatibility
        echo '<span class="pagination-links">';
        
        // First and Previous links
        if ($paged > 1) {
            $first_url = add_query_arg('paged', 1, $base_url);
            $prev_url = add_query_arg('paged', max(1, $paged - 1), $base_url);
            echo '<a class="first-page button" href="' . esc_url($first_url) . '">&laquo;</a>';
            echo '<a class="prev-page button" href="' . esc_url($prev_url) . '">&lsaquo;</a>';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled">&laquo;</span>';
            echo '<span class="tablenav-pages-navspan button disabled">&lsaquo;</span>';
        }
        
        // Current page info
        echo '<span class="paging-input">';
        echo '<span class="tablenav-paging-text">';
        echo 'Page ' . esc_html($paged) . ' of <span class="total-pages">' . esc_html($total_pages) . '</span>';
        echo '</span>';
        echo '</span>';
        
        // Next and Last links
        if ($paged < $total_pages) {
            $next_url = add_query_arg('paged', min($total_pages, $paged + 1), $base_url);
            $last_url = add_query_arg('paged', $total_pages, $base_url);
            echo '<a class="next-page button" href="' . esc_url($next_url) . '">&rsaquo;</a>';
            echo '<a class="last-page button" href="' . esc_url($last_url) . '">&raquo;</a>';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled">&rsaquo;</span>';
            echo '<span class="tablenav-pages-navspan button disabled">&raquo;</span>';
        }
        
        echo '</span>';
        echo '</div>';
    }
}

// Initialize the plugin
NGP_ProSony_URL_Viewer::get_instance();
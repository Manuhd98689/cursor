<?php
/**
 * Plugin Name: NGP ProSony URL Viewer (Optimized)
 * Description: Displays all ProSony URLs with a filter for category name in URL, pagination, CSV export, and search - Optimized for large datasets.
 * Version: 2.0
 * Author: NGP Team
 */

if (!defined('ABSPATH')) exit;

class NGP_ProSony_URL_Viewer_Optimized {
    
    private static $instance = null;
    private $cache_group = 'ngp_prosony_urls';
    private $cache_expiry = 3600; // 1 hour
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'handle_csv_export'));
        add_action('init', array($this, 'setup_cache_group'));
        
        // Clear cache when posts are updated
        add_action('save_post', array($this, 'clear_post_cache'));
        add_action('delete_post', array($this, 'clear_post_cache'));
    }
    
    public function setup_cache_group() {
        wp_cache_add_global_groups(array($this->cache_group));
    }
    
    public function clear_post_cache($post_id = null) {
        wp_cache_flush_group($this->cache_group);
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
            
            // Increase memory limit and execution time for CSV export
            ini_set('memory_limit', '512M');
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
            
            // Process in batches for CSV export
            $this->export_csv_in_batches($output, $search, $selected_category);
            
            fclose($output);
            exit;
        }
    }
    
    private function export_csv_in_batches($output, $search = '', $selected_category = '', $batch_size = 500) {
        $offset = 0;
        
        do {
            $batch_results = $this->get_filtered_posts_optimized($search, $selected_category, $batch_size, $offset);
            
            foreach ($batch_results['posts'] as $item) {
                fputcsv($output, array($item['id'], $item['title'], $item['logical'], $item['url']));
            }
            
            $offset += $batch_size;
            
            // Flush output buffer to prevent memory issues
            if (ob_get_level()) {
                ob_flush();
                flush();
            }
            
        } while (count($batch_results['posts']) === $batch_size);
    }
    
    /**
     * Optimized method to get filtered posts with database-level filtering and pagination
     */
    private function get_filtered_posts_optimized($search = '', $selected_category = '', $limit = 20, $offset = 0) {
        global $wpdb;
        
        // Create cache key
        $cache_key = md5(serialize(array($search, $selected_category, $limit, $offset)));
        $cached_result = wp_cache_get($cache_key, $this->cache_group);
        
        if ($cached_result !== false) {
            return $cached_result;
        }
        
        // Check if required class exists
        if (!class_exists('NGP\AcfPermanantLinkCall')) {
            $plugin_path = WP_PLUGIN_DIR . '/ngp-acf-permalink/library/class-ngp-permalink-call.php';
            if (!file_exists($plugin_path)) {
                return array('posts' => array(), 'total_count' => 0);
            }
            require_once $plugin_path;
        }
        
        if (!class_exists('NGP\AcfPermanantLinkCall')) {
            return array('posts' => array(), 'total_count' => 0);
        }
        
        $caller = NGP\AcfPermanantLinkCall::getInstance();
        
        // Build WHERE clause for database query
        $where_conditions = array();
        $where_values = array();
        
        // Base conditions
        $where_conditions[] = "p.post_status = %s";
        $where_values[] = 'publish';
        
        $where_conditions[] = "p.post_type IN (%s, %s, %s)";
        $where_values[] = 'page';
        $where_values[] = 'pdf';
        $where_values[] = 'partnerassets';
        
        // Search condition
        if (!empty($search)) {
            $where_conditions[] = "(p.post_title LIKE %s OR pm_logical.meta_value LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        // Build JOIN clause
        $joins = array();
        if (!empty($search)) {
            $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_logical ON p.ID = pm_logical.post_id AND pm_logical.meta_key = 'logicalName'";
        }
        
        $join_clause = implode(' ', $joins);
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Get total count for pagination
        $count_query = "
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p 
            {$join_clause}
            {$where_clause}
        ";
        
        $total_count = (int) $wpdb->get_var($wpdb->prepare($count_query, $where_values));
        
        // Get posts with pagination
        $posts_query = "
            SELECT DISTINCT p.ID, p.post_title 
            FROM {$wpdb->posts} p 
            {$join_clause}
            {$where_clause}
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d
        ";
        
        $query_values = array_merge($where_values, array($limit, $offset));
        $post_results = $wpdb->get_results($wpdb->prepare($posts_query, $query_values));
        
        $filtered_posts = array();
        
        foreach ($post_results as $post_data) {
            $post_id = $post_data->ID;
            $title = $post_data->post_title;
            
            // Get logical name from cache or database
            $logical_cache_key = 'logical_' . $post_id;
            $logical = wp_cache_get($logical_cache_key, $this->cache_group);
            if ($logical === false) {
                $logical = get_field('logicalName', $post_id);
                wp_cache_set($logical_cache_key, $logical, $this->cache_group, $this->cache_expiry);
            }
            
            // Get URL from cache or generate
            $url_cache_key = 'url_' . $post_id;
            $url = wp_cache_get($url_cache_key, $this->cache_group);
            if ($url === false) {
                try {
                    $url = $caller->resolveLocaleAndGetUrl($post_id, null);
                    wp_cache_set($url_cache_key, $url, $this->cache_group, $this->cache_expiry);
                } catch (Exception $e) {
                    error_log('NGP ProSony URL Viewer: Error getting URL for post ' . $post_id . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            if (!$url) continue;
            
            // Apply category filter by checking if category name is part of URL
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
        
        $result = array(
            'posts' => $filtered_posts,
            'total_count' => $total_count
        );
        
        // Cache the result
        wp_cache_set($cache_key, $result, $this->cache_group, $this->cache_expiry);
        
        return $result;
    }
    
    public function render_page() {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;
        
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $selected_category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
        
        // Use optimized method
        $result = $this->get_filtered_posts_optimized($search, $selected_category, $per_page, $offset);
        $paged_items = $result['posts'];
        $total_count = $result['total_count'];
        $total_pages = ceil($total_count / $per_page);
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        
        // Add performance info for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo '<div class="notice notice-info"><p>Performance: Showing ' . count($paged_items) . ' of ' . $total_count . ' total posts (Page ' . $paged . ' of ' . $total_pages . ')</p></div>';
        }
        
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
        
        if (empty($paged_items)) {
            echo '<tr><td colspan="5">No URLs found.</td></tr>';
        } else {
            $index = ($paged - 1) * $per_page + 1;
            foreach ($paged_items as $item) {
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
        
        // Add cache management for admins
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo '<div style="margin-top: 20px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';
            echo '<h3>Cache Management (Debug Mode)</h3>';
            echo '<a href="' . esc_url(add_query_arg('clear_cache', '1')) . '" class="button">Clear Cache</a>';
            echo '<p><small>Cache is automatically cleared when posts are updated.</small></p>';
            echo '</div>';
        }
        
        // Handle cache clearing
        if (isset($_GET['clear_cache']) && $_GET['clear_cache'] == '1') {
            $this->clear_post_cache();
            echo '<div class="notice notice-success is-dismissible"><p>Cache cleared successfully!</p></div>';
        }
        
        echo '</div>';
    }
    
    private function render_search_form($search, $selected_category) {
        // Get categories with caching
        $categories_cache_key = 'categories_list';
        $categories = wp_cache_get($categories_cache_key, $this->cache_group);
        if ($categories === false) {
            $categories = get_terms(array(
                'taxonomy' => 'category',
                'hide_empty' => false
            ));
            
            // Handle potential WP_Error from get_terms
            if (is_wp_error($categories)) {
                $categories = array();
            }
            
            wp_cache_set($categories_cache_key, $categories, $this->cache_group, $this->cache_expiry);
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
        
        // Use manual pagination 
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
NGP_ProSony_URL_Viewer_Optimized::get_instance();
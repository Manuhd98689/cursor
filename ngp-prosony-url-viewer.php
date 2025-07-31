<?php
/**
 * Plugin Name: NGP ProSony URL Viewer
 * Description: Displays all ProSony URLs with a filter for category name in URL, pagination, CSV export, and search.
 * Version: 1.1
 * Author: NGP Team
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'ngp_register_prosony_menu');
function ngp_register_prosony_menu() {
    add_menu_page(
        'ProSony URL Viewer',
        'ProSony URL Viewer',
        'manage_options',
        'ngp-prosony-url-viewer',
        'ngp_prosony_url_page',
        '',
        90
    );
}

function ngp_prosony_url_page() {
    global $wpdb;

    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;

    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $selected_category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';

    if (!class_exists('NGP\AcfPermanantLinkCall')) {
        require_once WP_PLUGIN_DIR . '/ngp-acf-permalink/library/class-ngp-permalink-call.php';
    }
    $caller = NGP\AcfPermanantLinkCall::getInstance();

    // Get all published posts of desired types
    $args = array(
        'post_type'      => array('page', 'pdf', 'partnerassets'),
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'fields'         => 'ids',
    );
    $all_post_ids = get_posts($args);

    $filtered_posts = [];

    foreach ($all_post_ids as $post_id) {
        $title = get_the_title($post_id);
        $logical = get_field('logicalName', $post_id);
        $url = $caller->resolveLocaleAndGetUrl($post_id, null);

        if (!$url) continue;

        // Apply category filter by checking if category name is part of URL
        if (!empty($selected_category) && stripos($url, $selected_category) === false) {
            continue;
        }

        // Apply search filter
        if (!empty($search) && stripos($title . ' ' . $logical, $search) === false) {
            continue;
        }

        $filtered_posts[] = [
            'id'      => $post_id,
            'title'   => $title,
            'logical' => $logical,
            'url'     => $url
        ];
    }

    $total_count = count($filtered_posts);
    $total_pages = ceil($total_count / $per_page);
    $paged_items = array_slice($filtered_posts, ($paged - 1) * $per_page, $per_page);

    echo '<div class="wrap"><h1>All ProSony URLs</h1>';

    // Search and Filter Form
    $categories = get_terms(array(
        'taxonomy' => 'category',
        'hide_empty' => false
    ));
    echo '<form method="get" style="margin-bottom: 15px;">';
    echo '<input type="hidden" name="page" value="ngp-prosony-url-viewer" />';
    echo '<input type="text" name="s" value="' . esc_attr($search) . '" placeholder="Search title or logical name" />';
    echo '<select name="category"><option value="">All Categories</option>';
    foreach ($categories as $cat) {
        $selected = ($selected_category === $cat->slug) ? 'selected' : '';
        echo '<option value="' . esc_attr($cat->slug) . '" ' . $selected . '>' . esc_html($cat->name) . '</option>';
    }
    echo '</select>';
    echo ' <input type="submit" class="button" value="Filter" />';
    echo ' <a href="' . esc_url(admin_url('admin.php?page=ngp-prosony-url-viewer&download_csv=1&s=' . urlencode($search) . '&category=' . urlencode($selected_category))) . '" class="button">Export CSV</a>';
    echo '</form>';

    echo '<p><strong>Total URLs:</strong> ' . esc_html($total_count) . '</p>';
    echo '<p><strong>Page:</strong> ' . esc_html($paged) . ' of ' . esc_html($total_pages) . '</p>';

    echo '<table class="widefat striped"><thead>
            <tr><th>#</th><th>Post ID</th><th>Title</th><th>Logical Name</th><th>ProSony URL</th></tr>
            </thead><tbody>';
    $index = ($paged - 1) * $per_page + 1;
    foreach ($paged_items as $item) {
        echo '<tr>';
        echo '<td>' . $index++ . '</td>';
        echo '<td>' . esc_html($item['id']) . '</td>';
        echo '<td>' . esc_html($item['title']) . '</td>';
        echo '<td>' . esc_html($item['logical']) . '</td>';
        echo '<td><a href="' . esc_url($item['url']) . '" target="_blank">' . esc_html($item['url']) . '</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Pagination
    if ($total_pages > 1) {
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        $base_url = admin_url('admin.php?page=ngp-prosony-url-viewer');
        if (!empty($search)) $base_url = add_query_arg('s', urlencode($search), $base_url);
        if (!empty($selected_category)) $base_url = add_query_arg('category', urlencode($selected_category), $base_url);

        $first_url = add_query_arg('paged', 1, $base_url);
        $prev_url = add_query_arg('paged', max(1, $paged - 1), $base_url);
        $next_url = add_query_arg('paged', min($total_pages, $paged + 1), $base_url);
        $last_url = add_query_arg('paged', $total_pages, $base_url);

        echo '<span class="pagination-links">';
        echo ($paged > 1)
            ? '<a class="first-page button" href="' . esc_url($first_url) . '">&laquo;</a><a class="prev-page button" href="' . esc_url($prev_url) . '">&lsaquo;</a>'
            : '<span class="first-page button disabled">&laquo;</span><span class="prev-page button disabled">&lsaquo;</span>';

        echo '<span class="paging-input"> Page ' . esc_html($paged) . ' of <span class="total-pages">' . esc_html($total_pages) . '</span> </span>';

        echo ($paged < $total_pages)
            ? '<a class="next-page button" href="' . esc_url($next_url) . '">&rsaquo;</a><a class="last-page button" href="' . esc_url($last_url) . '">&raquo;</a>'
            : '<span class="next-page button disabled">&rsaquo;</span><span class="last-page button disabled">&raquo;</span>';

        echo '</span></div></div>';
    }

    echo '</div>';

    // CSV Export
    if (isset($_GET['download_csv']) && $_GET['download_csv'] == 1) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="prosony-urls.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Post ID', 'Title', 'Logical Name', 'ProSony URL']);
        foreach ($filtered_posts as $item) {
            fputcsv($output, [$item['id'], $item['title'], $item['logical'], $item['url']]);
        }
        fclose($output);
        exit;
    }
}
<?php
/*
Plugin Name: Ali Activity Logs
Plugin URI: https://example.com/user-activity-logger
Description: Ghi lại các hoạt động của người dùng như đăng nhập, đăng xuất, và chỉnh sửa bài viết với thông tin chi tiết.
Version: 2.2
Author: Ali
Author URI: https://gemini.google.com
License: GPL2
*/

// Ngăn chặn truy cập trực tiếp vào tệp
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hàm kích hoạt plugin.
 * Đăng ký loại bài viết tùy chỉnh và xóa các quy tắc viết lại.
 */
function ual_activate_plugin() {
    ual_register_activity_log_post_type();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'ual_activate_plugin');

/**
 * Đăng ký loại bài viết tùy chỉnh 'activity_log'.
 */
function ual_register_activity_log_post_type() {
    $labels = array(
        'name'                  => 'Nhật ký Hoạt động',
        'singular_name'         => 'Nhật ký Hoạt động',
        'menu_name'             => 'Nhật ký Hoạt động',
        'name_admin_bar'        => 'Nhật ký Hoạt động',
        'all_items'             => 'Tất cả Nhật ký',
        'search_items'          => 'Tìm kiếm Nhật ký',
        'not_found'             => 'Không tìm thấy nhật ký.',
        'not_found_in_trash'    => 'Không tìm thấy nhật ký trong Thùng rác.'
    );

    $args = array(
        'labels'                => $labels,
        'public'                => false,
        'has_archive'           => false,
        'show_ui'               => false, // Không hiển thị giao diện mặc định
        'show_in_menu'          => false, // Không hiển thị trong menu chính
        'supports'              => array('title', 'editor'),
        'capability_type'       => 'post',
    );

    register_post_type('activity_log', $args);
}
add_action('init', 'ual_register_activity_log_post_type');

/**
 * Thêm trang quản trị cho Nhật ký hoạt động.
 */
function ual_add_admin_menu_page() {
    add_menu_page(
        'Ali Activity Logs',         // Tiêu đề trang
        'Ali Activity Logs',         // Tên menu
        'manage_options',             // Yêu cầu quyền
        'user-activity-logger',       // Slug của menu
        'ual_render_admin_page',      // Hàm hiển thị nội dung trang
        'dashicons-clipboard',        // Icon menu
        80                            // Vị trí menu
    );
    
    // Thêm trang cài đặt
    add_submenu_page(
        'user-activity-logger',
        'Cài đặt Ali Activity Logs',
        'Cài đặt',
        'manage_options',
        'ual-settings',
        'ual_settings_page'
    );
}
add_action('admin_menu', 'ual_add_admin_menu_page');

/**
 * Hàm hiển thị trang quản trị.
 */
function ual_render_admin_page() {
    if (!class_exists('WP_List_Table')) {
        require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
    }
    $list_table = new UAL_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap">
        <h2>User Activity Logs</h2>
        <form method="post">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page']; ?>" />
            <?php $list_table->display(); ?>
        </form>
    </div>
    <?php
}

/**
 * Class tùy chỉnh để hiển thị bảng danh sách trong trang quản trị.
 * extends WP_List_Table
 */
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class UAL_List_Table extends WP_List_Table {

    function get_columns() {
        $columns = array(
            'post_id'   => 'ID',
            'date_time' => 'Thời gian',
            'severity'  => 'Mức độ',
            'user'      => 'Người dùng',
            'user_ip'   => 'IP',
            'event'     => 'Loại sự kiện',
            'object'    => 'Đối tượng',
            'message'   => 'Tin nhắn'
        );
        return $columns;
    }

    function get_sortable_columns() {
        $sortable_columns = array(
            'date_time' => array('date_time', false),
            'user'      => array('user', false)
        );
        return $sortable_columns;
    }

    function column_default($item, $column_name) {
        switch ($column_name) {
            case 'post_id':
                return $item['post_id'];
            case 'date_time':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item['date_time']));
            case 'severity':
                $class = '';
                if ($item['severity'] === 'warning') {
                    $class = 'ual-warning';
                } elseif ($item['severity'] === 'danger') {
                    $class = 'ual-danger';
                }
                return '<span class="ual-severity ' . esc_attr($class) . '">' . esc_html(ucfirst($item['severity'])) . '</span>';
            case 'user':
                return $item['user'];
            case 'user_ip':
                return $item['user_ip'];
            case 'event':
                return $item['event'];
            case 'object':
                return $item['object'];
            case 'message':
                return $item['message'];
            default:
                return print_r($item, true);
        }
    }

    function prepare_items() {
        $per_page = 20;
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);

        $paged = $this->get_pagenum();
        $offset = ($paged - 1) * $per_page;

        $args = array(
            'post_type'      => 'activity_log',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC'
        );

        $query = new WP_Query($args);
        $total_items = $query->found_posts;
        $posts = $query->posts;

        $data = array();
        foreach ($posts as $post) {
            $user_id = get_post_meta($post->ID, '_ual_user_id', true);
            $user_info = get_userdata($user_id);
            $user_name = $user_info ? $user_info->user_login : 'Người dùng không xác định';

            $data[] = array(
                'post_id'    => $post->ID,
                'date_time'  => $post->post_date,
                'severity'   => get_post_meta($post->ID, '_ual_severity', true),
                'user'       => $user_name,
                'user_ip'    => get_post_meta($post->ID, '_ual_user_ip', true),
                'event'      => get_post_meta($post->ID, '_ual_event_type', true),
                'object'     => get_post_meta($post->ID, '_ual_object_name', true),
                'message'    => get_post_meta($post->ID, '_ual_message', true)
            );
        }

        $this->items = $data;

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }
}

/**
 * Hàm ghi lại hoạt động vào cơ sở dữ liệu.
 *
 * @param string $event_type Loại sự kiện (ví dụ: 'Đăng nhập', 'Chỉnh sửa bài viết').
 * @param string $message Chi tiết của hành động.
 * @param int $user_id ID của người dùng.
 * @param string $severity Mức độ nghiêm trọng ('info', 'warning', 'danger').
 * @param string $object_name Tên đối tượng liên quan (ví dụ: tên bài viết).
 */
function ual_log_activity($event_type, $message, $user_id, $severity = 'info', $object_name = '') {
    // Chỉ ghi nhật ký nếu tùy chọn đã được bật
    if (get_option('ual_logging_enabled', true) != '1') {
        return;
    }

    // Đảm bảo người dùng đã đăng nhập hoặc có thông tin người dùng
    if (empty($user_id)) {
        return;
    }

    $user_info = get_userdata($user_id);
    $user_name = $user_info ? $user_info->user_login : 'Người dùng không xác định';

    // Lấy địa chỉ IP của người dùng
    $user_ip = $_SERVER['REMOTE_ADDR'];

    // Chuẩn bị dữ liệu để chèn vào loại bài viết tùy chỉnh 'activity_log'
    $post_data = array(
        'post_title'    => $user_name . ' - ' . $event_type,
        'post_content'  => $message,
        'post_status'   => 'publish',
        'post_type'     => 'activity_log',
        'post_author'   => $user_id,
    );

    // Chèn bài viết mới vào cơ sở dữ liệu
    $log_id = wp_insert_post($post_data);

    // Lưu các trường tùy chỉnh vào Post Meta
    if ($log_id) {
        update_post_meta($log_id, '_ual_user_id', $user_id);
        update_post_meta($log_id, '_ual_user_ip', $user_ip);
        update_post_meta($log_id, '_ual_event_type', $event_type);
        update_post_meta($log_id, '_ual_severity', $severity);
        update_post_meta($log_id, '_ual_object_name', $object_name);
        update_post_meta($log_id, '_ual_message', $message);
    }
}

/**
 * Ghi lại hành động đăng nhập của người dùng.
 * Được kích hoạt bởi hook 'wp_login'.
 */
function ual_log_user_login($user_login, $user) {
    $message = sprintf('Người dùng "%s" đã đăng nhập thành công.', $user_login);
    ual_log_activity('Đăng nhập', $message, $user->ID, 'info', $user_login);
}
add_action('wp_login', 'ual_log_user_login', 10, 2);

/**
 * Ghi lại hành động đăng xuất của người dùng.
 * Được kích hoạt bởi hook 'wp_logout'.
 */
function ual_log_user_logout() {
    $user_id = get_current_user_id();
    if ($user_id) {
        $user_info = get_userdata($user_id);
        $user_name = $user_info ? $user_info->user_login : 'Người dùng không xác định';
        $message = sprintf('Người dùng "%s" đã đăng xuất.', $user_name);
        ual_log_activity('Đăng xuất', $message, $user_id, 'info', $user_name);
    }
}
add_action('wp_logout', 'ual_log_user_logout');

/**
 * Ghi lại hành động chỉnh sửa bài viết/trang.
 * Được kích hoạt bởi hook 'post_updated'.
 */
function ual_log_post_edit($post_id, $post_after, $post_before) {
    $user_id = get_current_user_id();
    if (empty($user_id)) {
        return;
    }

    $post_type = get_post_type($post_id);

    // Chỉ ghi nhật ký cho bài viết và trang
    if ($post_type !== 'post' && $post_type !== 'page') {
        return;
    }

    // Tránh ghi nhật ký các bản nháp tự động
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Tránh ghi nhật ký nếu post_status chưa được cập nhật từ 'auto-draft'
    if ($post_after->post_status === 'auto-draft') {
        return;
    }
    
    // Tên của đối tượng (bài viết/trang)
    $object_name = get_the_title($post_id);
    $action = '';
    $message = '';
    $severity = 'info';

    // Kiểm tra xem bài viết đã được tạo hay chỉ được cập nhật
    if ($post_after->post_date !== $post_before->post_date) {
        $action = 'Tạo mới ' . $post_type;
        $message = sprintf('Người dùng đã tạo mới %s "%s".', $post_type, $object_name);
    } else {
        $action = 'Chỉnh sửa ' . $post_type;
        $message = sprintf('Người dùng đã chỉnh sửa %s "%s".', $post_type, $object_name);
    }

    ual_log_activity($action, $message, $user_id, $severity, $object_name);
}
add_action('post_updated', 'ual_log_post_edit', 10, 3);

/**
 * Đăng ký các tùy chọn cài đặt plugin.
 */
function ual_settings_init() {
    register_setting('ual_settings_group', 'ual_logging_enabled', array(
        'type' => 'boolean',
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ));

    add_settings_section(
        'ual_main_settings_section',
        'Tùy chọn chung',
        null,
        'ual-settings'
    );

    add_settings_field(
        'ual_logging_enabled_field',
        'Bật ghi nhật ký',
        'ual_logging_enabled_callback',
        'ual-settings',
        'ual_main_settings_section'
    );
}
add_action('admin_init', 'ual_settings_init');

/**
 * Hàm callback để hiển thị trường checkbox.
 */
function ual_logging_enabled_callback() {
    $is_enabled = get_option('ual_logging_enabled', true);
    echo '<label for="ual_logging_enabled_field">';
    echo '<input type="checkbox" name="ual_logging_enabled" id="ual_logging_enabled_field" value="1" ' . checked(true, $is_enabled, false) . ' />';
    echo 'Bật chức năng ghi nhật ký hoạt động của người dùng.</label>';
}

/**
 * Hàm hiển thị trang cài đặt.
 */
function ual_settings_page() {
    ?>
    <div class="wrap">
        <h2>Cài đặt Ali Activity Logs</h2>
        <form action="options.php" method="post">
            <?php
            settings_fields('ual_settings_group');
            do_settings_sections('ual-settings');
            submit_button('Lưu Thay Đổi');
            ?>
        </form>
    </div>
    <?php
}

// Thêm CSS để định dạng bảng
function ual_add_admin_styles() {
    echo '
    <style>
        .ual-list-table {
            width: 100%;
        }
        .ual-severity {
            font-weight: bold;
            text-transform: uppercase;
        }
        .ual-severity.ual-warning {
            color: #FFA500;
        }
        .ual-severity.ual-danger {
            color: #ff0000;
        }
    </style>';
}
add_action('admin_head', 'ual_add_admin_styles');

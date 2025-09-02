<?php
/*
Plugin Name: Ali Security Audit Log
Plugin URI: https://example.com/ali-security-audit-log
Description: Ghi lại các hoạt động của người dùng cho mục đích kiểm tra bảo mật.
Version: 3.7
Author: Gemini
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
function asal_activate_plugin() {
    asal_register_activity_log_post_type();
    flush_rewrite_rules();

    // Thêm vai trò "Log View Only"
    add_role(
        'log_view_only',
        'Log View Only',
        array(
            'read' => true,
            'asal_view_logs' => true,
        )
    );

    // Thêm quyền hạn 'asal_view_logs' cho vai trò "Log View Only"
    $role = get_role('log_view_only');
    if ($role) {
        $role->add_cap('asal_view_logs');
    }

    // Lên lịch tác vụ dọn dẹp nhật ký hàng ngày
    if (!wp_next_scheduled('asal_daily_log_cleanup')) {
        wp_schedule_event(time(), 'daily', 'asal_daily_log_cleanup');
    }
}
register_activation_hook(__FILE__, 'asal_activate_plugin');

/**
 * Hàm hủy kích hoạt plugin.
 * Hủy bỏ tác vụ dọn dẹp đã lên lịch và vai trò tùy chỉnh.
 */
function asal_deactivate_plugin() {
    wp_clear_scheduled_hook('asal_daily_log_cleanup');
    
    // Xóa vai trò tùy chỉnh khi plugin bị hủy kích hoạt
    remove_role('log_view_only');
}
register_deactivation_hook(__FILE__, 'asal_deactivate_plugin');

/**
 * Hàm dọn dẹp các bản ghi cũ.
 */
function asal_cleanup_old_logs() {
    $retention_days = get_option('asal_log_retention_days', 0);
    
    // Nếu không có ngày lưu trữ được thiết lập hoặc bằng 0, không làm gì cả.
    if (empty($retention_days) || $retention_days <= 0) {
        return;
    }

    $args = array(
        'post_type'      => 'activity_log',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'date_query'     => array(
            array(
                'before'    => $retention_days . ' days ago',
                'inclusive' => true,
            ),
        ),
        'fields'         => 'ids',
    );

    $old_logs = get_posts($args);

    if ($old_logs) {
        foreach ($old_logs as $log_id) {
            wp_delete_post($log_id, true);
        }
    }
}
add_action('asal_daily_log_cleanup', 'asal_cleanup_old_logs');

/**
 * Đăng ký loại bài viết tùy chỉnh 'activity_log'.
 */
function asal_register_activity_log_post_type() {
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
add_action('init', 'asal_register_activity_log_post_type');

/**
 * Thêm trang quản trị cho Nhật ký hoạt động.
 */
function asal_add_admin_menu_page() {
    // Thêm trang chính của plugin vào menu "Công cụ"
    add_menu_page(
        'Ali Security Audit Log',         // Tiêu đề trang
        'Ali Security Audit Log',         // Tên menu
        'asal_view_logs',             // Yêu cầu quyền
        'ali-security-audit-log',      // Slug của menu
        'asal_render_admin_page',     // Hàm hiển thị nội dung trang
        'dashicons-clipboard',
        4
    );
    
    // Thêm trang cài đặt như một menu con
    add_submenu_page(
        'ali-security-audit-log',
        'Cài đặt Ali Security Audit Log',
        'Cài đặt',
        'manage_options',
        'asal-settings',
        'asal_settings_page'
    );
}
add_action('admin_menu', 'asal_add_admin_menu_page');

/**
 * Hàm hiển thị trang quản trị.
 */
function asal_render_admin_page() {
    if (!current_user_can('asal_view_logs')) {
        wp_die(__('Bạn không có quyền truy cập trang này.'));
    }

    if (!class_exists('WP_List_Table')) {
        require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
    }
    $list_table = new ASAL_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap">
        <h2>Nhật ký Kiểm tra Bảo mật</h2>
        <form method="get">
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

class ASAL_List_Table extends WP_List_Table {

    function get_columns() {
        $columns = array(
            'cb'        => '<input type="checkbox" />',
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
    
    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="log_id[]" value="%s" />',
            $item['post_id']
        );
    }
    
    function get_bulk_actions() {
        $actions = array(
            'delete' => 'Xóa'
        );
        return $actions;
    }
    
    function process_bulk_action() {
        if ('delete' === $this->current_action()) {
            if (!current_user_can('asal_view_logs')) {
                wp_die(__('Bạn không có quyền để thực hiện hành động này.'));
            }
            
            check_admin_referer('bulk-' . 'ali-security-audit-log');

            $log_ids = $_REQUEST['log_id'];
            if (is_array($log_ids)) {
                foreach ($log_ids as $log_id) {
                    wp_delete_post(absint($log_id), true);
                }
            }
        }
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
                    $class = 'asal-warning';
                } elseif ($item['severity'] === 'danger') {
                    $class = 'asal-danger';
                }
                return '<span class="asal-severity ' . esc_attr($class) . '">' . esc_html(ucfirst($item['severity'])) . '</span>';
            case 'user':
                return $item['user'];
            case 'user_ip':
                return $item['user_ip'];
            case 'event':
                return $item['event'];
            case 'object':
                return $item['object'];
            case 'message':
                // Rút gọn tin nhắn nếu quá dài
                $full_message = $item['message'];
                $short_message = $full_message;
                $read_more_link = '';

                if (strlen($full_message) > 100) {
                    $short_message = substr($full_message, 0, 100) . '...';
                    $read_more_link = '<a href="#" class="asal-read-more">Xem thêm</a>';
                }

                return '<span class="asal-truncated-message">' . esc_html($short_message) . '</span>'
                     . '<span class="asal-full-message" style="display:none;">' . esc_html($full_message) . '</span>'
                     . $read_more_link;
            default:
                return print_r($item, true);
        }
    }
    
    /**
     * Hiển thị các bộ lọc tùy chỉnh trên bảng.
     */
    function extra_tablenav($which) {
        if ('top' === $which) {
            // Lấy danh sách người dùng đã ghi nhật ký
            $users = get_users();
            $logged_event_types = array(
                'Đăng nhập'          => 'Đăng nhập',
                'Đăng xuất'          => 'Đăng xuất',
                'Tạo mới post'       => 'Tạo mới bài viết',
                'Chỉnh sửa post'     => 'Chỉnh sửa bài viết',
                'Tạo mới page'       => 'Tạo mới trang',
                'Chỉnh sửa page'     => 'Chỉnh sửa trang',
                'Tạo mới product'    => 'Tạo mới sản phẩm',
                'Chỉnh sửa product'  => 'Chỉnh sửa sản phẩm',
                'Thay đổi cài đặt'   => 'Thay đổi cài đặt'
            );
            $severities = array(
                'info'    => 'Thông tin',
                'warning' => 'Cảnh báo',
                'danger'  => 'Nguy hiểm'
            );
            
            ?>
            <div class="alignleft actions">
                <select name="asal_user_filter" id="asal_user_filter">
                    <option value="">Tất cả Người dùng</option>
                    <?php
                    $current_user = isset($_GET['asal_user_filter']) ? $_GET['asal_user_filter'] : '';
                    foreach ($users as $user) {
                        echo '<option value="' . esc_attr($user->ID) . '"' . selected($current_user, $user->ID) . '>' . esc_html($user->user_login) . '</option>';
                    }
                    ?>
                </select>

                <select name="asal_event_type_filter" id="asal_event_type_filter">
                    <option value="">Tất cả Sự kiện</option>
                    <?php
                    $current_event = isset($_GET['asal_event_type_filter']) ? $_GET['asal_event_type_filter'] : '';
                    foreach ($logged_event_types as $key => $value) {
                        echo '<option value="' . esc_attr($key) . '"' . selected($current_event, $key) . '>' . esc_html($value) . '</option>';
                    }
                    ?>
                </select>

                <select name="asal_severity_filter" id="asal_severity_filter">
                    <option value="">Tất cả Mức độ</option>
                    <?php
                    $current_severity = isset($_GET['asal_severity_filter']) ? $_GET['asal_severity_filter'] : '';
                    foreach ($severities as $key => $value) {
                        echo '<option value="' . esc_attr($key) . '"' . selected($current_severity, $key) . '>' . esc_html($value) . '</option>';
                    }
                    ?>
                </select>

                <?php submit_button('Lọc', 'secondary', false, false); ?>
            </div>
            <?php
        }
    }

    function prepare_items() {
        $this->process_bulk_action();

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

        $meta_query = array();

        // Xử lý bộ lọc người dùng
        if (isset($_GET['asal_user_filter']) && !empty($_GET['asal_user_filter'])) {
            $meta_query[] = array(
                'key'     => '_asal_user_id',
                'value'   => sanitize_text_field($_GET['asal_user_filter']),
                'compare' => '=',
            );
        }

        // Xử lý bộ lọc loại sự kiện
        if (isset($_GET['asal_event_type_filter']) && !empty($_GET['asal_event_type_filter'])) {
            $meta_query[] = array(
                'key'     => '_asal_event_type',
                'value'   => sanitize_text_field($_GET['asal_event_type_filter']),
                'compare' => '=',
            );
        }

        // Xử lý bộ lọc mức độ nghiêm trọng
        if (isset($_GET['asal_severity_filter']) && !empty($_GET['asal_severity_filter'])) {
            $meta_query[] = array(
                'key'     => '_asal_severity',
                'value'   => sanitize_text_field($_GET['asal_severity_filter']),
                'compare' => '=',
            );
        }

        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        $query = new WP_Query($args);
        $total_items = $query->found_posts;
        $posts = $query->posts;

        $data = array();
        foreach ($posts as $post) {
            $user_id = get_post_meta($post->ID, '_asal_user_id', true);
            $user_info = get_userdata($user_id);
            $user_name = $user_info ? $user_info->user_login : 'Người dùng không xác định';

            $data[] = array(
                'post_id'    => $post->ID,
                'date_time'  => $post->post_date,
                'severity'   => get_post_meta($post->ID, '_asal_severity', true),
                'user'       => $user_name,
                'user_ip'    => get_post_meta($post->ID, '_asal_user_ip', true),
                'event'      => get_post_meta($post->ID, '_asal_event_type', true),
                'object'     => get_post_meta($post->ID, '_asal_object_name', true),
                'message'    => get_post_meta($post->ID, '_asal_message', true)
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
function asal_log_activity($event_type, $message, $user_id, $severity = 'info', $object_name = '') {
    // Chỉ ghi nhật ký nếu tùy chọn đã được bật
    if (get_option('asal_logging_enabled', true) != '1') {
        return;
    }

    // Đảm bảo người dùng đã đăng nhập hoặc có thông tin người dùng
    if (empty($user_id)) {
        return;
    }
    
    // Tránh ghi nhật ký các bản nháp tự động hoặc sự kiện log của chính plugin
    if (strpos($event_type, 'asal') === 0 || strpos($object_name, 'asal') === 0) {
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
        update_post_meta($log_id, '_asal_user_id', $user_id);
        update_post_meta($log_id, '_asal_user_ip', $user_ip);
        update_post_meta($log_id, '_asal_event_type', $event_type);
        update_post_meta($log_id, '_asal_severity', $severity);
        update_post_meta($log_id, '_asal_object_name', $object_name);
        update_post_meta($log_id, '_asal_message', $message);
    }
}

/**
 * Ghi lại hành động đăng nhập của người dùng.
 * Được kích hoạt bởi hook 'wp_login'.
 */
function asal_log_user_login($user_login, $user) {
    $message = sprintf('Người dùng "%s" đã đăng nhập thành công.', $user_login);
    asal_log_activity('Đăng nhập', $message, $user->ID, 'info', $user_login);
}
add_action('wp_login', 'asal_log_user_login', 10, 2);

/**
 * Ghi lại hành động đăng xuất của người dùng.
 * Được kích hoạt bởi hook 'wp_logout'.
 */
function asal_log_user_logout() {
    $user_id = get_current_user_id();
    if ($user_id) {
        $user_info = get_userdata($user_id);
        $user_name = $user_info ? $user_info->user_login : 'Người dùng không xác định';
        $message = sprintf('Người dùng "%s" đã đăng xuất.', $user_name);
        asal_log_activity('Đăng xuất', $message, $user_id, 'info', $user_name);
    }
}
add_action('wp_logout', 'asal_log_user_logout');

/**
 * Ghi lại hành động chỉnh sửa bài viết/trang.
 * Được kích hoạt bởi hook 'post_updated'.
 */
function asal_log_post_edit($post_id, $post_after, $post_before) {
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

    asal_log_activity($action, $message, $user_id, $severity, $object_name);
}
add_action('post_updated', 'asal_log_post_edit', 10, 3);

/**
 * Ghi lại hành động chỉnh sửa sản phẩm.
 * Được kích hoạt bởi hook 'save_post'.
 */
function asal_log_product_activity($post_id, $post, $update) {
    // Chỉ xử lý cho loại bài viết 'product'
    if ($post->post_type !== 'product') {
        return;
    }

    // Bỏ qua các bản nháp tự động
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    $user_id = get_current_user_id();
    if (empty($user_id)) {
        return;
    }

    $product_name = get_the_title($post_id);
    $action = $update ? 'Chỉnh sửa product' : 'Tạo mới product';
    $message = $update ? sprintf('Người dùng đã chỉnh sửa sản phẩm "%s".', $product_name) : sprintf('Người dùng đã tạo mới sản phẩm "%s".', $product_name);
    
    asal_log_activity($action, $message, $user_id, 'info', $product_name);
}
add_action('save_post', 'asal_log_product_activity', 10, 3);


/**
 * Ghi lại các thay đổi cài đặt hệ thống.
 */
function asal_log_option_update($option, $old_value, $new_value) {
    $user_id = get_current_user_id();
    if (empty($user_id) || $old_value === $new_value) {
        return;
    }

    $object_name = $option;
    $event_type = 'Thay đổi cài đặt';
    $message = sprintf('Tùy chọn "%s" đã được thay đổi. Giá trị cũ: "%s", Giá trị mới: "%s".', $option, print_r($old_value, true), print_r($new_value, true));
    
    asal_log_activity($event_type, $message, $user_id, 'info', $object_name);
}
add_action('updated_option', 'asal_log_option_update', 10, 3);

/**
 * Xử lý yêu cầu gán vai trò người dùng
 */
function asal_handle_assign_role() {
    if (isset($_POST['asal_assign_role_submit']) && current_user_can('manage_options')) {
        check_admin_referer('asal_assign_role_nonce');

        $user_id = absint($_POST['asal_select_user']);
        $user_info = get_userdata($user_id);

        if ($user_info && !in_array('log_view_only', $user_info->roles)) {
            $user_info->add_role('log_view_only');
            // Thêm thông báo thành công
            add_settings_error('asal_settings_group', 'asal_user_added', 'Đã thêm người dùng vào vai trò "Log View Only" thành công.', 'success');
        } else {
            // Thêm thông báo lỗi
            add_settings_error('asal_settings_group', 'asal_user_exists', 'Người dùng đã có vai trò này hoặc không hợp lệ.', 'error');
        }
    }
}
add_action('admin_init', 'asal_handle_assign_role');

/**
 * Xử lý yêu cầu xóa vai trò người dùng
 */
function asal_handle_remove_role() {
    if (isset($_POST['asal_remove_role_submit']) && current_user_can('manage_options')) {
        check_admin_referer('asal_remove_role_nonce');

        $user_id = absint($_POST['asal_user_id_to_remove']);
        $user_info = get_userdata($user_id);

        if ($user_info && in_array('log_view_only', $user_info->roles)) {
            $user_info->remove_role('log_view_only');
            add_settings_error('asal_settings_group', 'asal_user_removed', 'Đã xóa người dùng khỏi vai trò "Log View Only" thành công.', 'success');
        } else {
            add_settings_error('asal_settings_group', 'asal_user_not_found', 'Người dùng không có vai trò này hoặc không hợp lệ.', 'error');
        }
    }
}
add_action('admin_init', 'asal_handle_remove_role');

/**
 * Đăng ký các tùy chọn cài đặt plugin.
 */
function asal_settings_init() {
    register_setting('asal_settings_group', 'asal_logging_enabled', array(
        'type' => 'boolean',
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ));
    
    register_setting('asal_settings_group', 'asal_log_retention_days', array(
        'type' => 'integer',
        'default' => 30,
        'sanitize_callback' => 'absint'
    ));

    add_settings_section(
        'asal_main_settings_section',
        'Tùy chọn chung',
        null,
        'asal-settings'
    );
    
    add_settings_field(
        'asal_logging_enabled_field',
        'Bật ghi nhật ký',
        'asal_logging_enabled_callback',
        'asal-settings',
        'asal_main_settings_section'
    );
    
    add_settings_field(
        'asal_log_retention_days_field',
        'Thời gian lưu nhật ký (ngày)',
        'asal_log_retention_days_callback',
        'asal-settings',
        'asal_main_settings_section'
    );
    
    add_settings_field(
        'asal_log_view_users_field',
        'Người dùng có quyền xem log',
        'asal_log_view_users_callback',
        'asal-settings',
        'asal_main_settings_section'
    );

    add_settings_section(
        'asal_role_management_section',
        'Quản lý vai trò "Log View Only"',
        null,
        'asal-settings'
    );
    
    add_settings_field(
        'asal_assign_log_role_field',
        'Thêm người dùng vào vai trò',
        'asal_assign_log_role_callback',
        'asal-settings',
        'asal_role_management_section'
    );
}
add_action('admin_init', 'asal_settings_init');

/**
 * Hàm callback để hiển thị trường checkbox.
 */
function asal_logging_enabled_callback() {
    $is_enabled = get_option('asal_logging_enabled', true);
    echo '<label for="asal_logging_enabled_field">';
    echo '<input type="checkbox" name="asal_logging_enabled" id="asal_logging_enabled_field" value="1" ' . checked(true, $is_enabled, false) . ' />';
    echo 'Bật chức năng ghi nhật ký hoạt động của người dùng.</label>';
}

/**
 * Hàm callback để hiển thị trường nhập số ngày.
 */
function asal_log_retention_days_callback() {
    $days = get_option('asal_log_retention_days', 30);
    echo '<input type="number" name="asal_log_retention_days" id="asal_log_retention_days_field" value="' . esc_attr($days) . '" min="0" step="1" />';
    echo '<p class="description">Số ngày để lưu trữ nhật ký. Sau thời gian này, các bản ghi cũ sẽ tự động bị xóa. Nhập 0 để lưu trữ vĩnh viễn.</p>';
}

/**
 * Hàm callback để hiển thị danh sách người dùng có quyền xem log.
 */
function asal_log_view_users_callback() {
    $args = array(
        'role' => 'log_view_only',
        'orderby' => 'user_login',
        'order' => 'ASC'
    );
    $users = get_users($args);

    if (empty($users)) {
        echo '<p>Chưa có người dùng nào được gán vai trò "Log View Only".</p>';
    } else {
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="manage-column">Tên đăng nhập</th>';
        echo '<th class="manage-column">Tên đầy đủ</th>';
        echo '<th class="manage-column">Hành động</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        foreach ($users as $user) {
            echo '<tr>';
            echo '<td>' . esc_html($user->user_login) . '</td>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;">';
            settings_fields('asal_settings_group');
            wp_nonce_field('asal_remove_role_nonce');
            echo '<input type="hidden" name="asal_user_id_to_remove" value="' . esc_attr($user->ID) . '">';
            echo '<input type="submit" name="asal_remove_role_submit" value="Xóa" class="button button-secondary button-small" onclick="return confirm(\'Bạn có chắc chắn muốn xóa người dùng này khỏi vai trò không?\');">';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    }
}

/**
 * Hàm callback để hiển thị form thêm người dùng vào vai trò
 */
function asal_assign_log_role_callback() {
    // Lấy tất cả người dùng trừ những người đã có vai trò log_view_only
    $users = get_users(array(
        'role__not_in' => array('log_view_only'),
        'orderby' => 'user_login',
        'order' => 'ASC'
    ));

    if (empty($users)) {
        echo '<p>Tất cả người dùng đều đã có vai trò này hoặc không có người dùng nào để gán.</p>';
        return;
    }
    
    echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
    settings_fields('asal_settings_group');
    echo '<select name="asal_select_user" id="asal_select_user">';
    foreach ($users as $user) {
        echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->user_login) . '</option>';
    }
    echo '</select>';
    echo ' ';
    submit_button('Gán quyền', 'primary', 'asal_assign_role_submit', false);
    wp_nonce_field('asal_assign_role_nonce');
    echo '</form>';
}

/**
 * Hàm hiển thị trang cài đặt.
 */
function asal_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Bạn không có quyền truy cập trang này.'));
    }
    ?>
    <div class="wrap">
        <h2>Cài đặt Ali Security Audit Log</h2>
        <?php settings_errors(); ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('asal_settings_group');
            do_settings_sections('asal-settings');
            submit_button('Lưu Thay Đổi');
            ?>
        </form>
    </div>
    <?php
}

// Thêm CSS và JavaScript để định dạng bảng và xử lý sự kiện
function asal_add_admin_assets() {
    ?>
    <style>
        .asal-list-table {
            width: 100%;
        }
        .asal-severity {
            font-weight: bold;
            text-transform: uppercase;
        }
        .asal-severity.asal-warning {
            color: #FFA500;
        }
        .asal-severity.asal-danger {
            color: #ff0000;
        }
        .asal-truncated-message, .asal-read-more {
            display: inline;
        }
        .asal-full-message {
            display: none;
        }
        .asal-read-more, .asal-hide-message {
            cursor: pointer;
            color: #0073aa;
            text-decoration: underline;
            margin-left: 5px;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sử dụng một selector cụ thể để tránh xung đột
            const table = document.querySelector('.wp-list-table');
            if (table) {
                table.addEventListener('click', function(event) {
                    const target = event.target;
                    if (target.classList.contains('asal-read-more')) {
                        event.preventDefault();
                        const row = target.closest('td');
                        if (row) {
                            const truncatedSpan = row.querySelector('.asal-truncated-message');
                            const fullSpan = row.querySelector('.asal-full-message');
                            
                            if (fullSpan.style.display === 'none') {
                                truncatedSpan.style.display = 'none';
                                fullSpan.style.display = 'inline';
                                target.textContent = 'Thu gọn';
                            } else {
                                truncatedSpan.style.display = 'inline';
                                fullSpan.style.display = 'none';
                                target.textContent = 'Xem thêm';
                            }
                        }
                    }
                });
            }
        });
    </script>
    <?php
}
add_action('admin_head', 'asal_add_admin_assets');

/**
 * Thêm shortcode để hiển thị log trên front-end
 */
function asal_add_front_end_shortcode() {
    add_shortcode('ali_security_audit_log', 'asal_render_front_end_logs');
}
add_action('init', 'asal_add_front_end_shortcode');

/**
 * Hàm hiển thị log trên front-end
 */
function asal_render_front_end_logs() {
    ob_start();

    // Kiểm tra quyền của người dùng
    if (!is_user_logged_in()) {
        echo '<p>Bạn cần đăng nhập để xem nhật ký hoạt động. Vui lòng <a href="' . wp_login_url() . '">Đăng nhập</a>.</p>';
        return ob_get_clean();
    }

    if (!current_user_can('asal_view_logs')) {
        echo '<p>Bạn không có quyền truy cập vào nhật ký hoạt động này.</p>';
        return ob_get_clean();
    }

    // Lấy dữ liệu nhật ký
    $args = array(
        'post_type'      => 'activity_log',
        'post_status'    => 'publish',
        'posts_per_page' => 50, // Giới hạn số lượng bản ghi hiển thị
        'orderby'        => 'date',
        'order'          => 'DESC'
    );
    $query = new WP_Query($args);
    $logs = $query->posts;

    if (empty($logs)) {
        echo '<p>Không có bản ghi nhật ký nào.</p>';
        return ob_get_clean();
    }

    ?>
    <style>
        .asal-log-table {
            width: 100%;
            border-collapse: collapse;
            font-family: Arial, sans-serif;
            margin-top: 20px;
        }
        .asal-log-table th, .asal-log-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .asal-log-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .asal-log-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
    </style>
    <h3>Nhật ký Hoạt động</h3>
    <table class="asal-log-table">
        <thead>
            <tr>
                <th>Thời gian</th>
                <th>Mức độ</th>
                <th>Người dùng</th>
                <th>Sự kiện</th>
                <th>Đối tượng</th>
                <th>Tin nhắn</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log) : 
                $user_id = get_post_meta($log->ID, '_asal_user_id', true);
                $user_info = get_userdata($user_id);
                $user_name = $user_info ? $user_info->user_login : 'Người dùng không xác định';
            ?>
                <tr>
                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->post_date)); ?></td>
                    <td><?php echo esc_html(ucfirst(get_post_meta($log->ID, '_asal_severity', true))); ?></td>
                    <td><?php echo esc_html($user_name); ?></td>
                    <td><?php echo esc_html(get_post_meta($log->ID, '_asal_event_type', true)); ?></td>
                    <td><?php echo esc_html(get_post_meta($log->ID, '_asal_object_name', true)); ?></td>
                    <td><?php echo esc_html(get_post_meta($log->ID, '_asal_message', true)); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

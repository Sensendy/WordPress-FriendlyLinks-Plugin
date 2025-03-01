<?php
/*
Plugin Name: 友情链接管理
Description: 自动创建友链页面并提供申请审核功能
Version: 4.0
Author: 森森的夜
*/

global $wpdb;
define('FL_TABLE', $wpdb->prefix . 'friendly_links');
define('FL_PAGE_SLUG', 'links');

// 激活时初始化
register_activation_hook(__FILE__, 'fl_activate');
function fl_activate() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE " . FL_TABLE . " (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        site_name varchar(255) NOT NULL,
        owner_name varchar(255) NOT NULL,
        url varchar(255) NOT NULL,
        slogan text,
        logo_url varchar(512),
        status varchar(20) DEFAULT 'pending',
        submitted_time datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY url (url)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // 创建页面
    $existing_page = get_page_by_path(FL_PAGE_SLUG);
    if (!$existing_page) {
        $page_id = wp_insert_post(array(
            'post_title'    => '友情链接',
            'post_name'     => FL_PAGE_SLUG,
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_content'  => '[friendly_links]'
        ));
    }

    // 设置定时清理任务
    if (!wp_next_scheduled('fl_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'fl_daily_cleanup');
    }
}

// 停用时清理
register_deactivation_hook(__FILE__, 'fl_deactivate');
function fl_deactivate() {
    wp_clear_scheduled_hook('fl_daily_cleanup');
}

// 每日清理30天前的拒绝记录
add_action('fl_daily_cleanup', 'fl_cleanup_records');
function fl_cleanup_records() {
    global $wpdb;
    
    // 获取要删除的记录
    $links = $wpdb->get_results(
        "SELECT * FROM " . FL_TABLE . " 
        WHERE status = 'rejected' 
        AND submitted_time < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    
    if ($links) {
        $upload_dir = wp_upload_dir();
        
        foreach ($links as $link) {
            // 删除LOGO文件
            if (!empty($link->logo_url)) {
                $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $link->logo_url);
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
        }
        
        // 删除数据库记录
        $wpdb->query(
            "DELETE FROM " . FL_TABLE . " 
            WHERE status = 'rejected' 
            AND submitted_time < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }
}

// 短代码处理
add_shortcode('friendly_links', 'fl_display_content');
function fl_display_content() {
    ob_start();
    
    // 显示状态消息
    if (isset($_GET['fl_status'])) {
        echo '<div class="fl-notice">';
        switch ($_GET['fl_status']) {
            case 'success':
                echo '<p class="success">申请已提交，等待审核！</p>';
                break;
            case 'error':
                $message = isset($_GET['message']) ? urldecode($_GET['message']) : '未知错误';
                echo '<p class="error">提交失败：' . esc_html($message) . '</p>';
                break;
        }
        echo '</div>';
    }

    // 显示友链
    global $wpdb;
    $links = $wpdb->get_results("SELECT * FROM " . FL_TABLE . " WHERE status = 'approved'");
    
    if ($links) {
        echo '<div class="friendly-links">';
        foreach ($links as $link) {
            echo '<div class="fl-item">';
            if (!empty($link->logo_url)) {
                echo '<div class="fl-logo"><img src="' . esc_url($link->logo_url) . '" alt="' . esc_attr($link->site_name) . '"></div>';
            }
            echo '<div class="fl-info">';
            echo '<h3><a href="' . esc_url($link->url) . '" target="_blank" rel="nofollow noopener">' . esc_html($link->site_name) . '</a></h3>';
            echo '<p class="owner">所有者：' . esc_html($link->owner_name) . '</p>';
            if (!empty($link->slogan)) {
                echo '<p class="slogan">' . esc_html($link->slogan) . '</p>';
            }
            echo '</div></div>';
        }
        echo '</div>';
    } else {
        echo '<p class="no-links">暂无友情链接</p>';
    }

    fl_display_form();
    
    return ob_get_clean();
}

// 申请表单
function fl_display_form() {
    ?>
    <div class="fl-application">
        <h3>申请友情链接</h3>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('fl_apply_nonce', 'fl_nonce'); ?>
            <div class="form-group">
                <label>网站名称 *</label>
                <input type="text" name="site_name" required maxlength="255">
            </div>
            <div class="form-group">
                <label>所有者名称 *</label>
                <input type="text" name="owner_name" required maxlength="255">
            </div>
            <div class="form-group">
                <label>网站地址 *</label>
                <input type="url" name="url" required pattern="https?://.+" placeholder="https://example.com">
            </div>
            <div class="form-group">
                <label>格言（最多140字）</label>
                <textarea name="slogan" maxlength="140"></textarea>
            </div>
            <div class="form-group">
                <label>LOGO（可选，PNG/JPG，最大2MB）</label>
                <input type="file" name="logo" accept=".jpg,.jpeg,.png">
            </div>
            <button type="submit" name="fl_submit" class="submit-btn">提交申请</button>
        </form>
    </div>
    <?php
}

// 处理提交
add_action('init', 'fl_handle_submission');
function fl_handle_submission() {
    if (!isset($_POST['fl_submit'])) return;

    $error = false;
    $redirect_url = get_permalink();

    // 验证nonce
    if (!wp_verify_nonce($_POST['fl_nonce'], 'fl_apply_nonce')) {
        $error = '安全验证失败，请刷新页面后重试';
    }

    // 验证必填字段
    $required_fields = ['site_name', 'owner_name', 'url'];
    foreach ($required_fields as $field) {
        if (empty(trim($_POST[$field]))) {
            $error = '请填写所有带*号的必填字段';
            break;
        }
    }

    // 验证URL格式
    if (!$error && !filter_var($_POST['url'], FILTER_VALIDATE_URL)) {
        $error = '请输入有效的网站地址';
    }

    // 处理文件上传
    $logo_url = '';
    if (!$error && isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['logo'];
        
        // 检查上传错误
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = array(
                1 => '文件大小超过服务器限制（最大'.size_format(wp_max_upload_size()).'）',
                2 => '文件大小超过表单限制',
                3 => '文件只有部分被上传',
                4 => '没有文件被上传',
                6 => '找不到临时文件夹',
                7 => '文件写入失败',
                8 => '文件上传被PHP扩展阻止'
            );
            $error = '文件上传失败：' . ($upload_errors[$file['error']] ?? '未知错误');
        } else {
            // 验证文件类型
            $file_info = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            $allowed_types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            
            if (!isset($allowed_types[$file_info['ext']])) {
                $error = '只支持JPG/PNG格式图片';
            }
            // 验证文件大小
            elseif ($file['size'] > (2 * 1024 * 1024)) { // 2MB
                $error = '图片大小不能超过2MB';
            }
            // 上传文件
            else {
                $upload_dir = wp_upload_dir();
                if (!wp_mkdir_p($upload_dir['path'])) {
                    $error = '无法创建上传目录';
                } else {
                    $unique_name = wp_unique_filename($upload_dir['path'], $file['name']);
                    $filename = wp_normalize_path($upload_dir['path'] . '/' . $unique_name);

                    if (@move_uploaded_file($file['tmp_name'], $filename)) {
                        $logo_url = $upload_dir['url'] . '/' . $unique_name;
                    } else {
                        $error = '文件保存失败，请检查目录权限';
                    }
                }
            }
        }
    }

    // 检查URL是否已存在
    if (!$error) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . FL_TABLE . " WHERE url = %s",
            esc_url_raw($_POST['url'])
        ));
        if ($exists) {
            $error = '该网址已存在申请记录';
        }
    }

    // 保存数据
    if (!$error) {
        global $wpdb;
        try {
            $result = $wpdb->insert(FL_TABLE, array(
                'site_name'  => sanitize_text_field($_POST['site_name']),
                'owner_name' => sanitize_text_field($_POST['owner_name']),
                'url'        => esc_url_raw($_POST['url']),
                'slogan'     => sanitize_textarea_field($_POST['slogan']),
                'logo_url'   => $logo_url
            ));
            
            if ($result === false) {
                $error = '提交失败：'. $wpdb->last_error;
            }
        } catch (Exception $e) {
            $error = '数据库错误：' . $e->getMessage();
        }
    }

    // 处理重定向
    $args = $error ? ['fl_status' => 'error', 'message' => urlencode($error)] : ['fl_status' => 'success'];
    wp_redirect(add_query_arg($args, $redirect_url));
    exit;
}

// 修改后台菜单
add_action('admin_menu', 'fl_admin_menu');
function fl_admin_menu() {
    add_menu_page(
        '友情链接审核',
        '友链管理',
        'manage_options',
        'fl-review',
        'fl_admin_review_page',
        'dashicons-admin-links',
        30
    );
    
    add_submenu_page(
        'fl-review',
        '已通过友链',
        '已通过友链',
        'manage_options',
        'fl-approved',
        'fl_admin_approved_page'
    );
}

// 新增已通过友链管理页面
function fl_admin_approved_page() {
    global $wpdb;
    
    // 处理删除操作
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        check_admin_referer('fl_action');
        
        $id = intval($_GET['id']);
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . FL_TABLE . " WHERE id = %d",
            $id
        ));
        
        if ($link) {
            // 删除LOGO文件
            if (!empty($link->logo_url)) {
                $upload_dir = wp_upload_dir();
                $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $link->logo_url);
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
            
            // 删除数据库记录
            $result = $wpdb->delete(
                FL_TABLE,
                array('id' => $id),
                array('%d')
            );
            
            if ($result !== false) {
                echo '<div class="notice notice-success"><p>删除成功</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>删除失败：'. esc_html($wpdb->last_error) .'</p></div>';
            }
        }
    }

    // 获取已通过友链
    $links = $wpdb->get_results("SELECT * FROM " . FL_TABLE . " WHERE status = 'approved' ORDER BY submitted_time DESC");
    
    ?>
    <div class="wrap">
        <h1>已通过友情链接</h1>
        <?php if (empty($links)): ?>
            <div class="notice notice-info"><p>当前没有已通过的友链</p></div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>网站名称</th>
                        <th>所有者</th>
                        <th>网址</th>
                        <th>LOGO</th>
                        <th>格言</th>
                        <th>添加时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $link): ?>
                    <tr>
                        <td><?php echo esc_html($link->site_name); ?></td>
                        <td><?php echo esc_html($link->owner_name); ?></td>
                        <td><a href="<?php echo esc_url($link->url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_url($link->url); ?></a></td>
                        <td><?php echo $link->logo_url ? '<img src="'.esc_url($link->logo_url).'" style="max-height:50px">' : '无'; ?></td>
                        <td><?php echo esc_html($link->slogan); ?></td>
                        <td><?php echo date_i18n('Y-m-d H:i', strtotime($link->submitted_time)); ?></td>
                        <td>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fl-approved&action=delete&id='.$link->id), 'fl_action'); ?>" 
                               onclick="return confirm('确定要永久删除该友链吗？')" 
                               style="color: #a00;">删除</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// 修改原审核页面函数名
function fl_admin_review_page() {
    global $wpdb;
    
    // 处理删除操作
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        check_admin_referer('fl_action');
        
        $id = intval($_GET['id']);
        
        // 获取要删除的友链信息
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . FL_TABLE . " WHERE id = %d",
            $id
        ));
        
        if ($link) {
            // 删除LOGO文件
            if (!empty($link->logo_url)) {
                $upload_dir = wp_upload_dir();
                $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $link->logo_url);
                
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
            
            // 删除数据库记录
            $result = $wpdb->delete(
                FL_TABLE,
                array('id' => $id),
                array('%d')
            );
            
            if ($result !== false) {
                echo '<div class="notice notice-success"><p>删除成功</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>删除失败：'. esc_html($wpdb->last_error) .'</p></div>';
            }
        }
    }

    // 处理审核操作
    if (isset($_GET['action']) && isset($_GET['id'])) {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        check_admin_referer('fl_action');
        
        $id = intval($_GET['id']);
        $action = sanitize_text_field($_GET['action']);
        
        if (in_array($action, ['approve', 'reject'])) {            
            $status = ($action === 'approve') ? 'approved' : 'rejected';
            $result = $wpdb->update(
                FL_TABLE, 
                array('status' => $status), 
                array('id' => $id), 
                array('%s'), 
                array('%d')
            );
            
            if ($result !== false) {
                echo '<div class="notice notice-success"><p>操作成功</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>操作失败：'. esc_html($wpdb->last_error) .'</p></div>';
            }
        }
    }

    // 获取待审核列表
    $applications = $wpdb->get_results("SELECT * FROM " . FL_TABLE . " WHERE status = 'pending' ORDER BY submitted_time DESC");
    
    ?>
    <div class="wrap">
        <h1>友情链接审核</h1>
        <?php if (empty($applications)): ?>
            <div class="notice notice-info"><p>当前没有待审核的申请</p></div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>网站名称</th>
                        <th>所有者</th>
                        <th>网址</th>
                        <th>LOGO</th>
                        <th>格言</th>
                        <th>申请时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applications as $app): ?>
                    <tr>
                        <td><?php echo esc_html($app->site_name); ?></td>
                        <td><?php echo esc_html($app->owner_name); ?></td>
                        <td><a href="<?php echo esc_url($app->url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_url($app->url); ?></a></td>
                        <td><?php echo $app->logo_url ? '<img src="'.esc_url($app->logo_url).'" style="max-height:50px">' : '无'; ?></td>
                        <td><?php echo esc_html($app->slogan); ?></td>
                        <td><?php echo date_i18n('Y-m-d H:i', strtotime($app->submitted_time)); ?></td>
                        <td>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fl-review&action=approve&id='.$app->id), 'fl_action'); ?>">通过</a> | 
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fl-review&action=reject&id='.$app->id), 'fl_action'); ?>">拒绝</a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fl-review&action=delete&id='.$app->id), 'fl_action'); ?>" 
                               onclick="return confirm('确定要永久删除该友链吗？')" 
                               style="color: #a00;">删除</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// 加载资源
add_action('wp_enqueue_scripts', 'fl_enqueue_scripts');
function fl_enqueue_scripts() {
    wp_enqueue_style('fl-style', plugins_url('style.css', __FILE__));
}
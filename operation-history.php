<?php
/*
Plugin Name: Operation History (backend-users) - Improved for Game Tracking
Plugin URI: https://your-site.example/operation-history
Description: 记录后台操作历史（改进版）。支持 Gutenberg/REST/AJAX 保存，优先使用编辑页快照并记录详细 meta 变更。
Version: 2.3
Author: YourName
Author URI: https://your-site.example
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: operation-history
Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;

/* ---------------- 配置项 ---------------- */
// 需要跟踪的 post_type 列表；若希望跟踪所有类型可留空 array()
$OH_TRACKED_POST_TYPES = array('game_management', 'post');

// 是否启用调试（会写入 wp-content/debug.log），上线后建议设为 false
$OH_DEBUG_ENABLED = false;

/* ---------------- 辅助函数 ---------------- */
function oh_debug_enabled() {
    global $OH_DEBUG_ENABLED;
    return !empty($OH_DEBUG_ENABLED) && defined('WP_DEBUG') && WP_DEBUG;
}

function oh_debug($msg) {
    if (!oh_debug_enabled()) return;
    if (is_array($msg) || is_object($msg)) $msg = print_r($msg, true);
    error_log('[OH DEBUG] ' . $msg);
}

/* --- 创建表 --- */
register_activation_hook(__FILE__, 'oh_create_table');
function oh_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'operation_history';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        time datetime NOT NULL,
        user_id bigint(20) unsigned DEFAULT 0,
        user_login varchar(60) DEFAULT '',
        action varchar(160) DEFAULT '',
        object_type varchar(60) DEFAULT '',
        object_id bigint(20) DEFAULT 0,
        details text,
        ip varchar(45) DEFAULT '',
        PRIMARY KEY (id)
    ) {$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // 安排清理任务
    if (!wp_next_scheduled('oh_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'oh_daily_cleanup');
    }
}

register_deactivation_hook(__FILE__, 'oh_unschedule_cleanup');
function oh_unschedule_cleanup() {
    $t = wp_next_scheduled('oh_daily_cleanup');
    if ($t) wp_unschedule_event($t, 'oh_daily_cleanup');
}

/* 定期清理（365 天） */
add_action('oh_daily_cleanup', 'oh_do_cleanup');
function oh_do_cleanup() {
    global $wpdb;
    $table = $wpdb->prefix . 'operation_history';
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE time < %s", date('Y-m-d H:i:s', strtotime('-365 days'))));
}

/* --- 更宽松的后台上下文检测 --- */
function oh_is_backend_user_context() {
    if (!is_user_logged_in()) return false;
    if (is_admin()) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (defined('DOING_AJAX') && DOING_AJAX) return true;
    return false;
}

/* --- 忽略的 meta 键 --- */
function oh_get_ignored_meta_keys() {
    return array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_thumbnail_id',
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
    );
}

/* --- 归一化 meta 值 --- */
function oh_normalize_meta_value($val) {
    if (is_array($val)) {
        $clean = array();
        foreach ($val as $v) {
            if (is_array($v)) {
                $sub = array();
                foreach ($v as $sv) {
                    $s = trim((string)$sv);
                    if ($s === '') continue;
                    $sub[] = $s;
                }
                if (!empty($sub)) $clean[] = $sub;
            } else {
                $s = trim((string)$v);
                if ($s === '') continue;
                $clean[] = $s;
            }
        }
        if (empty($clean)) return null;
        if (count($clean) === 1) return $clean[0];
        return array_values($clean);
    }
    $s = trim((string)$val);
    if ($s === '') return null;
    return $s;
}

/* --- 直接从 DB 读取 postmeta（用于旧快照） --- */
function oh_get_postmeta_raw_from_db($post_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
        $post_id
    ), ARRAY_A);

    $res = array();
    if ($rows) {
        foreach ($rows as $r) {
            $key = $r['meta_key'];
            $val = maybe_unserialize($r['meta_value']);
            if (!isset($res[$key])) $res[$key] = array();
            $res[$key][] = $val;
        }
    }
    return $res;
}

/* --- 内存缓存 meta --- */
function oh_set_meta_cache($post_id, $meta) {
    static $oh_meta_cache = array();
    $oh_meta_cache[$post_id] = $meta;
}

function oh_get_cached_meta($post_id) {
    static $oh_meta_cache = array();
    return isset($oh_meta_cache[$post_id]) ? $oh_meta_cache[$post_id] : array();
}

/* --- 在编辑页加载时生成快照（供保存时优先使用） --- */
add_action('load-post.php', 'oh_snapshot_meta_on_load');
add_action('load-post-new.php', 'oh_snapshot_meta_on_load');
function oh_snapshot_meta_on_load() {
    if (!oh_is_backend_user_context()) return;

    if (function_exists('get_current_screen')) {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post') {
            // 兼容性继续
        }
    }

    $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
    if (!$post_id) return;
    $user_id = get_current_user_id();
    $all_meta = get_post_meta($post_id);
    $transient_key = 'oh_snapshot_' . $post_id . '_' . $user_id;
    set_transient($transient_key, $all_meta, 12 * HOUR_IN_SECONDS);
    oh_debug("Snapshot saved for post {$post_id} user {$user_id}");
}

/* --- 读取并清除快照 --- */
function oh_get_and_clear_snapshot($post_id) {
    $user_id = get_current_user_id();
    $transient_key = 'oh_snapshot_' . $post_id . '_' . $user_id;
    $snap = get_transient($transient_key);
    if ($snap !== false) {
        delete_transient($transient_key);
        return $snap;
    }
    return false;
}

/* --- 预更新缓存（后备） --- */
add_action('pre_post_update', 'oh_pre_post_update_cache_meta', 1, 2);
function oh_pre_post_update_cache_meta($post_ID, $data) {
    if (!oh_is_backend_user_context()) return;
    if (wp_is_post_revision($post_ID)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    $mem = oh_get_cached_meta($post_ID);
    if (!empty($mem)) return;
    $all_meta = oh_get_postmeta_raw_from_db($post_ID);
    oh_set_meta_cache($post_ID, $all_meta);
    oh_debug("Pre-update DB snapshot cached for post {$post_ID}");
}

/* --- 日志写入 --- */
function oh_generate_log_details($action, $object_type, $object_id, $extra_info = '', $changes = '') {
    $actions = array(
        'post_created' => '创建了',
        'post_updated' => '更新了',
        'post_deleted' => '删除了',
        'attachment_added' => '添加了附件',
        'attachment_edited' => '编辑了附件',
        'theme_switched' => '切换了主题',
        'plugin_activated' => '启用了插件',
        'plugin_deactivated' => '停用了插件',
        'user_login' => '登录',
        'user_logout' => '登出',
        'profile_update' => '更新了用户资料',
        'game_updated' => '更新了游戏'
    );
    $object_types = array(
        'post' => '文章',
        'attachment' => '附件',
        'theme' => '主题',
        'plugin' => '插件',
        'user' => '用户',
        'game' => '游戏'
    );
    $action_desc = isset($actions[$action]) ? $actions[$action] : $action;
    $object_type_desc = isset($object_types[$object_type]) ? $object_types[$object_type] : $object_type;
    $details = "{$action_desc} {$object_type_desc}（ID: {$object_id}）";
    if ($extra_info) $details .= "，{$extra_info}";
    if ($changes) $details .= "，变更：{$changes}";
    return $details;
}

function oh_log($action, $object_type = '', $object_id = 0, $extra_info = '', $changes = '', $force = false) {
    if (!$force && !oh_is_backend_user_context()) return;

    global $wpdb;
    $table = $wpdb->prefix . 'operation_history';
    $user = wp_get_current_user();
    $datetime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    $current_time = $datetime->format('Y-m-d H:i:s');
    $details = oh_generate_log_details($action, $object_type, $object_id, $extra_info, $changes);
    $res = $wpdb->insert(
        $table,
        array(
            'time' => $current_time,
            'user_id' => intval($user->ID),
            'user_login' => isset($user->user_login) ? $user->user_login : '',
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => intval($object_id),
            'details' => mb_substr($details, 0, 2000),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        ),
        array('%s','%d','%s','%s','%s','%d','%s','%s')
    );

    if ($res === false) {
        oh_debug("oh_log db insert failed: " . $wpdb->last_error);
    } else {
        oh_debug("oh_log inserted: action={$action} object_type={$object_type} id={$object_id}");
    }
}

/* --- 排除修订与自动保存的辅助 --- */
function oh_is_valid_post_for_logging($post_id) {
    if (wp_is_post_revision($post_id)) return false;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return false;
    return true;
}

/* --- 变化片段提取 --- */
function oh_extract_changed_fragment($before_html, $after_html, $max_chars = 200, $context_words = 5) {
    $before_html = (string)$before_html;
    $after_html = (string)$after_html;
    if ($before_html === $after_html) return '';

    // Remove tags and normalize whitespace
    $before_text_full = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($before_html)));
    $after_text_full = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($after_html)));

    $split_pattern = '/(<\/p>|<br\s*\/?>|\r?\n){1,}/i';
    $before_blocks = preg_split($split_pattern, $before_html);
    $after_blocks = preg_split($split_pattern, $after_html);

    $get_block_text = function($html_block) {
        return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string)$html_block)));
    };

    $diff_blocks = array();
    $max_check = max(count($before_blocks), count($after_blocks));
    for ($i = 0; $i < $max_check; $i++) {
        $b = isset($before_blocks[$i]) ? $get_block_text($before_blocks[$i]) : '';
        $a = isset($after_blocks[$i]) ? $get_block_text($after_blocks[$i]) : '';
        if ($b !== $a) {
            if ($b !== '') $diff_blocks[] = array('side' => 'before', 'text' => $b);
            if ($a !== '') $diff_blocks[] = array('side' => 'after', 'text' => $a);
            if (count($diff_blocks) >= 6) break;
        }
    }

    if (!empty($diff_blocks)) {
        $pairs = array();
        for ($i = 0; $i < count($diff_blocks); $i += 2) {
            $old = isset($diff_blocks[$i]) && $diff_blocks[$i]['side'] === 'before' ? $diff_blocks[$i]['text'] : '';
            $new = isset($diff_blocks[$i+1]) && $diff_blocks[$i+1]['side'] === 'after' ? $diff_blocks[$i+1]['text'] : '';
            if ($old === '' && $new === '') continue;
            $old_snip = mb_substr($old, 0, $max_chars);
            $new_snip = mb_substr($new, 0, $max_chars);
            $pairs[] = '旧："' . $old_snip . '" -> 新："' . $new_snip . '"';
        }
        return implode('； ', $pairs);
    }

    $before_words = preg_split('/\s+/u', $before_text_full);
    $after_words = preg_split('/\s+/u', $after_text_full);
    $bn = count($before_words);
    $an = count($after_words);
    $start = 0;
    while ($start < $bn && $start < $an && $before_words[$start] === $after_words[$start]) $start++;
    $end_b = $bn - 1;
    $end_a = $an - 1;
    while ($end_b >= $start && $end_a >= $start && $before_words[$end_b] === $after_words[$end_a]) { $end_b--; $end_a--; }
    if ($start <= $end_b || $start <= $end_a) {
        $s_context = max(0, $start - $context_words);
        $e_context_b = min($bn - 1, $end_b + $context_words);
        $e_context_a = min($an - 1, $end_a + $context_words);
        $old_snip_words = array_slice($before_words, $s_context, $e_context_b - $s_context + 1);
        $new_snip_words = array_slice($after_words, $s_context, $e_context_a - $s_context + 1);
        $old_snip = implode(' ', $old_snip_words);
        $new_snip = implode(' ', $new_snip_words);
        $old_snip = mb_substr($old_snip, 0, $max_chars);
        $new_snip = mb_substr($new_snip, 0, $max_chars);
        return '旧："' . $old_snip . '" -> 新："' . $new_snip . '"';
    }

    return '旧："' . mb_substr($before_text_full, 0, $max_chars) . '" -> 新："' . mb_substr($after_text_full, 0, $max_chars) . '"';
}

/* ---------------- 关键：保存/更新捕获 ---------------- */
/*
  我们以 save_post（优先） + post_updated（补充）作为捕获点：
  - save_post：兼容 REST/AJAX/Gutenberg/经典编辑器保存
  - post_updated：用于获得 WP 提供的两个 post 对象（旧/新）做字段级比较
*/

/* 保存时主处理（所有 post_type 或按配置过滤） */
add_action('save_post', 'oh_on_save_post_for_tracking', 20, 3);
function oh_on_save_post_for_tracking($post_ID, $post, $update) {
    global $OH_TRACKED_POST_TYPES;

    if (!oh_is_backend_user_context()) {
        oh_debug("save_post skipped: not backend context for post {$post_ID}");
        return;
    }

    if (!oh_is_valid_post_for_logging($post_ID)) {
        oh_debug("save_post skipped: invalid post for logging {$post_ID}");
        return;
    }

    // 如果限定了要跟踪的 post_type，则只跟踪那些类型
    if (!empty($OH_TRACKED_POST_TYPES) && is_array($OH_TRACKED_POST_TYPES)) {
        $pt = get_post_type($post_ID);
        if (!in_array($pt, $OH_TRACKED_POST_TYPES, true)) {
            oh_debug("save_post skip post type {$pt} for post {$post_ID}");
            return;
        }
    }

    oh_debug("save_post triggered for post {$post_ID}, update=" . ($update ? '1' : '0'));

    // 尝试获取旧 meta（优先快照）
    $old_meta_all = oh_get_and_clear_snapshot($post_ID);
    if ($old_meta_all === false) {
        $mem = oh_get_cached_meta($post_ID);
        if (!empty($mem)) {
            $old_meta_all = $mem;
        } else {
            $old_meta_all = oh_get_postmeta_raw_from_db($post_ID);
        }
    }

    $new_meta_all = get_post_meta($post_ID);
    $ignore_keys = oh_get_ignored_meta_keys();
    $all_keys = array_unique(array_merge(array_keys($old_meta_all), array_keys($new_meta_all)));
    $changes = array();

    foreach ($all_keys as $key) {
        if (in_array($key, $ignore_keys, true)) continue;

        $old_raw = isset($old_meta_all[$key]) ? $old_meta_all[$key] : array();
        $new_raw = isset($new_meta_all[$key]) ? $new_meta_all[$key] : array();
        $old_norm = oh_normalize_meta_value($old_raw);
        $new_norm = oh_normalize_meta_value($new_raw);

        if (is_array($old_norm) || is_array($new_norm)) {
            $old_ser = wp_json_encode(is_array($old_norm) ? $old_norm : array($old_norm));
            $new_ser = wp_json_encode(is_array($new_norm) ? $new_norm : array($new_norm));
        } else {
            $old_ser = $old_norm === null ? '' : (string)$old_norm;
            $new_ser = $new_norm === null ? '' : (string)$new_norm;
        }

        if ($old_ser === $new_ser) continue;

        $old_display_raw = is_array($old_norm) ? wp_json_encode($old_norm) : (string)$old_norm;
        $new_display_raw = is_array($new_norm) ? wp_json_encode($new_norm) : (string)$new_norm;

        if (mb_strlen(wp_strip_all_tags($old_display_raw)) > 200 || mb_strlen(wp_strip_all_tags($new_display_raw)) > 200) {
            $frag = oh_extract_changed_fragment($old_display_raw, $new_display_raw, 200);
            $changes[] = "meta:{$key}：" . $frag;
        } else {
            $old_disp = mb_substr(wp_strip_all_tags($old_display_raw), 0, 200);
            $new_disp = mb_substr(wp_strip_all_tags($new_display_raw), 0, 200);
            $changes[] = "meta:{$key}：旧=\"" . $old_disp . "\" -> 新=\"" . $new_disp . "\"";
        }
    }

    // 也比较基础字段：标题、内容、摘要、状态（如果需要）
    // 注意：在 save_post 环境下 get_post($post_ID) 通常返回保存后的对象；若需要旧的 post 字段请使用 post_updated 钩子（下方实现）

    $post_after = get_post($post_ID);
    if ($post_after) {
        // 标题（这里只做简单比较，post_updated 钩子会提供旧对象比较）
        // 我们仍记录标题发生变化（通过旧 meta 快照无法获取旧标题，这里只作为补充）
        // 若需要精确比较标题变化请参考下面的 post_updated 钩子（会在 two-arg 提供旧/新）
    }

    if (!empty($changes)) {
        $extra_info = "类型：{$post_after->post_type}，状态：{$post_after->post_status}，标题：{$post_after->post_title}";
        $changes_text = implode('； ', $changes);
        // 使用 force=true 以确保在 REST/AJAX 等上下文也能写入
        oh_log('game_updated', 'post', $post_ID, $extra_info, mb_substr($changes_text, 0, 1900), true);
        oh_debug("Logged changes for post {$post_ID}");
    } else {
        oh_debug("No meta changes detected for post {$post_ID}");
    }
}

/* 补充：post_updated 用于对比 WP 提供的 old/new post 对象（字段级差异） */
add_action('post_updated', 'oh_on_post_updated_detail', 10, 3);
function oh_on_post_updated_detail($post_ID, $post_after, $post_before) {
    global $OH_TRACKED_POST_TYPES;
    if (!oh_is_backend_user_context()) {
        oh_debug("post_updated skipped: not backend context for post {$post_ID}");
        return;
    }
    if (!oh_is_valid_post_for_logging($post_ID)) {
        oh_debug("post_updated skipped: invalid post for logging {$post_ID}");
        return;
    }
    if (!empty($OH_TRACKED_POST_TYPES) && is_array($OH_TRACKED_POST_TYPES)) {
        $pt = $post_after->post_type;
        if (!in_array($pt, $OH_TRACKED_POST_TYPES, true)) {
            oh_debug("post_updated skip post type {$pt} for post {$post_ID}");
            return;
        }
    }

    $changes = array();
    $format_short = function($v, $len = 120) {
        $s = wp_strip_all_tags((string)$v);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_substr($s, 0, $len);
    };

    if (isset($post_before->post_title) && $post_before->post_title !== $post_after->post_title) {
        $changes[] = '标题：旧="' . $format_short($post_before->post_title, 60) . '" -> 新="' . $format_short($post_after->post_title, 60) . '"';
    }
    if (isset($post_before->post_status) && $post_before->post_status !== $post_after->post_status) {
        $changes[] = '状态：' . $post_before->post_status . ' -> ' . $post_after->post_status;
    }
    if (isset($post_before->post_excerpt) && $post_before->post_excerpt !== $post_after->post_excerpt) {
        $frag = oh_extract_changed_fragment($post_before->post_excerpt, $post_after->post_excerpt, 180);
        if ($frag === '') $frag = $format_short($post_after->post_excerpt, 120);
        $changes[] = '摘要：' . $frag;
    }
    if (isset($post_before->post_content) && $post_before->post_content !== $post_after->post_content) {
        $frag = oh_extract_changed_fragment($post_before->post_content, $post_after->post_content, 300);
        if ($frag === '') $frag = $format_short($post_after->post_content, 200);
        $changes[] = '正文：' . $frag;
    }

    if (!empty($changes)) {
        $extra_info = "类型：{$post_after->post_type}，状态：{$post_after->post_status}，标题：{$post_after->post_title}";
        $changes_text = implode('； ', $changes);
        oh_log('post_updated', 'post', $post_ID, $extra_info, mb_substr($changes_text, 0, 1900), true);
        oh_debug("post_updated logged for {$post_ID}");
    } else {
        oh_debug("post_updated: no field changes for {$post_ID}");
    }
}

/* --- 其它事件（删除/附件/用户/主题/插件） --- */
add_action('delete_post', 'oh_on_delete_post');
function oh_on_delete_post($post_ID) {
    if (!oh_is_backend_user_context()) return;
    oh_log('post_deleted', 'post', $post_ID, '已删除', '', true);
}

add_action('add_attachment', 'oh_on_add_attachment');
function oh_on_add_attachment($post_ID) {
    if (!oh_is_backend_user_context()) return;
    oh_log('attachment_added', 'attachment', $post_ID, '已添加', '', true);
}

add_action('edit_attachment', 'oh_on_edit_attachment');
function oh_on_edit_attachment($post_ID) {
    if (!oh_is_backend_user_context()) return;
    oh_log('attachment_edited', 'attachment', $post_ID, '已编辑', '', true);
}

add_action('wp_login', 'oh_on_login', 10, 2);
function oh_on_login($user_login, $user) {
    $user_obj = get_user_by('login', $user_login);
    $uid = $user_obj ? $user_obj->ID : 0;
    $extra_info = '登录操作';
    if (!empty($_REQUEST['redirect_to'])) $extra_info .= '，跳转到：' . substr($_REQUEST['redirect_to'], 0, 300);
    oh_log('user_login', 'user', $uid, $extra_info, '', true);
}

add_action('wp_logout', 'oh_on_logout');
function oh_on_logout() {
    $user = wp_get_current_user();
    oh_log('user_logout', 'user', $user->ID, '登出操作', '', true);
}

add_action('user_register', 'oh_on_user_register');
function oh_on_user_register($user_id) {
    if (!oh_is_backend_user_context()) return;
    $u = get_userdata($user_id);
    oh_log('user_register', 'user', $user_id, '注册新用户：'.($u ? $u->user_login : "ID {$user_id}"), '', true);
}

add_action('profile_update', 'oh_on_profile_update', 10, 2);
function oh_on_profile_update($user_id, $old_user_data) {
    if (!oh_is_backend_user_context()) return;
    
    $u = get_userdata($user_id);
    $changes = array();
    $fmt = function($v) { return mb_substr(strip_tags((string)$v), 0, 200); };

    if ($old_user_data->user_email !== $u->user_email) {
        $changes[] = '邮箱：旧="' . $fmt($old_user_data->user_email) . '" -> 新="' . $fmt($u->user_email) . '"';
    }
    if ($old_user_data->display_name !== $u->display_name) {
        $changes[] = '显示名称：旧="' . $fmt($old_user_data->display_name) . '" -> 新="' . $fmt($u->display_name) . '"';
    }
    if ($old_user_data->user_nicename !== $u->user_nicename) {
        $changes[] = '昵称：旧="' . $fmt($old_user_data->user_nicename) . '" -> 新="' . $fmt($u->user_nicename) . '"';
    }
    if ($old_user_data->user_url !== $u->user_url) {
        $changes[] = '网站：旧="' . $fmt($old_user_data->user_url) . '" -> 新="' . $fmt($u->user_url) . '"';
    }
    
    $old_roles = isset($old_user_data->roles) ? (array)$old_user_data->roles : array();
    $new_roles = isset($u->roles) ? (array)$u->roles : array();
    if ($old_roles !== $new_roles) {
        $changes[] = '角色：旧="' . implode(',', $old_roles) . '" -> 新="' . implode(',', $new_roles) . '"';
    }
    
    if (!empty($changes)) {
        oh_log('profile_update', 'user', $user_id, '用户：' . $u->user_login, mb_substr(implode('； ', $changes), 0, 1900), true);
    }
}

add_action('switch_theme', 'oh_on_switch_theme');
function oh_on_switch_theme($new_name, $new_theme) {
    if (!oh_is_backend_user_context()) return;
    oh_log('theme_switched', 'theme', null, '新主题名称：' . $new_name, '', true);
}

add_action('activated_plugin', 'oh_plugin_activated');
function oh_plugin_activated($plugin) {
    if (!oh_is_backend_user_context()) return;
    oh_log('plugin_activated', 'plugin', null, '已激活插件：' . $plugin, '', true);
}

add_action('deactivated_plugin', 'oh_plugin_deactivated');
function oh_plugin_deactivated($plugin) {
    if (!oh_is_backend_user_context()) return;
    oh_log('plugin_deactivated', 'plugin', null, '已停用插件：' . $plugin, '', true);
}

/* --- 后台管理页（查看记录） --- */
add_action('admin_menu', 'oh_admin_menu');
function oh_admin_menu() {
    add_menu_page('操作历史', '操作历史', 'manage_options', 'operation-history', 'oh_admin_page');
}

function oh_admin_page() {
    if (!current_user_can('manage_options')) return;
    
    global $wpdb;
    $table = $wpdb->prefix . 'operation_history';
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $logs_per_page = 50;
    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $offset = ($paged - 1) * $logs_per_page;
    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $logs_per_page, $offset), ARRAY_A);
    $total_pages = max(1, ceil($total_logs / $logs_per_page));
    $page_links = paginate_links(array(
        'base' => add_query_arg('paged', '%#%'),
        'format' => '',
        'prev_text' => '&laquo; 上一页',
        'next_text' => '下一页 &raquo;',
        'total' => $total_pages,
        'current' => $paged
    ));

    echo '<div class="wrap"><h2>操作历史</h2>';
    
    if ($page_links) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
    }
    
    echo '<table class="widefat fixed">
            <thead>
                <tr>
                    <th width="50">ID</th>
                    <th width="160">时间</th>
                    <th>用户</th>
                    <th>操作</th>
                    <th>对象</th>
                    <th>详情</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>';
    
    if (empty($logs)) {
        echo '<tr><td colspan="7">没有记录。</td></tr>';
    } else {
        foreach ($logs as $r) {
            echo '<tr>';
            echo '<td>' . esc_html($r['id']) . '</td>';
            echo '<td>' . esc_html($r['time']) . '</td>';
            echo '<td>' . esc_html($r['user_login'] . ' (' . $r['user_id'] . ')') . '</td>';
            echo '<td>' . esc_html($r['action']) . '</td>';
            echo '<td>' . esc_html($r['object_type'] . '#' . $r['object_id']) . '</td>';
            echo '<td style="max-width:600px;word-break:break-word;">' . esc_html($r['details']) . '</td>';
            echo '<td>' . esc_html($r['ip']) . '</td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody>
        </table>';
    
    if ($page_links) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
    }
    
    echo '</div>';
}

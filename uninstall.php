<?php if (!defined('WP_UNINSTALL_PLUGIN')) { exit(); } global $wpdb; $table = $wpdb->prefix . 'operation_history'; $wpdb->query("DROP TABLE IF EXISTS {$table}"); 

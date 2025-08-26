=== Operation History (backend-users) ===
Contributors: yourname
Donate link: https://your-site.example/donate
Tags: activity-log,audit,logging,admin,history
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
记录后台操作历史（改进版）。支持 Gutenberg / REST / AJAX 保存，优先使用编辑页快照并记录详细的 post meta 变更与字段差异，便于追踪编辑历史、用户操作和插件/主题变动。

主要功能：

在编辑页面生成快照（供保存时使用），优先捕捉编辑器的旧数据，避免“旧值丢失”。
对 post meta、标题、状态、摘要、正文做详细差异提取（会保留长文本片段）。
捕获常见后台操作：文章/附件/用户/主题/插件 的增删改，登录/登出，用户资料更新等。
提供后台管理页面查看日志（需要 manage_options 权限）。
支持多种保存上下文（经典编辑器、Gutenberg、REST API、admin-ajax）。
== Installation ==

上传插件目录到 /wp-content/plugins/operation-history/，或通过后台上传 ZIP 并激活。
激活后插件会创建数据库表 wp_operation_history（基于站点表前缀）。
在 管理后台 -> 操作历史 查看记录（需要管理员权限）。
== Frequently Asked Questions ==
= 如何只跟踪特定 post type？ =
编辑插件文件顶部的 $OH_TRACKED_POST_TYPES 数组，添加或移除 post_type 名称。例如：array('my_game','post')。

= 卸载插件会删除记录吗？ =
插件包含卸载时会删除数据的逻辑（卸载时表会被删除）。如果你希望保留记录，请在卸载前备份数据库或注释掉卸载逻辑。

== Screenshots ==

后台 -> 操作历史 列表页面（示例截图）
== Changelog ==
= 2.3 =

改进：支持 Gutenberg/REST/AJAX 快照保存并改进 meta 比对算法。
改进：使用 WP 时间函数、改进 IP 检测、增加国际化。
修复：减少自动保存/修订误报并改进上下文判断。
== Upgrade Notice ==
= 2.3 =
升级时会在数据库创建操作历史表。升级前建议备份数据库。

== Privacy ==
本插件会在日志中记录：执行操作的用户 ID/用户名、操作时间、变更详情及 IP 地址（REMOTE_ADDR / X-Forwarded-For）。请确保在你的站点隐私策略中声明此行为并满足相关 GDPR/地区法规的要求。

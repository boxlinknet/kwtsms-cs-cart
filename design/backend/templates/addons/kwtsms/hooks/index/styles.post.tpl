{*
FILE:      design/backend/templates/addons/kwtsms/hooks/index/styles.post.tpl
CREATED:   2026-10-20 19:55 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Loads the kwtSMS admin stylesheet through the admin "index:styles" hook.
WHY:       PRD 7: add-on CSS is loaded by this hook; every selector in styles.less lives under a
           ".kwtsms-" root class, so nothing leaks into other admin pages.
USAGE:     Rendered by CS-Cart on every admin page while the add-on is active.
DEPENDS:   design/backend/css/addons/kwtsms/styles.less
*}
{style src="addons/kwtsms/styles.less"}
{* 2026-10-21 04:38 +03: the Templates page styles, Task 1.12, task 596 *}
{style src="addons/kwtsms/templates.less"}

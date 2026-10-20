{*
FILE:      design/backend/templates/addons/kwtsms/hooks/index/scripts.post.tpl
CREATED:   2026-10-20 19:55 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Loads the kwtSMS admin script through the admin "index:scripts" hook.
WHY:       PRD 7: add-on JavaScript is loaded by this hook. The script only wires elements that carry a
           "kwtsms-" class or data attribute and holds no secrets.
USAGE:     Rendered by CS-Cart on every admin page while the add-on is active.
DEPENDS:   js/addons/kwtsms/backend.js
*}
{script src="js/addons/kwtsms/backend.js"}

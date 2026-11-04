{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/hooks/index/login_buttons.post.tpl
 * CREATED:   2026-11-04 19:13 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      A link "Sign in with an SMS code" under the buttons of the storefront login form (hook index:login_buttons of
 *            views/auth/login_form.tpl), shown only while login_code is ON.
 * WHY:       Task M2.1 (SafatMall B2). The sign-in by code is a separate page; the form only points to it. The link is a
 *            plain anchor (the hook sits inside the login <form>, so no form of our own may be opened here) and carries
 *            the form's return address so the customer lands where they started.
 * USAGE:     Loaded by core as a hook template while the add-on is active. fn_kwtsms_login_code_on() reads Settings only.
 * DEPENDS:   func.php fn_kwtsms_login_code_on, the page kwtsms_auth.login, language variable kwtsms.login.link.
 *}
{* 2026-11-04 19:43 +03: Task M2.1 (e2e AA contrast, 3.29:1 for the theme's link colour), task 596: the link carries its own colour (6.4:1 on white) and an underline. *}
{if ""|fn_kwtsms_login_code_on}
    {* 2026-11-04 21:19 +03: review fix M4 of Task M2.1, task 596. fn_url builds the whole address, so the separator before return_url is right whatever the URL mode (it was appended with a hard-coded "&"). *}
    {$kwtsms_back = $smarty.request.return_url|default:$config.current_url|escape:"url"}
    <div class="ty-login-reglink ty-center kwtsms-login-link">
        <a class="ty-login-reglink__a" style="color: #1a56c4; text-decoration: underline;" href="{"kwtsms_auth.login?return_url=`$kwtsms_back`"|fn_url}" rel="nofollow">{__("kwtsms.login.link")|fn_html_escape}</a>
    </div>
{/if}

{*
 * FILE:      design/backend/templates/addons/kwtsms/views/kwtsms_vendor/phone.tpl
 * CREATED:   2026-11-06 18:34 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The vendor-panel page "Confirm SMS number": the state of the company's SMS number (confirmed, waiting for
 *            the code, changed, no phone, not a mobile number), a button that sends a code to the company phone and a
 *            form that takes the code.
 * WHY:       Task M2.6 (SafatMall F4, P3-56). Order SMS go to one confirmed number. The number is the company phone of
 *            the vendor (company profile); it is never typed here, so nobody can confirm a number they cannot reach, and
 *            the company is the one of the signed-in vendor administrator.
 * USAGE:     Rendered by controllers/backend/kwtsms_vendor.php (GET phone). Variable kwtsms_vendor_phone: status
 *            (none|approved|changed|no_phone|invalid), masked_ltr, can_send, approved_at.
 * DEPENDS:   Language variables kwtsms.vendorphone.*, common/mainbox.tpl. The security hash is added to the forms by
 *            the core output filter.
 *}
{$kwtsms_vp = $kwtsms_vendor_phone}
{capture name="mainbox"}
{* 2026-11-06 18:50 +03: Task M2.6 e2e finding (task 596): the page sits under .kwtsms-page and uses the add-on badges, so the AA colours of styles.less apply (the core .label is 2.85:1 and .muted 4.29:1). *}
<div class="kwtsms-page kwtsms-vendor-phone" id="kwtsms_vendor_phone">
    {if $kwtsms_vp.status == "approved"}
        <p id="kwtsms_vendor_confirmed" role="status"><span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.vendorphone.badge_confirmed")|fn_html_escape}</span>
            {* 2026-11-06 18:59 +03: Task M2.6 e2e finding (task 596): the masked number is its own bdi dir=ltr element, so +965****1234 keeps its order inside an Arabic sentence (the isolate characters of maskLtr() did not survive the placeholder in the Arabic picture). *}
            {* 2026-11-06 23:55 +03: M2.6-fix M5 (task 596): the masked number is escaped here and in the waiting line below. *}
            {__("kwtsms.vendorphone.state_confirmed")|fn_html_escape} <bdi dir="ltr" class="kwtsms-vendor-phone__number">{$kwtsms_vp.masked|fn_html_escape}</bdi></p>
        <p class="muted">{__("kwtsms.vendorphone.confirmed_hint")|fn_html_escape}</p>
    {elseif $kwtsms_vp.status == "no_phone"}
        <p id="kwtsms_vendor_no_phone" role="status"><span class="kwtsms-badge">{__("kwtsms.vendorphone.badge_waiting")|fn_html_escape}</span>
            {__("kwtsms.vendorphone.no_phone")|fn_html_escape}</p>
    {elseif $kwtsms_vp.status == "invalid" || !$kwtsms_vp.can_send}
        <p id="kwtsms_vendor_invalid" role="status"><span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.vendorphone.badge_waiting")|fn_html_escape}</span>
            {__("kwtsms.vendorphone.invalid")|fn_html_escape}</p>
    {else}
        <p id="kwtsms_vendor_state" role="status"><span class="kwtsms-badge{if $kwtsms_vp.status == "changed"} kwtsms-badge--danger{/if}">{__("kwtsms.vendorphone.badge_waiting")|fn_html_escape}</span>
            {if $kwtsms_vp.status == "changed"}{__("kwtsms.vendorphone.state_changed")|fn_html_escape}{else}{__("kwtsms.vendorphone.state_waiting")|fn_html_escape}{/if} <bdi dir="ltr" class="kwtsms-vendor-phone__number">{$kwtsms_vp.masked|fn_html_escape}</bdi></p>
        <p>{__("kwtsms.vendorphone.intro")|fn_html_escape}</p>
        <form action="{""|fn_url}" method="post" name="kwtsms_vendor_send" id="kwtsms_vendor_send" class="form-horizontal">
            <input type="submit" class="btn" id="kwtsms_vendor_send_button" name="dispatch[kwtsms_vendor.send_code]" value="{__("kwtsms.vendorphone.send")|fn_html_escape}">
        </form>
        <form action="{""|fn_url}" method="post" name="kwtsms_vendor_verify" id="kwtsms_vendor_verify" class="form-horizontal form-edit">
            <div class="control-group">
                <label for="kwtsms_vendor_code" class="control-label cm-required">{__("kwtsms.vendorphone.code_label")|fn_html_escape}:</label>
                <div class="controls">
                    <input type="text" id="kwtsms_vendor_code" name="kwtsms_code" size="12" maxlength="16" value="" autocomplete="one-time-code" inputmode="numeric" dir="ltr">
                </div>
            </div>
            <div class="control-group"><div class="controls">
                <input type="submit" class="btn btn-primary" id="kwtsms_vendor_confirm_button" name="dispatch[kwtsms_vendor.verify]" value="{__("kwtsms.vendorphone.confirm")|fn_html_escape}">
            </div></div>
        </form>
    {/if}
    <p><a id="kwtsms_vendor_profile_link" href="{"companies.update?company_id=`$auth.company_id`"|fn_url}">{__("kwtsms.vendorphone.edit_company")|fn_html_escape}</a></p>
</div>
{/capture}
{include file="common/mainbox.tpl" title=__("kwtsms.vendorphone.title") content=$smarty.capture.mainbox}

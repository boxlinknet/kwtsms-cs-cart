{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/navigation.tpl
CREATED:   2026-10-21 02:01 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Tabs between the kwtSMS admin pages, above the page content (native nav-tabs markup).
WHY:       PRD 7. The same sections also go into the core sidebar (navigation.dynamic.sections), which the 4.21 backend
           shows collapsed by default; tabs keep the pages one click apart at 1280 and 768 px. Shown once there are at
           least two pages the administrator may open (Task 1.10 builds only Settings).
USAGE:     {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}
           $sections: Admin\Navigation::sections() (title, href, active), already filtered by privilege.
DEPENDS:   Admin\Navigation, styles.less (.kwtsms-nav).
*}
{if $sections && $sections|count > 1}
    <nav class="kwtsms-nav tabs" aria-label="{__("kwtsms.nav.label")}">
        <ul class="nav nav-tabs">
            {foreach $sections as $section_id => $section}
                <li class="{if $section.active}active{/if}" id="kwtsms_nav_{$section_id}">
                    <a href="{$section.href|fn_url}"{if $section.active} aria-current="page"{/if}>{$section.title}</a>
                </li>
            {/foreach}
        </ul>
    </nav>
{/if}

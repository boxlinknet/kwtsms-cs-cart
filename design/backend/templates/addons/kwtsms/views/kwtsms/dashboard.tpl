{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/dashboard.tpl
CREATED:   2026-10-21 03:27 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The kwtSMS Dashboard: warnings, the test-mode card, the status figures (balance first), the setup and go-live
           checklists, the counters for today / 7 / 30 days (live and test), the Problems panel, credits by event and
           receiver, and the last 10 messages.
WHY:       PRD 6.9. Native backend components under the .kwtsms-page root. Everything is auto-escaped (no nofilter);
           the few {__()} calls with values pass numbers, dates and texts that DashboardPage already made safe. Each
           action button is its own small form (forms never nest) and posts kwtsms_return=dashboard so the controller
           brings the administrator back here. Filtered-log links use the Log\Schema filter names and open the Logs page
           (Task 1.14); a link is only drawn for an administrator who holds view_kwtsms_logs.
USAGE:     Rendered by kwtsms.dashboard (controllers/backend/kwtsms.php) with $kwtsms = Admin\DashboardPage::viewData()
           and $kwtsms_nav = Admin\Navigation::sections().
DEPENDS:   components/navigation.tpl, dashboard.less, kwtsms.dash.* and kwtsms.outcome.* language variables.
*}
{$date_time = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-dashboard" id="kwtsms_dashboard_page">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    {* ---- warnings ------------------------------------------------------------------------------------------------- *}
    {foreach $kwtsms.warnings as $w}
        {$warn_key = "kwtsms.dash.warn.`$w.id`"}
        {if $w.variant}{$warn_key = "`$warn_key`.`$w.variant`"}{/if}
        <div class="alert {if $w.level == "error"}alert-error{else}alert-warning{/if} kwtsms-alert" id="kwtsms_warn_{$w.id}" role="alert">
            <p>{__($warn_key, $w.params)}</p>
            {if $w.action && $kwtsms.can.gateway}
                <form action="{""|fn_url}" method="post" name="kwtsms_warn_{$w.id}_form" class="kwtsms-inline-form">
                    <input type="hidden" name="kwtsms_return" value="dashboard">
                    <input type="submit" class="btn" id="kwtsms_warn_{$w.id}_action" name="dispatch[kwtsms.{$w.action}]" value="{__("kwtsms.dash.action.`$w.action`")}">
                </form>
            {/if}
        </div>
    {/foreach}

    {* ---- test mode ------------------------------------------------------------------------------------------------ *}
    {if $kwtsms.test_mode || $kwtsms.test_card.messages > 0}
        <section class="kwtsms-testband{if !$kwtsms.test_mode} kwtsms-testband--held{/if}" id="kwtsms_test_mode_card" aria-labelledby="kwtsms_testband_title">
            <h4 id="kwtsms_testband_title">{if $kwtsms.test_mode}{__("kwtsms.dash.test.title")}{else}{__("kwtsms.dash.test.title_held")}{/if}</h4>
            {if $kwtsms.test_mode}
                <p id="kwtsms_test_mode_text">{if $kwtsms.enabled}{__("kwtsms.dash.test.text_enabled")}{else}{__("kwtsms.dash.test.text_disabled")}{/if}</p>
            {/if}
            <p id="kwtsms_test_held">
                {if $kwtsms.test_card.messages > 0}
                    {$held_date = $kwtsms.test_card.since|date_format:"`$settings.Appearance.date_format`"}
                    {__("kwtsms.dash.test.held", ["[count]" => $kwtsms.test_card.messages, "[date]" => $held_date, "[credits]" => $kwtsms.test_card.credits])}
                {else}
                    {__("kwtsms.dash.test.none")}
                {/if}
            </p>
            {if $kwtsms.test_mode && $kwtsms.can.gateway}
                <a class="btn" id="kwtsms_test_mode_off" href="{"kwtsms.settings"|fn_url}#kwtsms_test_mode">{__("kwtsms.dash.test.turn_off")}</a>
            {/if}
        </section>
    {/if}

    {* ---- status: balance first, then the ledger ------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_status" aria-labelledby="kwtsms_status_title">
        <h4 class="subheader" id="kwtsms_status_title">{__("kwtsms.dash.status.title")}</h4>
        <div class="kwtsms-overview">
            <div class="kwtsms-balance-card" id="kwtsms_balance_card">
                <span class="kwtsms-label">{__("kwtsms.dash.card.balance")}</span>
                {if $kwtsms.balance}
                    <p class="kwtsms-balance-card__number"><bdi id="kwtsms_balance_value">{$kwtsms.balance.available}</bdi> <span>{__("kwtsms.dash.balance.available")}</span></p>
                    <p class="muted kwtsms-balance-card__meta">
                        {if $kwtsms.balance.purchased !== ""}{__("kwtsms.dash.balance.purchased", ["[count]" => $kwtsms.balance.purchased])}. {/if}{__("kwtsms.settings.checked_at")}: <bdi>{$kwtsms.balance.synced_at|date_format:$date_time}</bdi>
                    </p>
                    <p class="kwtsms-balance-card__days" id="kwtsms_days_left">
                        {if $kwtsms.days_left === null}{__("kwtsms.dash.balance.days_none")}
                        {elseif $kwtsms.days_left == 0}{__("kwtsms.dash.balance.days_less")}
                        {elseif $kwtsms.days_left > 365}{__("kwtsms.dash.balance.days_year")}
                        {else}{__("kwtsms.dash.balance.days", ["[days]" => $kwtsms.days_left])}{/if}
                    </p>
                {else}
                    <p class="muted" id="kwtsms_balance_none">{__("kwtsms.dash.balance.none")}</p>
                {/if}
                <div class="kwtsms-actions">
                    <a class="btn" id="kwtsms_recharge" href="{$kwtsms.urls.recharge}" target="_blank" rel="noopener noreferrer">{__("kwtsms.dash.balance.recharge")}</a>
                    {if $kwtsms.can.gateway && $kwtsms.connected}
                        <form action="{""|fn_url}" method="post" name="kwtsms_refresh_form" class="kwtsms-inline-form">
                            <input type="hidden" name="kwtsms_return" value="dashboard">
                            <input type="submit" class="btn" id="kwtsms_refresh_balance" name="dispatch[kwtsms.refresh_balance]" value="{__("kwtsms.settings.refresh_balance")}">
                        </form>
                    {/if}
                </div>
            </div>

            <dl class="kwtsms-ledger" aria-label="{__("kwtsms.dash.status.title")}">
                <div class="kwtsms-ledger__item" id="kwtsms_card_enabled">
                    <dt>{__("kwtsms.dash.card.enabled")}</dt>
                    <dd>{if $kwtsms.enabled}<span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.status.on")}</span>{else}<span class="kwtsms-badge">{__("kwtsms.status.off")}</span>{/if}</dd>
                </div>
                <div class="kwtsms-ledger__item" id="kwtsms_card_connected">
                    <dt>{__("kwtsms.dash.card.connected")}</dt>
                    <dd>
                        {if $kwtsms.connection.state == "ok"}<span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.status.connected")}</span>
                        {elseif $kwtsms.connection.state == "paused"}<span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.status.paused")}</span>
                        {elseif $kwtsms.connection.state == "error"}<span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.dash.connection.problem")}</span>
                        {else}<span class="kwtsms-badge">{__("kwtsms.status.not_connected")}</span>{/if}
                        {if $kwtsms.connection.text}<span class="kwtsms-ledger__note" id="kwtsms_connection_text">{$kwtsms.connection.text}</span>{/if}
                    </dd>
                </div>
                <div class="kwtsms-ledger__item" id="kwtsms_card_test_mode">
                    <dt>{__("kwtsms.dash.card.test_mode")}</dt>
                    <dd>{if $kwtsms.test_mode}<span class="kwtsms-badge kwtsms-badge--test">{__("kwtsms.status.on")}</span>{else}<span class="kwtsms-badge">{__("kwtsms.status.off")}</span>{/if}</dd>
                </div>
                <div class="kwtsms-ledger__item" id="kwtsms_card_pilot_mode">
                    <dt>{__("kwtsms.dash.card.pilot_mode")}</dt>
                    <dd>{if $kwtsms.pilot_mode}<span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.status.on")}</span>{else}<span class="kwtsms-badge">{__("kwtsms.status.off")}</span>{/if}</dd>
                </div>
                <div class="kwtsms-ledger__item" id="kwtsms_card_sender">
                    <dt>{__("kwtsms.dash.card.sender")}</dt>
                    <dd>
                        {if $kwtsms.sender.id}
                            <bdi dir="ltr" class="kwtsms-ledger__value">{$kwtsms.sender.id}</bdi>
                            <span class="kwtsms-ledger__note">{if $kwtsms.sender.is_test_sender}{__("kwtsms.dash.sender.test_sender")}{elseif $kwtsms.sender.type == "promotional"}{__("kwtsms.dash.sender_type.promotional")}{else}{__("kwtsms.dash.sender_type.transactional")}{/if}</span>
                        {else}
                            <span class="muted">{__("kwtsms.dash.sender.none")}</span>
                        {/if}
                    </dd>
                </div>
                <div class="kwtsms-ledger__item" id="kwtsms_card_last_sync">
                    <dt>{__("kwtsms.dash.card.last_sync")}</dt>
                    <dd>
                        <bdi>{if $kwtsms.last_sync}{$kwtsms.last_sync|date_format:$date_time}{else}{__("kwtsms.settings.never")}{/if}</bdi>
                        {if $kwtsms.can.gateway && $kwtsms.connected}
                            <form action="{""|fn_url}" method="post" name="kwtsms_sync_form" class="kwtsms-inline-form">
                                <input type="hidden" name="kwtsms_return" value="dashboard">
                                <input type="submit" class="btn btn-mini" id="kwtsms_sync_now" name="dispatch[kwtsms.sync]" value="{__("kwtsms.settings.sync")}">
                            </form>
                        {/if}
                    </dd>
                </div>
                <div class="kwtsms-ledger__item" id="kwtsms_card_cron">
                    <dt>{__("kwtsms.dash.card.cron")}</dt>
                    <dd>
                        {if $kwtsms.cron.last}<bdi>{$kwtsms.cron.last|date_format:$date_time}</bdi>{else}<span>{__("kwtsms.settings.never")}</span>{/if}
                        <span class="kwtsms-badge{if $kwtsms.cron.state == "stale" || $kwtsms.cron.state == "never"} kwtsms-badge--danger{elseif $kwtsms.cron.state == "ok"} kwtsms-badge--ok{/if}" data-kwtsms-cron="{$kwtsms.cron.state}">{__("kwtsms.dash.cron.`$kwtsms.cron.state`")}</span>
                    </dd>
                </div>
                <div class="kwtsms-ledger__item" id="kwtsms_card_outbox">
                    <dt>{__("kwtsms.dash.card.outbox")}</dt>
                    <dd>
                        {if $kwtsms.outbox.waiting > 0}
                            <span class="kwtsms-ledger__value">{__("kwtsms.dash.outbox.waiting", ["[count]" => $kwtsms.outbox.waiting])}</span>
                            <span class="kwtsms-ledger__note">{__("kwtsms.dash.outbox.oldest", ["[minutes]" => $kwtsms.outbox.oldest_minutes])}</span>
                        {else}
                            <span class="kwtsms-ledger__value">{__("kwtsms.dash.outbox.empty")}</span>
                        {/if}
                        {if $kwtsms.outbox.held > 0}<span class="kwtsms-ledger__note">{__("kwtsms.dash.outbox.held", ["[count]" => $kwtsms.outbox.held])}</span>{/if}
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    {* ---- checklists ------------------------------------------------------------------------------------------------ *}
    <div class="kwtsms-columns">
        <section class="kwtsms-section kwtsms-panel" id="kwtsms_setup" aria-labelledby="kwtsms_setup_title">
            <h4 class="subheader" id="kwtsms_setup_title">{__("kwtsms.dash.setup.title")}</h4>
            {if $kwtsms.setup.complete}
                <p class="muted" id="kwtsms_setup_complete">{__("kwtsms.dash.setup.complete")}</p>
            {else}
                <p class="muted">{__("kwtsms.dash.setup.intro")}</p>
                <ol class="kwtsms-steps" id="kwtsms_setup_steps">
                    {foreach $kwtsms.setup.steps as $step}
                        <li class="kwtsms-step kwtsms-step--{$step.state}" id="kwtsms_setup_{$step.id}" data-kwtsms-state="{$step.state}">
                            <span class="kwtsms-step__mark" aria-hidden="true"></span>
                            <span class="kwtsms-step__body">
                                <span class="kwtsms-step__label">{__("kwtsms.dash.setup.`$step.id`")}</span>
                                {if $step.value}<bdi class="kwtsms-step__value">{$step.value}</bdi>{/if}
                                {* 2026-10-21 05:16 +03: ruling R36, Task 1.11 fix round 1 item 3, task 596: only the merchant can
                                   judge these steps, so the merchant marks them done (a POST with the security hash). *}
                                {if $step.optional && $step.state != "done" && $kwtsms.can.manage}
                                    <form action="{""|fn_url}" method="post" name="kwtsms_mark_{$step.id}_form" class="kwtsms-inline-form kwtsms-step__mark-form">
                                        <input type="hidden" name="kwtsms_return" value="dashboard">
                                        <input type="hidden" name="kwtsms_step" value="{$step.id}">
                                        <input type="submit" class="btn btn-mini" id="kwtsms_mark_{$step.id}" name="dispatch[kwtsms.mark_step]" value="{__("kwtsms.dash.mark.button")}">
                                    </form>
                                {/if}
                            </span>
                            <span class="kwtsms-step__state">{__("kwtsms.dash.state.`$step.state`")}</span>
                            {if $step.href}
                                <a class="kwtsms-step__link" href="{$step.href|fn_url}{if $step.anchor}#{$step.anchor}{/if}">{__("kwtsms.dash.setup.`$step.id`.link")}</a>
                            {/if}
                        </li>
                    {/foreach}
                </ol>
            {/if}
        </section>

        <section class="kwtsms-section kwtsms-panel" id="kwtsms_golive" aria-labelledby="kwtsms_golive_title">
            <h4 class="subheader" id="kwtsms_golive_title">{__("kwtsms.dash.golive.title")}</h4>
            <p class="muted">{__("kwtsms.dash.golive.intro")}</p>
            <ul class="kwtsms-steps kwtsms-steps--plain" id="kwtsms_golive_steps">
                {foreach $kwtsms.golive.steps as $step}
                    <li class="kwtsms-step kwtsms-step--{$step.state}" id="kwtsms_golive_{$step.id}" data-kwtsms-state="{$step.state}">
                        <span class="kwtsms-step__mark" aria-hidden="true"></span>
                        <span class="kwtsms-step__body">
                            <span class="kwtsms-step__label">{__("kwtsms.dash.golive.`$step.id`")}</span>
                            {if $step.value}<bdi class="kwtsms-step__value" dir="ltr">{$step.value}</bdi>{/if}
                        </span>
                        <span class="kwtsms-step__state">{__("kwtsms.dash.state.`$step.state`")}</span>
                        {if $step.href}
                            <a class="kwtsms-step__link" href="{if $step.external}{$step.href}{else}{$step.href|fn_url}{if $step.anchor}#{$step.anchor}{/if}{/if}"{if $step.external} target="_blank" rel="noopener noreferrer"{/if}>{__("kwtsms.dash.golive.`$step.id`.link")}</a>
                        {/if}
                    </li>
                {/foreach}
            </ul>
        </section>
    </div>

    {* ---- counters --------------------------------------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_counters_section" aria-labelledby="kwtsms_counters_title">
        <h4 class="subheader" id="kwtsms_counters_title">{__("kwtsms.dash.counters.title")}</h4>
        <div class="kwtsms-table-wrap">
            <table class="table table-middle kwtsms-table" id="kwtsms_counters">
                <thead>
                    <tr>
                        <th scope="col" rowspan="2">{__("kwtsms.dash.counters.rows")}</th>
                        <th scope="colgroup" colspan="3" class="kwtsms-table__group">{__("kwtsms.dash.counters.live")}</th>
                        <th scope="colgroup" colspan="3" class="kwtsms-table__group kwtsms-table__group--test">{__("kwtsms.dash.counters.test")}</th>
                    </tr>
                    <tr>
                        {foreach ["live", "test"] as $mode}
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.counters.today")}</th>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.counters.week")}</th>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.counters.month")}</th>
                        {/foreach}
                    </tr>
                </thead>
                <tbody>
                    {foreach $kwtsms.counters.rows as $row}
                        <tr data-kwtsms-group="{$row.group}">
                            <th scope="row"{if $row.group == "accepted"} title="{__("kwtsms.dash.group.accepted_tip")}"{/if}>{__("kwtsms.dash.group.`$row.group`")}</th>
                            <td class="kwtsms-table__num" data-kwtsms-cell="live.today.{$row.group}">{$row.live_today}</td>
                            <td class="kwtsms-table__num" data-kwtsms-cell="live.week.{$row.group}">{$row.live_week}</td>
                            <td class="kwtsms-table__num" data-kwtsms-cell="live.month.{$row.group}">{$row.live_month}</td>
                            <td class="kwtsms-table__num kwtsms-table__num--test" data-kwtsms-cell="test.today.{$row.group}">{$row.test_today}</td>
                            <td class="kwtsms-table__num kwtsms-table__num--test" data-kwtsms-cell="test.week.{$row.group}">{$row.test_week}</td>
                            <td class="kwtsms-table__num kwtsms-table__num--test" data-kwtsms-cell="test.month.{$row.group}">{$row.test_month}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        <p class="muted kwtsms-section__note">{__("kwtsms.dash.counters.note")}</p>
    </section>

    {* ---- problems --------------------------------------------------------------------------------------------------- *}
    {$p = $kwtsms.problems}
    <section class="kwtsms-section" id="kwtsms_problems" aria-labelledby="kwtsms_problems_title">
        <h4 class="subheader" id="kwtsms_problems_title">{__("kwtsms.dash.problems.title")}</h4>
        {if !$p.outcomes && !$p.codes && !$p.countries}
            <p class="muted" id="kwtsms_problems_none">{__("kwtsms.dash.problems.none")}</p>
        {else}
            <div class="kwtsms-problems">
                {if $p.outcomes}
                    <div class="kwtsms-problems__col kwtsms-problems__col--outcomes" id="kwtsms_problems_outcomes">
                        <h5>{__("kwtsms.dash.problems.outcomes")}</h5>
                        <ul class="kwtsms-plist">
                            {foreach $p.outcomes as $o}
                                <li data-kwtsms-outcome="{$o.outcome}">
                                    <bdi class="kwtsms-plist__n">{$o.count}</bdi>
                                    <span class="kwtsms-plist__text">{__("kwtsms.outcome.`$o.outcome`")}</span>
                                    {if $o.href}<a class="kwtsms-plist__link" href="{$o.href|fn_url}">{__("kwtsms.dash.problems.view")}</a>{/if}
                                </li>
                            {/foreach}
                        </ul>
                    </div>
                {/if}
                {if $p.codes}
                    <div class="kwtsms-problems__col" id="kwtsms_problems_codes">
                        <h5>{__("kwtsms.dash.problems.codes")}</h5>
                        <ul class="kwtsms-plist">
                            {foreach $p.codes as $c}
                                <li data-kwtsms-code="{$c.code}">
                                    <bdi class="kwtsms-plist__n">{$c.count}</bdi>
                                    <span class="kwtsms-plist__text"><bdi dir="ltr" class="kwtsms-code-tag">{$c.code}</bdi> {$c.text}</span>
                                    {if $c.href}<a class="kwtsms-plist__link" href="{$c.href|fn_url}">{__("kwtsms.dash.problems.view")}</a>{/if}
                                </li>
                            {/foreach}
                        </ul>
                    </div>
                {/if}
                {if $p.countries}
                    <div class="kwtsms-problems__col" id="kwtsms_problems_countries">
                        <h5>{__("kwtsms.dash.problems.countries")}</h5>
                        <ul class="kwtsms-plist">
                            {foreach $p.countries as $country}
                                <li data-kwtsms-country="{$country.code}">
                                    <bdi class="kwtsms-plist__n">{$country.count}</bdi>
                                    <span class="kwtsms-plist__text">{if $country.label}{$country.label}{else}{__("kwtsms.dash.problems.unknown_country")}{/if}</span>
                                    {if $p.countries_href}<a class="kwtsms-plist__link" href="{$p.countries_href|fn_url}">{__("kwtsms.dash.problems.view")}</a>{/if}
                                </li>
                            {/foreach}
                        </ul>
                        <p class="muted kwtsms-section__note" id="kwtsms_fraud_note">{__("kwtsms.dash.problems.fraud")}</p>
                        <a class="btn" id="kwtsms_request_coverage" href="{$kwtsms.urls.coverage}" target="_blank" rel="noopener noreferrer">{__("kwtsms.dash.problems.coverage")}</a>
                    </div>
                {/if}
            </div>
        {/if}
    </section>

    {* ---- credits by event and receiver ------------------------------------------------------------------------------ *}
    <section class="kwtsms-section" id="kwtsms_credits_section" aria-labelledby="kwtsms_credits_title">
        <h4 class="subheader" id="kwtsms_credits_title">{__("kwtsms.dash.credits.title")}</h4>
        {if !$kwtsms.credits_by_event}
            <p class="muted" id="kwtsms_credits_none">{__("kwtsms.dash.credits.none")}</p>
        {else}
            <div class="kwtsms-table-wrap">
                <table class="table table-middle kwtsms-table" id="kwtsms_credits">
                    <thead>
                        <tr>
                            <th scope="col" rowspan="2">{__("kwtsms.dash.credits.event")}</th>
                            <th scope="col" rowspan="2">{__("kwtsms.dash.credits.receiver")}</th>
                            <th scope="colgroup" colspan="2" class="kwtsms-table__group">{__("kwtsms.dash.credits.this_month")}: <bdi>{$kwtsms.month_start|date_format:"%b %Y"}</bdi></th>
                            <th scope="colgroup" colspan="2" class="kwtsms-table__group">{__("kwtsms.dash.credits.last_month")}: <bdi>{$kwtsms.last_month_start|date_format:"%b %Y"}</bdi></th>
                        </tr>
                        <tr>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.counters.live")}</th>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.counters.test")}</th>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.counters.live")}</th>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.counters.test")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $kwtsms.credits_by_event as $c}
                            <tr>
                                <td>{if $c.event_label}{$c.event_label}{else}<bdi dir="ltr">{$c.event_id}</bdi>{/if}</td>
                                <td>{$c.receiver_label}</td>
                                <td class="kwtsms-table__num">{$c.this_live}</td>
                                <td class="kwtsms-table__num kwtsms-table__num--test">{$c.this_test}</td>
                                <td class="kwtsms-table__num">{$c.last_live}</td>
                                <td class="kwtsms-table__num kwtsms-table__num--test">{$c.last_test}</td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {/if}
    </section>

    {* ---- last messages ---------------------------------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_last_section" aria-labelledby="kwtsms_last_title">
        <h4 class="subheader" id="kwtsms_last_title">{__("kwtsms.dash.last.title")}</h4>
        {if !$kwtsms.last_messages}
            <p class="muted" id="kwtsms_last_none">{__("kwtsms.dash.last.none")}</p>
        {else}
            <div class="kwtsms-table-wrap">
                <table class="table table-middle kwtsms-table" id="kwtsms_last_messages">
                    <thead>
                        <tr>
                            <th scope="col">{__("kwtsms.dash.last.time")}</th>
                            <th scope="col">{__("kwtsms.dash.last.event")}</th>
                            <th scope="col">{__("kwtsms.dash.last.to")}</th>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.last.recipients")}</th>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.dash.last.credits")}</th>
                            <th scope="col">{__("kwtsms.dash.last.status")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $kwtsms.last_messages as $m}
                            <tr data-kwtsms-message="{$m.message_id}">
                                <td><bdi>{$m.created_at|date_format:$date_time}</bdi></td>
                                <td>{if $m.event_label}{$m.event_label}{else}<bdi dir="ltr">{$m.event_id}</bdi>{/if}</td>
                                <td>{$m.receiver_label}</td>
                                <td class="kwtsms-table__num">{$m.recipients}</td>
                                <td class="kwtsms-table__num">{$m.credits}</td>
                                <td{if $m.status == "accepted"} title="{__("kwtsms.dash.group.accepted_tip")}"{/if}>
                                    {$m.status_label}
                                    {if $m.is_test} <span class="kwtsms-badge kwtsms-badge--test">{__("kwtsms.dash.last.test")}</span>{/if}
                                    {if $m.api_code} <bdi dir="ltr" class="kwtsms-code-tag">{$m.api_code}</bdi>{/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
            {if $kwtsms.logs_url}
                <p><a class="btn" id="kwtsms_open_log" href="{$kwtsms.logs_url|fn_url}">{__("kwtsms.dash.last.all")}</a></p>
            {/if}
        {/if}
    </section>
</div>
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.dash.title")
    content=$smarty.capture.mainbox
    select_storefront=false
}

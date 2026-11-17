<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Upgrade/Shipped130Terms.php
 * CREATED:   2026-11-17 07:50 +03
 * TASK:      596 M3.13 round 3
 * AUTHOR:    kwtSMS
 * WHAT:      The 1.3.0 Arabic text of every language variable whose Arabic value 1.4.0 rewrote to the kwtSMS glossary
 *            terms (Sender ID is "ism almursil", promotional is "i'lani"; never the banned "m'arrif almursil" or
 *            "tarwiji"), keyed name => lang => text. The English value is the current one (unchanged), so only
 *            Arabic is refreshed.
 * WHY:       INSERT IGNORE keeps the stored value of an upgraded store, so without this list an upgrade from 1.3.0
 *            would keep showing the banned term. PostUpgrade140::shipped130() merges it into SHIPPED_1_3_0 for
 *            PostRefresh::refreshUneditedValues(): a text the merchant edited stays, an unedited one is replaced.
 * USAGE:     PostUpgrade140::shipped130() (required once); generated from git show v1.3.0:var/langs/ar/addons/kwtsms.po.
 * DEPENDS:   Nothing.
 */

// phpcs:disable Generic.Files.LineLength

return [
    'kwtsms.alert.otp_unavailable.reason.sender' => [
        'en' => 'the Sender ID is KWT-SMS and test mode is off',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{647}\u{648} K"
            . "WT-SMS \u{648}\u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}\u{62a}\u{628}\u{627}"
            . "\u{631} \u{645}\u{639}\u{637}\u{644}",
    ],
    'kwtsms.alert.sender_banned.text' => [
        'en' => 'kwtSMS banned the Sender ID [sender], so SMS are paused. Select another Sender ID in the kwtSMS settings.',
        'ar' => "\u{62d}\u{638}\u{631}\u{62a} kwtSMS \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}"
            . "\u{631}\u{633}\u{644} [sender]\u{60c} \u{644}\u{630}\u{644}\u{643} \u{62a}\u{648}\u{642}"
            . "\u{641} \u{625}\u{631}\u{633}\u{627}\u{644} \u{627}\u{644}\u{631}\u{633}\u{627}\u{626}"
            . "\u{644}. \u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}"
            . "\u{644} \u{622}\u{62e}\u{631} \u{645}\u{646} \u{625}\u{639}\u{62f}\u{627}\u{62f}\u{627}"
            . "\u{62a} kwtSMS.",
    ],
    'kwtsms.alert.sender_banned.title' => [
        'en' => 'kwtSMS banned your Sender ID',
        'ar' => "\u{62d}\u{638}\u{631}\u{62a} kwtSMS \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}"
            . "\u{631}\u{633}\u{644}",
    ],
    'kwtsms.alert.sender_removed.text' => [
        'en' => 'The Sender ID [sender] is no longer on your kwtSMS account, so SMS are paused. Select another Sender ID in the kwtSMS settings.',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} [sender] \u{644}"
            . "\u{645} \u{64a}\u{639}\u{62f} \u{645}\u{648}\u{62c}\u{648}\u{62f}\u{627} \u{641}\u{64a} "
            . "\u{62d}\u{633}\u{627}\u{628} kwtSMS\u{60c} \u{644}\u{630}\u{644}\u{643} \u{62a}\u{648}"
            . "\u{642}\u{641} \u{625}\u{631}\u{633}\u{627}\u{644} \u{627}\u{644}\u{631}\u{633}\u{627}"
            . "\u{626}\u{644}. \u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}"
            . "\u{633}\u{644} \u{622}\u{62e}\u{631} \u{645}\u{646} \u{625}\u{639}\u{62f}\u{627}\u{62f}"
            . "\u{627}\u{62a} kwtSMS.",
    ],
    'kwtsms.alert.sender_removed.title' => [
        'en' => 'Sender ID removed from your kwtSMS account',
        'ar' => "\u{62a}\u{645}\u{62a} \u{625}\u{632}\u{627}\u{644}\u{629} \u{645}\u{639}\u{631}\u{641} "
            . "\u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{645}\u{646} \u{62d}\u{633}\u{627}\u{628} k"
            . "wtSMS",
    ],
    'kwtsms.alert.storefront_sender_invalid.text' => [
        'en' => 'The Sender ID [sender] of storefront [storefront] is no longer on your kwtSMS account or was banned, so that storefront sends under the main Sender ID. Pick another Sender ID for it in the kwtSMS settings.',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} [sender] \u{627}"
            . "\u{644}\u{62e}\u{627}\u{635} \u{628}\u{648}\u{627}\u{62c}\u{647}\u{629} \u{627}\u{644}"
            . "\u{645}\u{62a}\u{62c}\u{631} [storefront] \u{644}\u{645} \u{64a}\u{639}\u{62f} \u{641}"
            . "\u{64a} \u{62d}\u{633}\u{627}\u{628} kwtSMS \u{623}\u{648} \u{62a}\u{645} \u{62d}\u{638}"
            . "\u{631}\u{647}\u{60c} \u{644}\u{630}\u{644}\u{643} \u{62a}\u{631}\u{633}\u{644} \u{647}"
            . "\u{630}\u{647} \u{627}\u{644}\u{648}\u{627}\u{62c}\u{647}\u{629} \u{628}\u{627}\u{633}"
            . "\u{62a}\u{62e}\u{62f}\u{627}\u{645} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}"
            . "\u{631}\u{633}\u{644} \u{627}\u{644}\u{631}\u{626}\u{64a}\u{633}\u{64a}. \u{627}\u{62e}"
            . "\u{62a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} \u{622}\u{62e}"
            . "\u{631} \u{644}\u{647}\u{627} \u{645}\u{646} \u{625}\u{639}\u{62f}\u{627}\u{62f}\u{627}"
            . "\u{62a} kwtSMS.",
    ],
    'kwtsms.alert.storefront_sender_invalid.title' => [
        'en' => 'A storefront Sender ID is not usable',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{627}\u{644}"
            . "\u{62e}\u{627}\u{635} \u{628}\u{648}\u{627}\u{62c}\u{647}\u{629} \u{645}\u{62a}\u{62c}"
            . "\u{631} \u{63a}\u{64a}\u{631} \u{635}\u{627}\u{644}\u{62d}",
    ],
    'kwtsms.dash.card.sender' => [
        'en' => 'Sender ID',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644}",
    ],
    'kwtsms.dash.golive.sender_ordered' => [
        'en' => 'Order a transactional Sender ID (about 5 working days)',
        'ar' => "\u{627}\u{637}\u{644}\u{628} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} "
            . "\u{644}\u{644}\u{645}\u{639}\u{627}\u{645}\u{644}\u{627}\u{62a} (\u{646}\u{62d}\u{648} 5"
            . " \u{623}\u{64a}\u{627}\u{645} \u{639}\u{645}\u{644})",
    ],
    'kwtsms.dash.sender_type.promotional' => [
        'en' => 'Promotional',
        'ar' => "\u{62a}\u{631}\u{648}\u{64a}\u{62c}\u{64a}",
    ],
    'kwtsms.dash.setup.sender' => [
        'en' => 'Choose a Sender ID',
        'ar' => "\u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644}",
    ],
    'kwtsms.dash.warn.sync_failing' => [
        'en' => 'The last kwtSMS sync failed. SMS keep going out with the saved balance and Sender IDs, and the add-on tries again by itself.',
        'ar' => "\u{641}\u{634}\u{644}\u{62a} \u{622}\u{62e}\u{631} \u{645}\u{632}\u{627}\u{645}\u{646}"
            . "\u{629} \u{645}\u{639} kwtSMS. \u{62a}\u{633}\u{62a}\u{645}\u{631} \u{627}\u{644}\u{631}"
            . "\u{633}\u{627}\u{626}\u{644} \u{628}\u{627}\u{644}\u{62e}\u{631}\u{648}\u{62c} \u{628}"
            . "\u{627}\u{644}\u{631}\u{635}\u{64a}\u{62f} \u{648}\u{645}\u{639}\u{631}\u{641}\u{627}"
            . "\u{62a} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{627}\u{644}\u{645}\u{62d}\u{641}"
            . "\u{648}\u{638}\u{629}\u{60c} \u{648}\u{62a}\u{639}\u{64a}\u{62f} \u{627}\u{644}\u{625}"
            . "\u{636}\u{627}\u{641}\u{629} \u{627}\u{644}\u{645}\u{62d}\u{627}\u{648}\u{644}\u{629} "
            . "\u{62a}\u{644}\u{642}\u{627}\u{626}\u{64a}\u{627}.",
    ],
    'kwtsms.err.ERR008' => [
        'en' => 'kwtSMS banned this Sender ID. Select another Sender ID in the settings.',
        'ar' => "\u{62d}\u{638}\u{631}\u{62a} kwtSMS \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}"
            . "\u{631}\u{633}\u{644} \u{647}\u{630}\u{627}. \u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}"
            . "\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} \u{622}\u{62e}\u{631} \u{645}\u{646} \u{627}"
            . "\u{644}\u{625}\u{639}\u{62f}\u{627}\u{62f}\u{627}\u{62a}.",
    ],
    'kwtsms.help.account.sender_p1' => [
        'en' => 'The Sender ID is the name customers see as the sender. kwtSMS gives every account KWT-SMS for testing only. It can delay messages and doesn\'t reach Virgin Kuwait numbers. Register your own Sender ID before you go live.',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{647}\u{648} "
            . "\u{627}\u{644}\u{627}\u{633}\u{645} \u{627}\u{644}\u{630}\u{64a} \u{64a}\u{631}\u{627}"
            . "\u{647} \u{627}\u{644}\u{639}\u{645}\u{64a}\u{644} \u{643}\u{645}\u{631}\u{633}\u{644}. "
            . "\u{64a}\u{645}\u{646}\u{62d} kwtSMS \u{643}\u{644} \u{62d}\u{633}\u{627}\u{628} \u{627}"
            . "\u{644}\u{645}\u{639}\u{631}\u{641} KWT-SMS \u{644}\u{644}\u{627}\u{62e}\u{62a}\u{628}"
            . "\u{627}\u{631} \u{641}\u{642}\u{637}. \u{642}\u{62f} \u{64a}\u{624}\u{62e}\u{631} "
            . "\u{627}\u{644}\u{631}\u{633}\u{627}\u{626}\u{644} \u{648}\u{644}\u{627} \u{64a}\u{635}"
            . "\u{644} \u{625}\u{644}\u{649} \u{623}\u{631}\u{642}\u{627}\u{645} Virgin \u{627}\u{644}"
            . "\u{643}\u{648}\u{64a}\u{62a}. \u{633}\u{62c}\u{651}\u{644} \u{645}\u{639}\u{631}\u{641} "
            . "\u{645}\u{631}\u{633}\u{644} \u{62e}\u{627}\u{635}\u{627} \u{628}\u{643} \u{642}\u{628}"
            . "\u{644} \u{623}\u{646} \u{62a}\u{628}\u{62f}\u{623} \u{627}\u{644}\u{639}\u{645}\u{644} "
            . "\u{627}\u{644}\u{641}\u{639}\u{644}\u{64a}.",
    ],
    'kwtsms.help.account.sender_p2' => [
        'en' => 'Use a transactional Sender ID for order SMS. kwtSMS registers a Sender ID in about 5 working days. The fees above were right when this page was written. The Sender ID help page has the current ones.',
        'ar' => "\u{627}\u{633}\u{62a}\u{62e}\u{62f}\u{645} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}"
            . "\u{633}\u{644} \u{62a}\u{634}\u{63a}\u{64a}\u{644}\u{64a}\u{627} \u{644}\u{631}\u{633}"
            . "\u{627}\u{626}\u{644} \u{627}\u{644}\u{637}\u{644}\u{628}\u{627}\u{62a}. \u{64a}\u{633}"
            . "\u{62a}\u{63a}\u{631}\u{642} \u{62a}\u{633}\u{62c}\u{64a}\u{644} \u{645}\u{639}\u{631}"
            . "\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{641}\u{64a} kwtSMS \u{646}\u{62d}"
            . "\u{648} 5 \u{623}\u{64a}\u{627}\u{645} \u{639}\u{645}\u{644}. \u{627}\u{644}\u{631}"
            . "\u{633}\u{648}\u{645} \u{623}\u{639}\u{644}\u{627}\u{647} \u{635}\u{62d}\u{64a}\u{62d}"
            . "\u{629} \u{648}\u{642}\u{62a} \u{643}\u{62a}\u{627}\u{628}\u{629} \u{647}\u{630}\u{647} "
            . "\u{627}\u{644}\u{635}\u{641}\u{62d}\u{629}\u{60c} \u{648}\u{62a}\u{62c}\u{62f} \u{627}"
            . "\u{644}\u{631}\u{633}\u{648}\u{645} \u{627}\u{644}\u{62d}\u{627}\u{644}\u{64a}\u{629} "
            . "\u{641}\u{64a} \u{635}\u{641}\u{62d}\u{629} \u{645}\u{633}\u{627}\u{639}\u{62f}\u{629} "
            . "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644}.",
    ],
    'kwtsms.help.account.sender_promotional' => [
        'en' => 'Promotional: for offers sent to many people. Messages to DND numbers are blocked, and the credits are still spent. One-time fee: 10 KD.',
        'ar' => "\u{62a}\u{631}\u{648}\u{64a}\u{62c}\u{64a}: \u{644}\u{644}\u{639}\u{631}\u{648}\u{636} "
            . "\u{627}\u{644}\u{62a}\u{64a} \u{62a}\u{64f}\u{631}\u{633}\u{644} \u{625}\u{644}\u{649} "
            . "\u{639}\u{62f}\u{62f} \u{643}\u{628}\u{64a}\u{631} \u{645}\u{646} \u{627}\u{644}\u{646}"
            . "\u{627}\u{633}. \u{62a}\u{64f}\u{62d}\u{62c}\u{628} \u{627}\u{644}\u{631}\u{633}\u{627}"
            . "\u{626}\u{644} \u{627}\u{644}\u{645}\u{648}\u{62c}\u{647}\u{629} \u{625}\u{644}\u{649} "
            . "\u{623}\u{631}\u{642}\u{627}\u{645} DND \u{648}\u{62a}\u{64f}\u{62e}\u{635}\u{645} "
            . "\u{627}\u{644}\u{646}\u{642}\u{627}\u{637} \u{645}\u{639} \u{630}\u{644}\u{643}. \u{631}"
            . "\u{633}\u{645} \u{644}\u{645}\u{631}\u{629} \u{648}\u{627}\u{62d}\u{62f}\u{629}: 10 "
            . "\u{62f}\u{646}\u{627}\u{646}\u{64a}\u{631}.",
    ],
    'kwtsms.help.account.sender_title' => [
        'en' => 'Sender ID',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644}",
    ],
    'kwtsms.help.codes.p4' => [
        'en' => 'Codes need a private transactional Sender ID. kwtSMS filters promotional Sender IDs for numbers on the Do Not Disturb (DND) list, and still charges for those messages. The shared KWT-SMS Sender ID is refused for codes unless test mode is on.',
        'ar' => "\u{62a}\u{62d}\u{62a}\u{627}\u{62c} \u{627}\u{644}\u{631}\u{645}\u{648}\u{632} \u{625}"
            . "\u{644}\u{649} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} \u{62a}\u{634}"
            . "\u{63a}\u{64a}\u{644}\u{64a} \u{62e}\u{627}\u{635}. \u{62a}\u{642}\u{648}\u{645} kwtSMS "
            . "\u{628}\u{62a}\u{635}\u{641}\u{64a}\u{629} \u{645}\u{639}\u{631}\u{641}\u{627}\u{62a} "
            . "\u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{627}\u{644}\u{62f}\u{639}\u{627}\u{626}"
            . "\u{64a}\u{629} \u{644}\u{644}\u{623}\u{631}\u{642}\u{627}\u{645} \u{627}\u{644}\u{645}"
            . "\u{633}\u{62c}\u{644}\u{629} \u{641}\u{64a} \u{642}\u{627}\u{626}\u{645}\u{629} \u{639}"
            . "\u{62f}\u{645} \u{627}\u{644}\u{625}\u{632}\u{639}\u{627}\u{62c} (DND)\u{60c} \u{648}"
            . "\u{62a}\u{62d}\u{627}\u{633}\u{628} \u{639}\u{644}\u{649} \u{62a}\u{644}\u{643} \u{627}"
            . "\u{644}\u{631}\u{633}\u{627}\u{626}\u{644} \u{645}\u{639} \u{630}\u{644}\u{643}. \u{64a}"
            . "\u{64f}\u{631}\u{641}\u{636} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644} \u{627}\u{644}\u{645}\u{634}\u{62a}\u{631}\u{643} KWT-SMS \u{644}\u{644}"
            . "\u{631}\u{645}\u{648}\u{632} \u{645}\u{627} \u{644}\u{645} \u{64a}\u{643}\u{646} \u{648}"
            . "\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}\u{62a}\u{628}\u{627}\u{631} \u{645}\u{641}"
            . "\u{639}\u{644}\u{627}.",
    ],
    'kwtsms.help.golive.g1' => [
        'en' => 'A transactional Sender ID is registered. It takes about 5 working days.',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{627}\u{644}"
            . "\u{62a}\u{634}\u{63a}\u{64a}\u{644}\u{64a} \u{645}\u{633}\u{62c}\u{651}\u{644}. \u{64a}"
            . "\u{633}\u{62a}\u{63a}\u{631}\u{642} \u{630}\u{644}\u{643} \u{646}\u{62d}\u{648} 5 "
            . "\u{623}\u{64a}\u{627}\u{645} \u{639}\u{645}\u{644}.",
    ],
    'kwtsms.help.links.sender_id' => [
        'en' => 'Sender ID help',
        'ar' => "\u{645}\u{633}\u{627}\u{639}\u{62f}\u{629} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}"
            . "\u{645}\u{631}\u{633}\u{644}",
    ],
    'kwtsms.help.missing.s5' => [
        'en' => 'Check Do Not Disturb. Some customers ask their network to block promotional messages. A promotional Sender ID, and KWT-SMS, can\'t reach them. A transactional Sender ID can.',
        'ar' => "\u{62a}\u{62d}\u{642}\u{642} \u{645}\u{646} \u{642}\u{627}\u{626}\u{645}\u{629} \u{639}"
            . "\u{62f}\u{645} \u{627}\u{644}\u{625}\u{632}\u{639}\u{627}\u{62c}. \u{628}\u{639}\u{636} "
            . "\u{627}\u{644}\u{639}\u{645}\u{644}\u{627}\u{621} \u{64a}\u{637}\u{644}\u{628}\u{648}"
            . "\u{646} \u{645}\u{646} \u{634}\u{628}\u{643}\u{62a}\u{647}\u{645} \u{62d}\u{62c}\u{628} "
            . "\u{627}\u{644}\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{62a}\u{631}\u{648}"
            . "\u{64a}\u{62c}\u{64a}\u{629}. \u{644}\u{627} \u{64a}\u{635}\u{644} \u{625}\u{644}\u{64a}"
            . "\u{647}\u{645} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} "
            . "\u{627}\u{644}\u{62a}\u{631}\u{648}\u{64a}\u{62c}\u{64a} \u{648}\u{644}\u{627} KWT-SMS"
            . "\u{60c} \u{628}\u{64a}\u{646}\u{645}\u{627} \u{64a}\u{635}\u{644} \u{625}\u{644}\u{64a}"
            . "\u{647}\u{645} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} "
            . "\u{627}\u{644}\u{62a}\u{634}\u{63a}\u{64a}\u{644}\u{64a}.",
    ],
    'kwtsms.help.missing.s6' => [
        'en' => 'Wait a few minutes. KWT-SMS is slow, especially on busy days. Give a message 3 to 5 minutes before you decide it is lost, and use your own Sender ID.',
        'ar' => "\u{627}\u{646}\u{62a}\u{638}\u{631} \u{628}\u{636}\u{639} \u{62f}\u{642}\u{627}\u{626}"
            . "\u{642}. KWT-SMS \u{628}\u{637}\u{64a}\u{621}\u{60c} \u{62e}\u{627}\u{635}\u{629} "
            . "\u{641}\u{64a} \u{627}\u{644}\u{623}\u{64a}\u{627}\u{645} \u{627}\u{644}\u{645}\u{632}"
            . "\u{62f}\u{62d}\u{645}\u{629}. \u{623}\u{645}\u{647}\u{644} \u{627}\u{644}\u{631}\u{633}"
            . "\u{627}\u{644}\u{629} \u{645}\u{646} 3 \u{625}\u{644}\u{649} 5 \u{62f}\u{642}\u{627}"
            . "\u{626}\u{642} \u{642}\u{628}\u{644} \u{623}\u{646} \u{62a}\u{62d}\u{643}\u{645} \u{628}"
            . "\u{636}\u{64a}\u{627}\u{639}\u{647}\u{627}\u{60c} \u{648}\u{627}\u{633}\u{62a}\u{62e}"
            . "\u{62f}\u{645} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} \u{62e}\u{627}"
            . "\u{635}\u{627} \u{628}\u{643}.",
    ],
    'kwtsms.help.setup.s2' => [
        'en' => 'Choose a Sender ID from the list. Don\'t keep KWT-SMS on a live store.',
        'ar' => "\u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} "
            . "\u{645}\u{646} \u{627}\u{644}\u{642}\u{627}\u{626}\u{645}\u{629}. \u{644}\u{627} \u{62a}"
            . "\u{64f}\u{628}\u{642}\u{650} KWT-SMS \u{641}\u{64a} \u{645}\u{62a}\u{62c}\u{631} \u{64a}"
            . "\u{639}\u{645}\u{644} \u{641}\u{639}\u{644}\u{64a}\u{627}.",
    ],
    'kwtsms.help.storefronts.p1' => [
        'en' => 'Each storefront can have its own Sender ID and its own texts. On Settings, pick a Sender ID for the storefront. "Same as the main Sender ID" follows the main one. On Templates, pick the storefront, then edit its text.',
        'ar' => "\u{64a}\u{645}\u{643}\u{646} \u{644}\u{643}\u{644} \u{648}\u{627}\u{62c}\u{647}\u{629} "
            . "\u{645}\u{62a}\u{62c}\u{631} \u{623}\u{646} \u{64a}\u{643}\u{648}\u{646} \u{644}\u{647}"
            . "\u{627} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} \u{648}\u{646}\u{635}"
            . "\u{648}\u{635} \u{62e}\u{627}\u{635}\u{629} \u{628}\u{647}\u{627}. \u{627}\u{62e}\u{62a}"
            . "\u{631} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{644}"
            . "\u{644}\u{648}\u{627}\u{62c}\u{647}\u{629} \u{645}\u{646} \u{635}\u{641}\u{62d}\u{629} "
            . "\u{627}\u{644}\u{625}\u{639}\u{62f}\u{627}\u{62f}\u{627}\u{62a}\u{60c} \u{648}\u{62e}"
            . "\u{64a}\u{627}\u{631} \"\u{645}\u{62b}\u{644} \u{645}\u{639}\u{631}\u{641} \u{627}"
            . "\u{644}\u{645}\u{631}\u{633}\u{644} \u{627}\u{644}\u{631}\u{626}\u{64a}\u{633}\u{64a}\" "
            . "\u{64a}\u{62a}\u{628}\u{639} \u{627}\u{644}\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}"
            . "\u{631}\u{626}\u{64a}\u{633}\u{64a}. \u{648}\u{641}\u{64a} \u{635}\u{641}\u{62d}\u{629} "
            . "\u{627}\u{644}\u{642}\u{648}\u{627}\u{644}\u{628} \u{627}\u{62e}\u{62a}\u{631} \u{627}"
            . "\u{644}\u{648}\u{627}\u{62c}\u{647}\u{629} \u{62b}\u{645} \u{639}\u{62f}\u{651}\u{644} "
            . "\u{646}\u{635}\u{647}\u{627}.",
    ],
    'kwtsms.help.storefronts.p4' => [
        'en' => 'If a storefront Sender ID leaves your synced list or is banned, that storefront uses the main Sender ID and you get an alert.',
        'ar' => "\u{625}\u{630}\u{627} \u{62e}\u{631}\u{62c} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}"
            . "\u{633}\u{644} \u{627}\u{644}\u{648}\u{627}\u{62c}\u{647}\u{629} \u{645}\u{646} \u{642}"
            . "\u{627}\u{626}\u{645}\u{62a}\u{643} \u{627}\u{644}\u{645}\u{62a}\u{632}\u{627}\u{645}"
            . "\u{646}\u{629} \u{623}\u{648} \u{62d}\u{64f}\u{638}\u{631}\u{60c} \u{62a}\u{633}\u{62a}"
            . "\u{62e}\u{62f}\u{645} \u{627}\u{644}\u{648}\u{627}\u{62c}\u{647}\u{629} \u{627}\u{644}"
            . "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{631}\u{626}\u{64a}\u{633}\u{64a} \u{648}"
            . "\u{62a}\u{635}\u{644}\u{643} \u{631}\u{633}\u{627}\u{644}\u{629} \u{62a}\u{646}\u{628}"
            . "\u{64a}\u{647}.",
    ],
    'kwtsms.help.support.p2' => [
        'en' => 'It holds what support asks for: your API username, the message ID, the Kuwait date and time, the Sender ID, the numbers, the text, the test flag, and the error code. It never holds your password.',
        'ar' => "\u{64a}\u{62d}\u{62a}\u{648}\u{64a} \u{639}\u{644}\u{649} \u{645}\u{627} \u{64a}\u{637}"
            . "\u{644}\u{628}\u{647} \u{627}\u{644}\u{62f}\u{639}\u{645}: \u{627}\u{633}\u{645} \u{645}"
            . "\u{633}\u{62a}\u{62e}\u{62f}\u{645} API \u{648}\u{645}\u{639}\u{631}\u{641} \u{627}"
            . "\u{644}\u{631}\u{633}\u{627}\u{644}\u{629} \u{648}\u{62a}\u{627}\u{631}\u{64a}\u{62e} "
            . "\u{648}\u{648}\u{642}\u{62a} \u{627}\u{644}\u{643}\u{648}\u{64a}\u{62a} \u{648}\u{645}"
            . "\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{648}\u{627}\u{644}"
            . "\u{623}\u{631}\u{642}\u{627}\u{645} \u{648}\u{627}\u{644}\u{646}\u{635} \u{648}\u{639}"
            . "\u{644}\u{627}\u{645}\u{629} \u{627}\u{644}\u{627}\u{62e}\u{62a}\u{628}\u{627}\u{631} "
            . "\u{648}\u{631}\u{645}\u{632} \u{627}\u{644}\u{62e}\u{637}\u{623}. \u{648}\u{644}\u{627} "
            . "\u{64a}\u{62d}\u{62a}\u{648}\u{64a} \u{623}\u{628}\u{62f}\u{627} \u{639}\u{644}\u{649} "
            . "\u{643}\u{644}\u{645}\u{629} \u{645}\u{631}\u{648}\u{631}\u{643}.",
    ],
    'kwtsms.help.test.p3' => [
        'en' => 'Turn test mode off on Settings when you are ready for real messages. If the Sender ID is still KWT-SMS, you must confirm first.',
        'ar' => "\u{623}\u{648}\u{642}\u{641} \u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}\u{62a}"
            . "\u{628}\u{627}\u{631} \u{645}\u{646} \u{635}\u{641}\u{62d}\u{629} \u{627}\u{644}\u{625}"
            . "\u{639}\u{62f}\u{627}\u{62f}\u{627}\u{62a} \u{639}\u{646}\u{62f}\u{645}\u{627} \u{62a}"
            . "\u{643}\u{648}\u{646} \u{62c}\u{627}\u{647}\u{632}\u{627} \u{644}\u{644}\u{631}\u{633}"
            . "\u{627}\u{626}\u{644} \u{627}\u{644}\u{641}\u{639}\u{644}\u{64a}\u{629}. \u{625}\u{630}"
            . "\u{627} \u{643}\u{627}\u{646} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644} \u{644}\u{627} \u{64a}\u{632}\u{627}\u{644} KWT-SMS \u{641}\u{639}\u{644}"
            . "\u{64a}\u{643} \u{627}\u{644}\u{62a}\u{623}\u{643}\u{64a}\u{62f} \u{623}\u{648}\u{644}"
            . "\u{627}.",
    ],
    'kwtsms.help.unreachable.p1' => [
        'en' => 'A code can\'t leave when sending is off, kwtSMS is unreachable or suspended, the balance is under 1 credit, pilot mode is on, the store daily cap is reached, or the Sender ID is KWT-SMS and test mode is off. The setting When codes cannot be sent decides what customers see.',
        'ar' => "\u{644}\u{627} \u{64a}\u{645}\u{643}\u{646} \u{625}\u{631}\u{633}\u{627}\u{644} \u{627}"
            . "\u{644}\u{631}\u{645}\u{632} \u{639}\u{646}\u{62f}\u{645}\u{627} \u{64a}\u{643}\u{648}"
            . "\u{646} \u{627}\u{644}\u{625}\u{631}\u{633}\u{627}\u{644} \u{645}\u{62a}\u{648}\u{642}"
            . "\u{641}\u{627}\u{60c} \u{623}\u{648} \u{64a}\u{62a}\u{639}\u{630}\u{631} \u{627}\u{644}"
            . "\u{648}\u{635}\u{648}\u{644} \u{625}\u{644}\u{649} kwtSMS \u{623}\u{648} \u{64a}\u{643}"
            . "\u{648}\u{646} \u{645}\u{648}\u{642}\u{648}\u{641}\u{627}\u{60c} \u{623}\u{648} \u{64a}"
            . "\u{642}\u{644} \u{627}\u{644}\u{631}\u{635}\u{64a}\u{62f} \u{639}\u{646} \u{631}\u{635}"
            . "\u{64a}\u{62f} \u{648}\u{627}\u{62d}\u{62f}\u{60c} \u{623}\u{648} \u{64a}\u{643}\u{648}"
            . "\u{646} \u{627}\u{644}\u{648}\u{636}\u{639} \u{627}\u{644}\u{62a}\u{62c}\u{631}\u{64a}"
            . "\u{628}\u{64a} \u{645}\u{641}\u{639}\u{644}\u{627}\u{60c} \u{623}\u{648} \u{628}\u{644}"
            . "\u{63a} \u{627}\u{644}\u{633}\u{642}\u{641} \u{627}\u{644}\u{64a}\u{648}\u{645}\u{64a} "
            . "\u{644}\u{644}\u{645}\u{62a}\u{62c}\u{631}\u{60c} \u{623}\u{648} \u{64a}\u{643}\u{648}"
            . "\u{646} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} KWT-SMS "
            . "\u{648}\u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}\u{62a}\u{628}\u{627}\u{631} "
            . "\u{645}\u{639}\u{637}\u{644}\u{627}. \u{64a}\u{62d}\u{62f}\u{62f} \u{62e}\u{64a}\u{627}"
            . "\u{631} \u{ab}\u{639}\u{646}\u{62f} \u{62a}\u{639}\u{630}\u{631} \u{625}\u{631}\u{633}"
            . "\u{627}\u{644} \u{627}\u{644}\u{631}\u{645}\u{648}\u{632}\u{bb} \u{645}\u{627} \u{64a}"
            . "\u{631}\u{627}\u{647} \u{627}\u{644}\u{639}\u{645}\u{644}\u{627}\u{621}.",
    ],
    'kwtsms.integrations.area.storefronts.text' => [
        'en' => 'Storefronts: [count]. With their own Sender ID: [own]. Switches, Sender ID, and texts can differ per storefront.',
        'ar' => "\u{648}\u{627}\u{62c}\u{647}\u{627}\u{62a} \u{627}\u{644}\u{645}\u{62a}\u{62c}\u{631}: ["
            . "count]. \u{627}\u{644}\u{62a}\u{64a} \u{644}\u{647}\u{627} \u{645}\u{639}\u{631}\u{641} "
            . "\u{645}\u{631}\u{633}\u{644} \u{62e}\u{627}\u{635}: [own]. \u{64a}\u{645}\u{643}\u{646} "
            . "\u{623}\u{646} \u{62a}\u{62e}\u{62a}\u{644}\u{641} \u{627}\u{644}\u{645}\u{641}\u{627}"
            . "\u{62a}\u{64a}\u{62d} \u{648}\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644} \u{648}\u{627}\u{644}\u{646}\u{635}\u{648}\u{635} \u{645}\u{646} \u{648}"
            . "\u{627}\u{62c}\u{647}\u{629} \u{625}\u{644}\u{649} \u{623}\u{62e}\u{631}\u{649}.",
    ],
    'kwtsms.order_sms.intro' => [
        'en' => 'Sends one SMS now, from the Sender ID of this order\'s storefront.',
        'ar' => "\u{62a}\u{631}\u{633}\u{644} \u{631}\u{633}\u{627}\u{644}\u{629} SMS \u{648}\u{627}"
            . "\u{62d}\u{62f}\u{629} \u{627}\u{644}\u{622}\u{646} \u{628}\u{627}\u{633}\u{62a}\u{62e}"
            . "\u{62f}\u{627}\u{645} \u{645}\u{639}\u{631}\u{651}\u{641} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644} \u{627}\u{644}\u{62e}\u{627}\u{635} \u{628}\u{648}\u{627}\u{62c}\u{647}"
            . "\u{629} \u{627}\u{644}\u{645}\u{62a}\u{62c}\u{631} \u{644}\u{647}\u{630}\u{627} \u{627}"
            . "\u{644}\u{637}\u{644}\u{628}.",
    ],
    'kwtsms.reason.no_sender_ids' => [
        'en' => 'Your kwtSMS account has no Sender ID yet.',
        'ar' => "\u{644}\u{627} \u{64a}\u{648}\u{62c}\u{62f} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}"
            . "\u{633}\u{644} \u{641}\u{64a} \u{62d}\u{633}\u{627}\u{628} kwtSMS \u{628}\u{639}\u{62f}.",
    ],
    'kwtsms.reason.sender_banned' => [
        'en' => 'kwtSMS banned this Sender ID. Choose another one.',
        'ar' => "\u{62d}\u{638}\u{631}\u{62a} kwtSMS \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}"
            . "\u{631}\u{633}\u{644} \u{647}\u{630}\u{627}. \u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}"
            . "\u{631}\u{641}\u{627} \u{622}\u{62e}\u{631}.",
    ],
    'kwtsms.reason.sender_not_in_list' => [
        'en' => 'Choose a Sender ID.',
        'ar' => "\u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644}.",
    ],
    'kwtsms.reason.sender_unknown' => [
        'en' => 'Choose a Sender ID from the list.',
        'ar' => "\u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} "
            . "\u{645}\u{646} \u{627}\u{644}\u{642}\u{627}\u{626}\u{645}\u{629}.",
    ],
    'kwtsms.settings.ack_required' => [
        'en' => 'Test mode is still on. To turn it off while KWT-SMS is the Sender ID, tick the confirmation under Test mode.',
        'ar' => "\u{645}\u{627} \u{632}\u{627}\u{644} \u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}"
            . "\u{62a}\u{628}\u{627}\u{631} \u{645}\u{641}\u{639}\u{644}\u{627}. \u{644}\u{625}\u{64a}"
            . "\u{642}\u{627}\u{641}\u{647} \u{628}\u{64a}\u{646}\u{645}\u{627} KWT-SMS \u{647}\u{648} "
            . "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644}\u{60c} \u{62d}"
            . "\u{62f}\u{62f} \u{62e}\u{627}\u{646}\u{629} \u{627}\u{644}\u{62a}\u{623}\u{643}\u{64a}"
            . "\u{62f} \u{623}\u{633}\u{641}\u{644} \u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}"
            . "\u{62a}\u{628}\u{627}\u{631}.",
    ],
    'kwtsms.settings.ack_required_sender' => [
        'en' => 'The Sender ID wasn\'t changed to KWT-SMS. With test mode off, tick the confirmation under Test mode first.',
        'ar' => "\u{644}\u{645} \u{64a}\u{62a}\u{63a}\u{64a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{627}"
            . "\u{644}\u{645}\u{631}\u{633}\u{644} \u{625}\u{644}\u{649} KWT-SMS. \u{628}\u{645}\u{627}"
            . " \u{623}\u{646} \u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}\u{62a}\u{628}\u{627}"
            . "\u{631} \u{645}\u{62a}\u{648}\u{642}\u{641}\u{60c} \u{62d}\u{62f}\u{62f} \u{62e}\u{627}"
            . "\u{646}\u{629} \u{627}\u{644}\u{62a}\u{623}\u{643}\u{64a}\u{62f} \u{623}\u{633}\u{641}"
            . "\u{644} \u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}\u{62a}\u{628}\u{627}\u{631} "
            . "\u{623}\u{648}\u{644}\u{627}.",
    ],
    'kwtsms.settings.field.sender_id' => [
        'en' => 'Sender ID',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644}",
    ],
    'kwtsms.settings.field.sender_type' => [
        'en' => 'Sender ID type',
        'ar' => "\u{646}\u{648}\u{639} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}"
            . "\u{644}",
    ],
    'kwtsms.settings.notice_transactional' => [
        'en' => 'Codes need a private transactional Sender ID. Promotional Sender IDs are filtered for numbers on DND and are still charged. KWT-SMS is refused outside test mode.',
        'ar' => "\u{62a}\u{62d}\u{62a}\u{627}\u{62c} \u{627}\u{644}\u{631}\u{645}\u{648}\u{632} \u{625}"
            . "\u{644}\u{649} \u{645}\u{639}\u{631}\u{651}\u{641} \u{645}\u{631}\u{633}\u{644} \u{62e}"
            . "\u{627}\u{635} \u{628}\u{627}\u{644}\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}"
            . "\u{62a}\u{639}\u{627}\u{645}\u{644}\u{64a}\u{629}. \u{62a}\u{64f}\u{635}\u{641}\u{651}"
            . "\u{649} \u{627}\u{644}\u{645}\u{639}\u{631}\u{651}\u{641}\u{627}\u{62a} \u{627}\u{644}"
            . "\u{62f}\u{639}\u{627}\u{626}\u{64a}\u{629} \u{644}\u{644}\u{623}\u{631}\u{642}\u{627}"
            . "\u{645} \u{627}\u{644}\u{645}\u{633}\u{62c}\u{644}\u{629} \u{641}\u{64a} \u{62e}\u{62f}"
            . "\u{645}\u{629} \u{639}\u{62f}\u{645} \u{627}\u{644}\u{625}\u{632}\u{639}\u{627}\u{62c} ("
            . "DND) \u{648}\u{62a}\u{64f}\u{62d}\u{62a}\u{633}\u{628} \u{631}\u{633}\u{648}\u{645}"
            . "\u{647}\u{627} \u{645}\u{639} \u{630}\u{644}\u{643}. \u{64a}\u{64f}\u{631}\u{641}\u{636}"
            . " KWT-SMS \u{62e}\u{627}\u{631}\u{62c} \u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}"
            . "\u{62a}\u{628}\u{627}\u{631}.",
    ],
    'kwtsms.settings.otp_unreachable_help' => [
        'en' => 'Applies when SMS sending is off-line: kwtSMS unreachable or suspended, balance under 1 credit, pilot mode, or the KWT-SMS Sender ID with test mode off. Pause tells the customer that SMS codes are unavailable and to try again in a few minutes. Continue lets the form go through with the phone unverified. Both raise an alert. A problem with one number never continues.',
        'ar' => "\u{62a}\u{64f}\u{637}\u{628}\u{642} \u{639}\u{646}\u{62f} \u{62a}\u{639}\u{630}\u{631} "
            . "\u{625}\u{631}\u{633}\u{627}\u{644} \u{627}\u{644}\u{631}\u{633}\u{627}\u{626}\u{644}: "
            . "\u{62a}\u{639}\u{630}\u{631} \u{627}\u{644}\u{648}\u{635}\u{648}\u{644} \u{625}\u{644}"
            . "\u{649} kwtSMS \u{623}\u{648} \u{625}\u{64a}\u{642}\u{627}\u{641}\u{647}\u{60c} \u{623}"
            . "\u{648} \u{631}\u{635}\u{64a}\u{62f} \u{623}\u{642}\u{644} \u{645}\u{646} \u{631}\u{635}"
            . "\u{64a}\u{62f} \u{648}\u{627}\u{62d}\u{62f}\u{60c} \u{623}\u{648} \u{627}\u{644}\u{648}"
            . "\u{636}\u{639} \u{627}\u{644}\u{62a}\u{62c}\u{631}\u{64a}\u{628}\u{64a}\u{60c} \u{623}"
            . "\u{648} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} KWT-SMS "
            . "\u{645}\u{639} \u{62a}\u{639}\u{637}\u{64a}\u{644} \u{648}\u{636}\u{639} \u{627}\u{644}"
            . "\u{627}\u{62e}\u{62a}\u{628}\u{627}\u{631}. \u{627}\u{644}\u{625}\u{64a}\u{642}\u{627}"
            . "\u{641} \u{64a}\u{62e}\u{628}\u{631} \u{627}\u{644}\u{639}\u{645}\u{64a}\u{644} \u{623}"
            . "\u{646} \u{631}\u{645}\u{648}\u{632} \u{627}\u{644}\u{62a}\u{62d}\u{642}\u{642} \u{63a}"
            . "\u{64a}\u{631} \u{645}\u{62a}\u{627}\u{62d}\u{629} \u{648}\u{64a}\u{637}\u{644}\u{628} "
            . "\u{645}\u{646}\u{647} \u{627}\u{644}\u{645}\u{62d}\u{627}\u{648}\u{644}\u{629} \u{628}"
            . "\u{639}\u{62f} \u{62f}\u{642}\u{627}\u{626}\u{642}. \u{627}\u{644}\u{645}\u{62a}\u{627}"
            . "\u{628}\u{639}\u{629} \u{62a}\u{633}\u{645}\u{62d} \u{628}\u{625}\u{62a}\u{645}\u{627}"
            . "\u{645} \u{627}\u{644}\u{646}\u{645}\u{648}\u{630}\u{62c} \u{628}\u{644}\u{627} \u{62a}"
            . "\u{648}\u{62b}\u{64a}\u{642} \u{627}\u{644}\u{62c}\u{648}\u{627}\u{644}. \u{643}\u{644}"
            . "\u{627}\u{647}\u{645}\u{627} \u{64a}\u{646}\u{634}\u{626} \u{62a}\u{646}\u{628}\u{64a}"
            . "\u{647}\u{627}. \u{648}\u{644}\u{627} \u{62a}\u{62a}\u{645} \u{627}\u{644}\u{645}\u{62a}"
            . "\u{627}\u{628}\u{639}\u{629} \u{623}\u{628}\u{62f}\u{627} \u{628}\u{633}\u{628}\u{628} "
            . "\u{645}\u{634}\u{643}\u{644}\u{629} \u{641}\u{64a} \u{631}\u{642}\u{645} \u{648}\u{627}"
            . "\u{62d}\u{62f}.",
    ],
    'kwtsms.settings.promotional_warning' => [
        'en' => 'A promotional Sender ID doesn\'t reach numbers on the do-not-disturb (DND) list, so some customers won\'t get their order SMS.',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{627}\u{644}"
            . "\u{62a}\u{631}\u{648}\u{64a}\u{62c}\u{64a} \u{644}\u{627} \u{64a}\u{635}\u{644} \u{625}"
            . "\u{644}\u{649} \u{627}\u{644}\u{623}\u{631}\u{642}\u{627}\u{645} \u{627}\u{644}\u{645}"
            . "\u{633}\u{62c}\u{644}\u{629} \u{641}\u{64a} \u{642}\u{627}\u{626}\u{645}\u{629} \u{639}"
            . "\u{62f}\u{645} \u{627}\u{644}\u{625}\u{632}\u{639}\u{627}\u{62c} (DND)\u{60c} \u{644}"
            . "\u{630}\u{644}\u{643} \u{644}\u{646} \u{64a}\u{633}\u{62a}\u{644}\u{645} \u{628}\u{639}"
            . "\u{636} \u{627}\u{644}\u{639}\u{645}\u{644}\u{627}\u{621} \u{631}\u{633}\u{627}\u{626}"
            . "\u{644} \u{637}\u{644}\u{628}\u{627}\u{62a}\u{647}\u{645}.",
    ],
    'kwtsms.settings.sender' => [
        'en' => 'Sender ID',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644}",
    ],
    'kwtsms.settings.sender_choose' => [
        'en' => 'Choose a Sender ID',
        'ar' => "\u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644}",
    ],
    'kwtsms.settings.sender_empty_list' => [
        'en' => 'Your kwtSMS account has no Sender ID yet. Request one at kwtsms.com, then sync.',
        'ar' => "\u{644}\u{627} \u{64a}\u{648}\u{62c}\u{62f} \u{645}\u{639}\u{631}\u{641} \u{645}\u{631}"
            . "\u{633}\u{644} \u{641}\u{64a} \u{62d}\u{633}\u{627}\u{628} kwtSMS \u{628}\u{639}\u{62f}."
            . " \u{627}\u{637}\u{644}\u{628} \u{648}\u{627}\u{62d}\u{62f}\u{627} \u{645}\u{646} kwtsms."
            . "com \u{62b}\u{645} \u{646}\u{641}\u{630} \u{627}\u{644}\u{645}\u{632}\u{627}\u{645}"
            . "\u{646}\u{629}.",
    ],
    'kwtsms.settings.sender_none' => [
        'en' => 'Log in to load the Sender IDs of your kwtSMS account.',
        'ar' => "\u{633}\u{62c}\u{644} \u{627}\u{644}\u{62f}\u{62e}\u{648}\u{644} \u{644}\u{62a}\u{62d}"
            . "\u{645}\u{64a}\u{644} \u{645}\u{639}\u{631}\u{641}\u{627}\u{62a} \u{627}\u{644}\u{645}"
            . "\u{631}\u{633}\u{644} \u{641}\u{64a} \u{62d}\u{633}\u{627}\u{628} kwtSMS.",
    ],
    'kwtsms.settings.sender_test_warning' => [
        'en' => 'KWT-SMS is the kwtSMS test sender. Delivery is delayed and it does not reach Virgin or DND-listed numbers. Register a private transactional Sender ID.',
        'ar' => "KWT-SMS \u{647}\u{648} \u{645}\u{631}\u{633}\u{644} \u{627}\u{644}\u{627}\u{62e}\u{62a}"
            . "\u{628}\u{627}\u{631} \u{641}\u{64a} kwtSMS. \u{64a}\u{62a}\u{623}\u{62e}\u{631} \u{627}"
            . "\u{644}\u{62a}\u{633}\u{644}\u{64a}\u{645} \u{648}\u{644}\u{627} \u{64a}\u{635}\u{644} "
            . "\u{625}\u{644}\u{649} \u{623}\u{631}\u{642}\u{627}\u{645} Virgin \u{623}\u{648} \u{627}"
            . "\u{644}\u{623}\u{631}\u{642}\u{627}\u{645} \u{627}\u{644}\u{645}\u{633}\u{62c}\u{644}"
            . "\u{629} \u{641}\u{64a} \u{642}\u{627}\u{626}\u{645}\u{629} \u{639}\u{62f}\u{645} \u{627}"
            . "\u{644}\u{625}\u{632}\u{639}\u{627}\u{62c}. \u{633}\u{62c}\u{644} \u{645}\u{639}\u{631}"
            . "\u{641} \u{645}\u{631}\u{633}\u{644} \u{62e}\u{627}\u{635}\u{627} \u{644}\u{644}\u{631}"
            . "\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{62a}\u{634}\u{63a}\u{64a}\u{644}\u{64a}"
            . "\u{629}.",
    ],
    'kwtsms.settings.sender_type_help' => [
        'en' => 'Order messages need a transactional Sender ID.',
        'ar' => "\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{637}\u{644}\u{628}\u{627}\u{62a} "
            . "\u{62a}\u{62d}\u{62a}\u{627}\u{62c} \u{625}\u{644}\u{649} \u{645}\u{639}\u{631}\u{641} "
            . "\u{645}\u{631}\u{633}\u{644} \u{62a}\u{634}\u{63a}\u{64a}\u{644}\u{64a}.",
    ],
    'kwtsms.settings.sender_type_promotional' => [
        'en' => 'Promotional',
        'ar' => "\u{62a}\u{631}\u{648}\u{64a}\u{62c}\u{64a}",
    ],
    'kwtsms.settings.sf_ack_required' => [
        'en' => '[storefront]: the Sender ID wasn\'t changed to KWT-SMS. With test mode off, tick the confirmation under the storefront Sender IDs first.',
        'ar' => "[storefront]: \u{644}\u{645} \u{64a}\u{62a}\u{63a}\u{64a}\u{631} \u{645}\u{639}\u{631}"
            . "\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{625}\u{644}\u{649} KWT-SMS. "
            . "\u{645}\u{639} \u{625}\u{64a}\u{642}\u{627}\u{641} \u{648}\u{636}\u{639} \u{627}\u{644}"
            . "\u{627}\u{62e}\u{62a}\u{628}\u{627}\u{631} \u{641}\u{639}\u{651}\u{644} \u{627}\u{644}"
            . "\u{62a}\u{623}\u{643}\u{64a}\u{62f} \u{623}\u{633}\u{641}\u{644} \u{645}\u{639}\u{631}"
            . "\u{641}\u{627}\u{62a} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{644}\u{644}\u{648}"
            . "\u{627}\u{62c}\u{647}\u{627}\u{62a} \u{623}\u{648}\u{644}\u{627}.",
    ],
    'kwtsms.settings.sf_sender_aria' => [
        'en' => 'Sender ID for [storefront]',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{644}\u{648}"
            . "\u{627}\u{62c}\u{647}\u{629} [storefront]",
    ],
    'kwtsms.settings.sf_sender_main' => [
        'en' => 'Main Sender ID: [sender]',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{627}\u{644}"
            . "\u{631}\u{626}\u{64a}\u{633}\u{64a}: [sender]",
    ],
    'kwtsms.settings.sf_sender_not_in_list' => [
        'en' => '[storefront]: that Sender ID is not in your synced list. Sync, then choose again.',
        'ar' => "[storefront]: \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} "
            . "\u{647}\u{630}\u{627} \u{644}\u{64a}\u{633} \u{641}\u{64a} \u{642}\u{627}\u{626}\u{645}"
            . "\u{62a}\u{643} \u{627}\u{644}\u{645}\u{632}\u{627}\u{645}\u{646}\u{629}. \u{632}\u{627}"
            . "\u{645}\u{646} \u{62b}\u{645} \u{627}\u{62e}\u{62a}\u{631} \u{645}\u{62c}\u{62f}\u{62f}"
            . "\u{627}.",
    ],
    'kwtsms.settings.sf_sender_same' => [
        'en' => 'Same as the main Sender ID',
        'ar' => "\u{645}\u{62b}\u{644} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}"
            . "\u{644} \u{627}\u{644}\u{631}\u{626}\u{64a}\u{633}\u{64a}",
    ],
    'kwtsms.settings.sf_sender_stale' => [
        'en' => '[sender] (no longer in your Sender ID list)',
        'ar' => "[sender] (\u{644}\u{645} \u{64a}\u{639}\u{62f} \u{641}\u{64a} \u{642}\u{627}\u{626}"
            . "\u{645}\u{629} \u{645}\u{639}\u{631}\u{641}\u{627}\u{62a} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644})",
    ],
    'kwtsms.settings.sf_senders_connect' => [
        'en' => 'Connect your kwtSMS account and sync to pick a Sender ID for each storefront.',
        'ar' => "\u{627}\u{631}\u{628}\u{637} \u{62d}\u{633}\u{627}\u{628} kwtSMS \u{648}\u{632}\u{627}"
            . "\u{645}\u{646}\u{647} \u{644}\u{627}\u{62e}\u{62a}\u{64a}\u{627}\u{631} \u{645}\u{639}"
            . "\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} \u{644}\u{643}\u{644} \u{648}\u{627}\u{62c}"
            . "\u{647}\u{629}.",
    ],
    'kwtsms.settings.sf_senders_help' => [
        'en' => 'Each storefront can send under its own Sender ID. Leave a storefront on the main Sender ID to use the one above. If a storefront\'s Sender ID is removed or blocked, its SMS go out under the main Sender ID.',
        'ar' => "\u{64a}\u{645}\u{643}\u{646} \u{644}\u{643}\u{644} \u{648}\u{627}\u{62c}\u{647}\u{629} "
            . "\u{645}\u{62a}\u{62c}\u{631} \u{627}\u{644}\u{625}\u{631}\u{633}\u{627}\u{644} \u{628}"
            . "\u{645}\u{639}\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} \u{62e}\u{627}\u{635} \u{628}"
            . "\u{647}\u{627}. \u{627}\u{62a}\u{631}\u{643} \u{627}\u{644}\u{648}\u{627}\u{62c}\u{647}"
            . "\u{629} \u{639}\u{644}\u{649} \u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}"
            . "\u{633}\u{644} \u{627}\u{644}\u{631}\u{626}\u{64a}\u{633}\u{64a} \u{644}\u{62a}\u{633}"
            . "\u{62a}\u{62e}\u{62f}\u{645} \u{627}\u{644}\u{645}\u{639}\u{631}\u{641} \u{623}\u{639}"
            . "\u{644}\u{627}\u{647}. \u{625}\u{630}\u{627} \u{62d}\u{64f}\u{630}\u{641} \u{645}\u{639}"
            . "\u{631}\u{641} \u{645}\u{631}\u{633}\u{644} \u{627}\u{644}\u{648}\u{627}\u{62c}\u{647}"
            . "\u{629} \u{623}\u{648} \u{62d}\u{64f}\u{638}\u{631} \u{62a}\u{64f}\u{631}\u{633}\u{644} "
            . "\u{631}\u{633}\u{627}\u{626}\u{644}\u{647}\u{627} \u{628}\u{645}\u{639}\u{631}\u{641} "
            . "\u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{627}\u{644}\u{631}\u{626}\u{64a}\u{633}"
            . "\u{64a}.",
    ],
    'kwtsms.settings.sf_senders_save' => [
        'en' => 'Save storefront Sender IDs',
        'ar' => "\u{62d}\u{641}\u{638} \u{645}\u{639}\u{631}\u{641}\u{627}\u{62a} \u{627}\u{644}\u{645}"
            . "\u{631}\u{633}\u{644} \u{644}\u{644}\u{648}\u{627}\u{62c}\u{647}\u{627}\u{62a}",
    ],
    'kwtsms.settings.sf_senders_saved' => [
        'en' => 'Storefront Sender IDs saved: [count] changed.',
        'ar' => "\u{62a}\u{645} \u{62d}\u{641}\u{638} \u{645}\u{639}\u{631}\u{641}\u{627}\u{62a} \u{627}"
            . "\u{644}\u{645}\u{631}\u{633}\u{644} \u{644}\u{644}\u{648}\u{627}\u{62c}\u{647}\u{627}"
            . "\u{62a}: \u{62a}\u{63a}\u{64a}\u{651}\u{631} [count].",
    ],
    'kwtsms.settings.sf_senders_title' => [
        'en' => 'Sender ID per storefront',
        'ar' => "\u{645}\u{639}\u{631}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} \u{644}\u{643}"
            . "\u{644} \u{648}\u{627}\u{62c}\u{647}\u{629} \u{645}\u{62a}\u{62c}\u{631}",
    ],
    'kwtsms.settings.sf_senders_unchanged' => [
        'en' => 'No storefront Sender ID changed.',
        'ar' => "\u{644}\u{645} \u{64a}\u{62a}\u{63a}\u{64a}\u{631} \u{623}\u{64a} \u{645}\u{639}\u{631}"
            . "\u{641} \u{645}\u{631}\u{633}\u{644} \u{644}\u{644}\u{648}\u{627}\u{62c}\u{647}\u{627}"
            . "\u{62a}.",
    ],
    'kwtsms.settings.warn_kwt_sender' => [
        'en' => 'Sender ID KWT-SMS is used by: [names]. Codes are refused there outside test mode. Choose a private transactional Sender ID.',
        'ar' => "\u{645}\u{639}\u{631}\u{651}\u{641} \u{627}\u{644}\u{645}\u{631}\u{633}\u{644} KWT-SMS "
            . "\u{645}\u{633}\u{62a}\u{62e}\u{62f}\u{645} \u{641}\u{64a}: [names]. \u{62a}\u{64f}"
            . "\u{631}\u{641}\u{636} \u{627}\u{644}\u{631}\u{645}\u{648}\u{632} \u{647}\u{646}\u{627}"
            . "\u{643} \u{62e}\u{627}\u{631}\u{62c} \u{648}\u{636}\u{639} \u{627}\u{644}\u{627}\u{62e}"
            . "\u{62a}\u{628}\u{627}\u{631}. \u{627}\u{62e}\u{62a}\u{631} \u{645}\u{639}\u{631}\u{651}"
            . "\u{641} \u{645}\u{631}\u{633}\u{644} \u{62e}\u{627}\u{635}\u{64b}\u{627} \u{628}\u{627}"
            . "\u{644}\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{62a}\u{639}\u{627}\u{645}"
            . "\u{644}\u{64a}\u{629}.",
    ],
    'kwtsms.templates.warn_promotional' => [
        'en' => 'Promotional wording: [found]. Keep order texts about the order. Promotions don\'t reach numbers on the do-not-disturb (DND) list.',
        'ar' => "\u{639}\u{628}\u{627}\u{631}\u{627}\u{62a} \u{62a}\u{631}\u{648}\u{64a}\u{62c}\u{64a}"
            . "\u{629}: [found]. \u{623}\u{628}\u{642}\u{650} \u{646}\u{635}\u{648}\u{635} \u{627}"
            . "\u{644}\u{637}\u{644}\u{628}\u{627}\u{62a} \u{639}\u{646} \u{627}\u{644}\u{637}\u{644}"
            . "\u{628}. \u{627}\u{644}\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{62a}\u{631}"
            . "\u{648}\u{64a}\u{62c}\u{64a}\u{629} \u{644}\u{627} \u{62a}\u{635}\u{644} \u{625}\u{644}"
            . "\u{649} \u{627}\u{644}\u{623}\u{631}\u{642}\u{627}\u{645} \u{627}\u{644}\u{645}\u{633}"
            . "\u{62c}\u{644}\u{629} \u{641}\u{64a} \u{642}\u{627}\u{626}\u{645}\u{629} \u{639}\u{62f}"
            . "\u{645} \u{627}\u{644}\u{625}\u{632}\u{639}\u{627}\u{62c} (DND).",
    ],
];

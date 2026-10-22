<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/VendorPhoneSourceInterface.php
 * CREATED:   2026-10-22 17:11 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Where the SMS transport reads the approved SMS number of a Multi-Vendor vendor (receiver V).
 * WHY:       Phase 2 Task A4: receiver V needs a phone the root administrator approved (P2-5). The approval store
 *            (Vendors\VendorPhones, Task A8) does not exist yet; the notifier depends on this interface only, and the
 *            container binds kwtsms.vendor_phones to a source that approves nobody until A8 replaces it.
 * USAGE:     $phone = $source->approvedPhone($company_id);   // '' = the vendor has no approved number
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Notifications;

interface VendorPhoneSourceInterface
{
    /**
     * The normalized, approved SMS number of a vendor; '' when the vendor has none (or lost it by changing it).
     */
    public function approvedPhone(int $company_id): string;
}

<?php

/*
 * FILE:      app/addons/kwtsms/controllers/backend/kwtsms_vendor.php
 * CREATED:   2026-11-06 18:33 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The controller kwtsms_vendor of the vendor panel (Multi-Vendor): the page where a vendor administrator
 *            confirms the company's SMS number with a code.
 * WHY:       Task M2.6 (SafatMall F4, P3-56). All logic is in Vendors\VendorPhoneController; the company id comes from
 *            the session, never from the request.
 * USAGE:     GET  dispatch=kwtsms_vendor.phone        the page (while vendor_phone_self_verify is ON, else 404)
 *            POST dispatch=kwtsms_vendor.send_code    a code to the company phone (security_hash required)
 *            POST dispatch=kwtsms_vendor.verify       kwtsms_code=<6 digits>   security_hash=<session hash>
 * DEPENDS:   Vendors\VendorPhoneController, the permission schemas (vendor: phone, send_code, verify).
 */

use Tygh\Addons\Kwtsms\Vendors\VendorPhoneController;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

return VendorPhoneController::run($mode, $_REQUEST) ?? [CONTROLLER_STATUS_OK];

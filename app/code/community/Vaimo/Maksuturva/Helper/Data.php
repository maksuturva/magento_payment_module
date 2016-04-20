<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Helper_Data extends Mage_Core_Helper_Abstract
{
    const CONFIG_PRESELECT_PAYMENT_METHOD = "payment/maksuturva/preselect_payment_method";
    const CONFIG_DELAYED_CAPTURE_METHODS = "payment/maksuturva/delayed_capture";

    /**
     * Generate random pmt_id string
     *
     * @return string
     */
    public static function generatePaymentId()
    {
        return sprintf('%04x%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}
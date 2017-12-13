<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Helper_Data extends Mage_Core_Helper_Abstract
{
    const CONFIG_PRESELECT_PAYMENT_METHOD = "payment/maksuturva/preselect_payment_method";
    const CONFIG_DELAYED_CAPTURE_METHODS = "payment/maksuturva/delayed_capture";
    const CONFIG_CAN_CANCEL_SETTLED = "payment/maksuturva/can_cancel_settled";
    const CONFIG_ENABLE_SETTLED_EMAIL = "payment/maksuturva/enable_payment_information_email";
    const CONFIG_CAN_SETTLED_SENDER = "payment/maksuturva/settled_sender_email_identity";
    const CONFIG_CAN_SETTLED_RECIPIENT = "payment/maksuturva/settled_recipient_email";
    const CONFIG_CAN_SETTLED_TEMPLATE = "payment/maksuturva/settled_email_template";


    /**
     * Generate random pmt_id string
     *
     * @return string
     */
    public static function generatePaymentId()
    {
        return sprintf('%04x%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    public function canCancelSettled(){
        return Mage::getStoreConfigFlag(self::CONFIG_CAN_CANCEL_SETTLED);
    }

    public function canSendMaksuturvaPaymentInformation(){
        return Mage::getStoreConfigFlag(self::CONFIG_ENABLE_SETTLED_EMAIL);
    }

    public function getSettledEmailSender(){
        return Mage::getStoreConfig(self::CONFIG_CAN_SETTLED_SENDER);
    }

    public function getSettledEmailRecipient(){
        return Mage::getStoreConfig(self::CONFIG_CAN_SETTLED_RECIPIENT);
    }

    public function getSettledEmailTemplate(){
        return Mage::getStoreConfig(self::CONFIG_CAN_SETTLED_TEMPLATE);
    }
}
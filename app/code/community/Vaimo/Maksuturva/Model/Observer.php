<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Model_Observer
{
    /**
     * Observer for event sales_quote_payment_import_data_before
     *
     * User selected sub-method is saved to additional_data
     *
     * @param Varien_Event_Observer $observer
     */
    public function setPreselectedMethod(Varien_Event_Observer $observer)
    {
        /** @var Varien_Object $data */
        $data = $observer->getEvent()->getInput();
        $payment = $observer->getEvent()->getPayment();
        $additional_data = unserialize($payment->getAdditionalData());

        if (substr($data->getMethod(), 0, 11) == 'maksuturva_') {
            // special case when sub-methods are split into different actual Magento methods
            // not supported officially
            if (isset($data['maksuturva_preselected_payment_method'])) {
                $realMethod = $data->getMaksuturvaPreselectedPaymentMethod();
            } else {
                $realMethod = strtoupper(substr($data->getMethod(), 11));
            }

            $data->setMethod('maksuturva');

            $instance = Mage::getModel('maksuturva/maksuturva');
            $methods = json_decode(json_encode($instance->getPaymentMethods()), true);
            foreach ($methods as $k => $v) {
                if ($v['code'] == $realMethod) {
                    $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD_DESCRIPTION] = $v['displayname'];
                    break;
                }
            }

            $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD] = $realMethod;
            $payment->setAdditionalData(serialize($additional_data));
        } elseif ($data->getMethod() == 'maksuturva') {
            $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD] = $data->getMaksuturvaPreselectedPaymentMethod();
            $payment->setAdditionalData(serialize($additional_data));
        }
    }
}
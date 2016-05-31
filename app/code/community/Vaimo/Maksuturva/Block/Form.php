<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Block_Form extends Mage_Payment_Block_Form
{
    const FORMTYPE_DROPDOWN = 0;
    const FORMTYPE_ICONS = 1;

    /**
     * Set template and redirect message
     */
    protected function _construct()
    {
        parent::_construct();

        $this->method = Mage::getModel('maksuturva/maksuturva');
        $preselect = intval($this->getMethod()->getConfigData('preselect_payment_method'));

        if ($preselect) {
            $this->paymentFees = $this->getMethod()->getConfigData('preselect_paymentfee');
            if (strlen($this->paymentFees) > 0) {
                if (!Mage::helper('core')->isModuleEnabled('Vaimo_PaymentFee')) {
                    throw new Exception("paymentfee configured but module Vaimo_PaymentFee not active!");
                }
            }

            switch ($this->getFormType()) {
                case self::FORMTYPE_DROPDOWN:
                    $this->setTemplate('maksuturva/form_select.phtml');
                    break;
                case self::FORMTYPE_ICONS:
                    $this->setTemplate('maksuturva/form_icons.phtml');
                    break;
                default:
                    throw new Exception('unknown form type');
            }
        }
    }

    public function getPaymentMethods()
    {
        return $this->getMethod()->getPaymentMethods();
    }

    public function getSelectedMethod()
    {
        return $this->getMethod()->getSelectedMethod();
    }

    /**
     * Compatibility method to check if icommerce/quickcheckout is active
     *
     * @return bool
     */
    public function isQuickCheckoutActive()
    {
        return Mage::helper('core')->isModuleEnabled('Icommerce_QuickCheckout');
    }

    /**
     * Get payment form type
     *
     * @return string
     */
    public function getFormType()
    {
        return $this->getMethod()->getConfigData('preselect_form_type');
    }
}

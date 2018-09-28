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

    /**
     * Get display name including discount
     * @param array $method
     *
     * @return string
     */
    public function getMethodDisplayName($method)
    {
        $displayName = $method->displayname;

        $discountStr = $this->getFormattedDiscount($method);

        if ($discountStr) {
            $displayName .= ' (' . $discountStr . ')';
        }

        return $displayName;
    }

    /**
     * E.g. "-10.5%" or "-50€"
     *
     * @param array $method
     *
     * @return string
     */
    public function getFormattedDiscount($method)
    {
        $formattedDiscount = '';

        $discountData = $this->getMethodDiscount($method->code);

        if (isset($discountData['amount']) && isset($discountData['type'])) {
            $discountAmount = $discountData['amount'];
            $discountType = $discountData['type'];

            $unit = null;

            switch ($discountType) {
                case Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION:
                case Mage_SalesRule_Model_Rule::TO_PERCENT_ACTION:
                    $unit = '%';
                    break;
                case Mage_SalesRule_Model_Rule::BY_FIXED_ACTION:
                case Mage_SalesRule_Model_Rule::CART_FIXED_ACTION:
                case Mage_SalesRule_Model_Rule::TO_FIXED_ACTION:
                    $unit = Mage::app()->getLocale()->currency(Mage::app()->getStore()->getCurrentCurrencyCode())->getSymbol();
                    break;
            }

            if (!empty($discountAmount) && !empty($discountType)) {
                if ((int)$discountAmount == $discountAmount) {
                    $discountAmount = number_format($discountAmount);
                } else {
                    $discountAmount = number_format($discountAmount, 2);
                }
                $formattedDiscount = sprintf('-%s%s', $discountAmount, $unit);
            }
        }
        return $formattedDiscount;
    }

    protected function getMethodDiscount($code)
    {
        $discount = Mage::getSingleton('maksuturva/discount');
        return $discount->getMethodDiscount($code);
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

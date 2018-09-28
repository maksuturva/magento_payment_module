<?php
/**
 * Copyright (c) 2018 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */

class Vaimo_Maksuturva_Model_Discount extends Mage_SalesRule_Model_Validator
{
    protected $methodDiscounts;

    /**
     * Returns array with amount and type if discount exists, false otherwise
     *
     * @param $methodCode
     * @return array|bool
     */
    public function getMethodDiscount($methodCode)
    {
        if ($this->methodDiscounts == null) {
            $this->methodDiscounts = $this->getMethodDiscounts();
        }

        if (!isset($this->methodDiscounts[$methodCode])) {
            return false;
        }
        return $this->methodDiscounts[$methodCode];
    }

    /**
     * @return array
     */
    public function getMethodDiscounts()
    {
        $address = Mage::getSingleton('checkout/cart')->getQuote()->getShippingAddress();

        $discounts = [];

        if (!$address instanceof Mage_Sales_Model_Quote_Address) {
            return $discounts;
        }

        $store = Mage::app()->getStore($address->getStoreId());

        $this->init($store->getWebsiteId(), $address->getCustomerGroupId(), $address->getCouponCode());

        return $this->getAddressMethodDiscounts($address);
    }

    /**
     * @param $address Mage_Sales_Model_Quote_Address
     * @return array
     */
    protected function getAddressMethodDiscounts($address)
    {
        // Payment method will be changed to check which discounts pass with Maksuturva methods
        // Cloning address to avoid changing payment method of original address
        $address = clone $address;

        $methodDiscounts = [];

        foreach ($this->_getRules() as $rule) {
            $methodCode = $this->getMaksuturvaPaymentMethodCode($rule);

            if (false == $methodCode) {
                continue;
            }

            // Set Maksuturva method so rule passes if all other conditions are met
            $this->setMaksuturvaPaymentMethod($address, $methodCode);

            if ($this->_canProcessRule($rule, $address)) {
                if (!isset($methodDiscounts[$methodCode])) {
                    $methodDiscounts[$methodCode] = [
                        'amount' => $rule->getDiscountAmount(),
                        'type' => $rule->getSimpleAction()
                    ];
                }
            }
        }
        return $methodDiscounts;
    }

    /**
     * @param $address Mage_Sales_Model_Quote_Address
     * @param $method string
     */
    protected function setMaksuturvaPaymentMethod($address, $methodCode)
    {
        $payment = $address->getQuote()->getPayment();
        $additional_data = unserialize($payment->getAdditionalData());
        $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD] = $methodCode;
        $payment->setAdditionalData(serialize($additional_data));
    }

    /**
     * @param $rule Mage_SalesRule_Model_Rule
     * @return bool
     */
    protected function getMaksuturvaPaymentMethodCode($rule)
    {
        $methodCode = false;

        $conditions = $rule->getConditions();

        foreach ($conditions->getConditions() as $condition) {
            if ($condition->getAttribute() == 'maksuturva_payment_method') {
                $methodCode = $condition->getValue();
            }
        }
        return $methodCode;
    }
}
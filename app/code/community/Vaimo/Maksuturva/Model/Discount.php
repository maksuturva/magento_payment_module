<?php
/**
 * Copyright (c) 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */

class Vaimo_Maksuturva_Model_Discount extends Mage_SalesRule_Model_Quote_Discount
{
    private $configs;

    /**
     * Collect address discount amount
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @return  Mage_SalesRule_Model_Quote_Discount
     */
    public function collect(Mage_Sales_Model_Quote_Address $address)
    {
        parent::collect($address);

        $active = $this->getConfigs()['active'];

        if (true == $active) {
            $items = $this->_getAddressItems($address);
            $this->processMaksuturvaDiscount($address, $items);

            $this->_calculator->prepareDescription($address);
        }

        return $this;
    }

    /**
     * @param $address Mage_Sales_Model_Quote_Address
     * @param $items
     */
    protected function processMaksuturvaDiscount($address, $items)
    {
        $discount = $this->getPaymentDiscount($address->getQuote()->getPayment());
        $discountPercent = $discount['amount'];
        $discountDescription = $discount['description'];

        if ($discountPercent <= 0 || empty($discountDescription)) {
            return;
        }

        foreach ($items as $item) {
            $itemPrice = $item->getDiscountCalculationPrice();
            $baseItemPrice = $item->getDiscountCalculationPrice();
            $qty = $item->getQty();

            $discountAmount = ($qty * $itemPrice - $item->getDiscountAmount()) * $discountPercent / 100;
            $baseDiscountAmount  = ($qty * $baseItemPrice - $item->getBaseDiscountAmount()) * $discountPercent / 100;

            $item->setDiscountAmount($discountAmount);
            $item->setBaseDiscountAmount($baseDiscountAmount);

            $this->_aggregateItemDiscount($item);
        }

        $shippingDiscount = ($address->getShippingAmount() - $address->getShippingDiscountAmount()) * $discountPercent / 100;
        $baseShippingDiscount = ($address->getBaseShippingAmount() - $address->getBaseShippingDiscountAmount()) * $discountPercent / 100;

        $this->_addAmount(-$shippingDiscount);
        $this->_addBaseAmount(-$baseShippingDiscount);

        if ($address->getDiscountDescription()) {
            $address->setDiscountDescription($address->getDiscountDescription() . ','. $discountDescription);
        } else {
            $address->setDiscountDescription($discountDescription);
        }
    }

    /**
     * @return array Example: ['FI01' => '5.0', 'FI55' => '10']
     */
    public function getDiscounts()
    {
        $discountConfigStr = $this->getConfigs()['methodDiscounts'];
        $discountConfigStr = preg_replace('/\s+/', '', $discountConfigStr);
        $discounts = explode(',', $discountConfigStr);

        $discountArr = [];
        foreach ($discounts as $discount) {
            $discountTuple = explode('=', $discount);

            if (count($discountTuple) != 2) {
                continue;
            }
            $discountArr[$discountTuple[0]] = $discountTuple[1];
        }
        return $discountArr;
    }

    /**
     * @param $methodCode
     *
     * @return float
     */
    public function getMethodDiscount($methodCode)
    {
        $discounts = $this->getDiscounts();

        if (isset($discounts[$methodCode])) {
            return $discounts[$methodCode];
        }
        return 0.0;
    }

    /**
     * @param Mage_Sales_Model_Quote_Payment $payment
     *
     * @return array|false
     */
    public function getPaymentDiscount($payment)
    {
        $preselectMethod = $this->getPreselectMethod($payment);

        if (! $preselectMethod) {
            return false;
        }

        $amount = $this->getMethodDiscount($preselectMethod);

        if ($amount <= 0) {
            return false;
        }

        $description = $this->getDiscountDescription($preselectMethod, $amount);

        return array(
            'amount' => $amount,
            'description' => $description
        );
    }

    /**
     * @param string $methodCode
     * @param float $amount
     *
     * @return string
     */
    protected function getDiscountDescription($methodCode, $amount)
    {
        $methodName = $this->getPaymentMethodName($methodCode);

        // PM - Payment Method
        return sprintf('%s-%.01f%%', $methodName, $amount);
    }

    /**
     * @param Mage_Sales_Model_Quote_Payment $payment
     *
     * @return mixed
     */
    protected function getPreselectMethod($payment)
    {
        if ($this->getConfigs()['preselectPaymentMethod']) {
            $additional_data = unserialize($payment->getAdditionalData());
            if (isset($additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD])) {
                return $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD];
            }
        }

        return false;
    }

    protected function getPaymentMethodName($methodCode)
    {
        $maksuturva = Mage::getModel('maksuturva/maksuturva');
        $methods = $maksuturva->getPaymentMethods();

        foreach ($methods as $method) {
            if ($method->{'code'} == $methodCode) {
                return $method->{'displayname'};
            }
        }
        return $methodCode;
    }

    protected function getConfigs()
    {
        if (is_null($this->configs)) {
            $maksuturva = Mage::getModel('maksuturva/maksuturva');
            $this->configs = [
                'preselectPaymentMethod' => $maksuturva->getConfigData('preselect_payment_method'),
                'methodDiscounts' => $maksuturva->getConfigData('method_discounts'),
                'active' => $maksuturva->getConfigData('active')
            ];
        }
        return $this->configs;
    }
}
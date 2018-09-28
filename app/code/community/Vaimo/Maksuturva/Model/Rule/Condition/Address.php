<?php
/**
 * Copyright (c) 2018 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */

class Vaimo_Maksuturva_Model_Rule_Condition_Address extends Mage_Rule_Model_Condition_Abstract
{
    public function loadAttributeOptions()
    {
        $attributes = array(
            'base_subtotal' => Mage::helper('salesrule')->__('Subtotal'),
            'total_qty' => Mage::helper('salesrule')->__('Total Items Quantity'),
            'weight' => Mage::helper('salesrule')->__('Total Weight'),
            'payment_method' => Mage::helper('salesrule')->__('Payment Method'),
            'shipping_method' => Mage::helper('salesrule')->__('Shipping Method'),
            'postcode' => Mage::helper('salesrule')->__('Shipping Postcode'),
            'region' => Mage::helper('salesrule')->__('Shipping Region'),
            'region_id' => Mage::helper('salesrule')->__('Shipping State/Province'),
            'country_id' => Mage::helper('salesrule')->__('Shipping Country'),
            'maksuturva_payment_method' => Mage::helper('salesrule')->__('Maksuturva payment method')
        );

        $this->setAttributeOption($attributes);

        return $this;
    }

    public function getAttributeElement()
    {
        $element = parent::getAttributeElement();
        $element->setShowAsText(true);
        return $element;
    }

    public function getInputType()
    {
        switch ($this->getAttribute()) {
            case 'base_subtotal': case 'weight': case 'total_qty':
                return 'numeric';

            case 'shipping_method': case 'payment_method': case 'country_id': case 'region_id':
                return 'select';
        }
        return 'string';
    }

    public function getValueElementType()
    {
        switch ($this->getAttribute()) {
            case 'shipping_method': case 'payment_method': case 'country_id': case 'region_id':
                return 'select';
        }
        return 'text';
    }

    public function getValueSelectOptions()
    {
        if (!$this->hasData('value_select_options')) {
            switch ($this->getAttribute()) {
                case 'country_id':
                    $options = Mage::getModel('adminhtml/system_config_source_country')
                        ->toOptionArray();
                    break;

                case 'region_id':
                    $options = Mage::getModel('adminhtml/system_config_source_allregion')
                        ->toOptionArray();
                    break;

                case 'shipping_method':
                    $options = Mage::getModel('adminhtml/system_config_source_shipping_allmethods')
                        ->toOptionArray();
                    break;

                case 'payment_method':
                    $options = Mage::getModel('adminhtml/system_config_source_payment_allmethods')
                        ->toOptionArray();
                    break;

                default:
                    $options = array();
            }
            $this->setData('value_select_options', $options);
        }
        return $this->getData('value_select_options');
    }

    /**
     * Validate Address Rule Condition
     *
     * @param Varien_Object $object
     * @return bool
     */
    public function validate(Varien_Object $object)
    {
        $address = $object;
        if (!$address instanceof Mage_Sales_Model_Quote_Address) {
            if ($object->getQuote()->isVirtual()) {
                $address = $object->getQuote()->getBillingAddress();
            }
            else {
                $address = $object->getQuote()->getShippingAddress();
            }
        }

        if ('payment_method' == $this->getAttribute() && ! $address->hasPaymentMethod()) {
            $address->setPaymentMethod($object->getQuote()->getPayment()->getMethod());
        }

        if ('maksuturva_payment_method' == $this->getAttribute()) {
            return $this->validateMaksuturvaPaymentMethod($object);
        }
        return parent::validate($address);
    }

    /**
     * @return array
     */
    public function getOperatorSelectOptions()
    {
        if ('maksuturva_payment_method' == $this->getAttribute()) {
            return [
                [
                    'value' => '==',
                    'label' => Mage::helper('maksuturva')->__('equals')
                ]
            ];
        }
        return parent::getOperatorSelectOptions();
    }

    /**
     * @param Varien_Object $object
     * @return bool
     */
    protected function validateMaksuturvaPaymentMethod(Varien_Object $object)
    {
        $valid = false;

        $payment = $object->getQuote()->getPayment();

        $additional_data = unserialize($payment->getAdditionalData());

        if (isset($additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD])) {
            $maksuturvaMethod = $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD];

            /**
             * Condition attribute value
             */
            $value = $this->getValueParsed();

            /**
             * Comparison operator
             */
            $op = $this->getOperatorForValidate();

            if ('==' == $op && $maksuturvaMethod == $value) {
                $valid = true;
            }
        }

        return $valid;
    }

}

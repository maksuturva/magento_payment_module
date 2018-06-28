<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Model_Maksuturva extends Mage_Payment_Model_Method_Abstract
{
    const ERROR_INVALID_HASH = 'invalid_hash_error';
    const ERROR_EMPTY_FIELD = 'empty_field_error';
    const ERROR_VALUES_MISMATCH = 'values_mismatch_error';
    const ERROR_SELLERCOSTS_VALUES_MISMATCH = 'sellercosts_values_mismatch_error';
    const MAKSUTURVA_PRESELECTED_PAYMENT_METHOD = "maksuturva_preselected_payment_method";
    const MAKSUTURVA_PRESELECTED_PAYMENT_METHOD_DESCRIPTION = "maksuturva_preselected_payment_method_description";
    const MAKSUTURVA_TRANSACTION_ID = "maksuturva_transaction_id";

    protected $_code = 'maksuturva';
    protected $_allowCurrencyCode = array("EUR");
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canSaveCc = false;
    protected $_isInitializeNeeded = true;
    protected $_formBlockType = 'maksuturva/form';


    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('maksuturva/index/redirect', array('_secure' => true));
    }

    /**
     * Return form field array to submit to maksuturva
     *
     * @return array
     */
    public function getCheckoutFormFields()
    {
        $implementation = $this->getGatewayImplementation();
        $implementation->setOrder($this->getOrder());

        return $implementation->getFormFields();
    }

    /**
     * @return Vaimo_Maksuturva_Model_Gateway_Implementation
     */
    public function getGatewayImplementation()
    {
        $implementation = Mage::getModel('maksuturva/gateway_implementation', $this->getConfigs());

        return $implementation;
    }

    /**
     * @param null|string $orderId
     * @return Mage_Sales_Model_Order
     */
    protected function getOrder($orderId = null)
    {
        if ($orderId) {
            $order = Mage::getModel('sales/order')->load($orderId);
        } else {
            $orderIncrementId = $this->getCheckout()->getLastRealOrderId();
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        }

        return $order;
    }


    public function getConfigs()
    {
        $config = array(
            'sandbox' => intval($this->getConfigData('sandboxmode')),
            'commencoding' => $this->getConfigData('commencoding'),
            'paymentdue' => intval($this->getConfigData('paymentdue')),
            'keyversion' => $this->getConfigData('keyversion'),
            'preselect_payment_method' => $this->getConfigData('preselect_payment_method'),
        );
        if ($config['sandbox']) {
            $config['sellerId'] = $this->getConfigData('test_sellerid');
            $config['secretKey'] = $this->getConfigData('test_secretkey');
            $config['commurl'] = $this->getConfigData('test_commurl');
        } else {
            $config['sellerId'] = $this->getConfigData('sellerid');
            $config['secretKey'] = $this->getConfigData('secretkey');
            $config['commurl'] = $this->getConfigData('commurl');
        }
        return $config;
    }

    /**
     * Instantiate state and set it to state object
     * @param string $paymentAction
     * @param Varien_Object
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }

    /**
     * Get checkout session namespace
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    public function getPaymentRequestUrl()
    {
        return $this->getGatewayImplementation()->getPaymentRequestUrl();
    }

    public function getPaymentMethods()
    {
        $quoteTotal = Mage::getSingleton('checkout/cart')->getQuote()->getGrandTotal();
        $cacheKey = "MAKSUTURVA_PAYMENT_METHODS_" . number_format($quoteTotal, 4, "_", "");

        if ($cachedData = Mage::app()->loadCache($cacheKey)) {
            $methods = unserialize($cachedData);
        } else {
            $methods = $this->getGatewayImplementation()->getPaymentMethods($quoteTotal);
            if ($methods) {
                Mage::app()->saveCache(serialize($methods), $cacheKey, array("MAKSUTURVA"), 60 * 5);
            }
        }

        return $methods;
    }

    public function getCanHaveVaimoPaymentFee()
    {
        if ($this->getConfigData('preselect_payment_method') && $this->getConfigData('preselect_paymentfee')) {
            return true;
        } else {
            return false;
        }
    }

    public function getVaimoPaymentFee()
    {
        return $this->getPaymentFeeForMethod($this->getSelectedMethod());
    }

    public function getSelectedMethod()
    {
        // this fails because collectTotals is done before assignData() call
        // we use observer instead and retrieve the payment method from there
        //$info = $this->getInfoInstance();
        //$method = $info->getAdditionalInformation(self::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD);

        $payment = $this->getInfoInstance()->getQuote()->getPayment();
        $additional_data = unserialize($payment->getAdditionalData());
        if (isset($additional_data[self::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD])) {
            return $additional_data[self::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD];
        } else {
            return null;
        }
    }

    public function getPaymentFeeForMethod($method)
    {
        $paymentFees = array();
        $paymentFeesStr = $this->getConfigData('preselect_paymentfee');
        $paymentFeesArr = explode(',', $paymentFeesStr);
        foreach ($paymentFeesArr as $k => $v) {
            $paymentFeeArr = explode('=', $v);
            $paymentFees[$paymentFeeArr[0]] = $paymentFeeArr[1];
        }

        if (isset($paymentFees[$method])) {
            return $paymentFees[$method];
        } else {
            return 0;
        }
    }

    /**
     * Check is method can be used on checkout.
     *
     * @return bool
     */
    public function canUseCheckout()
    {
        if ($this->getConfigData('preselect_payment_method')) {
            if ($this->getPaymentMethods()) {
                return $this->_canUseCheckout;
            } else {
                return false;
            }
        } else {
            return $this->_canUseCheckout;
        }
    }

    /**
     * Check method for processing with base currency
     *
     * @param string $currencyCode
     * @return boolean
     */
    public function canUseForCurrency($currencyCode)
    {
        if (in_array($currencyCode, $this->_allowCurrencyCode)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Override getConfigData, remove submethod name if set
     *
     * @param string $field
     * @param int|string|null|Mage_Core_Model_Store $storeId
     *
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if (null === $storeId) {
            $storeId = $this->getStore();
        }

        if (!in_array($field, array('active', 'model', 'title', 'sort_order'))) {
            $code = substr($this->getCode(), 0, 10);
        } else {
            $code = $this->getCode();
        }

        $path = 'payment/' . $code . '/' . $field;
        return Mage::getStoreConfig($path, $storeId);
    }

    /**
     * Retrieve payment method title
     *
     * @return string
     */
    public function getTitle()
    {
        // we are using preselection and we are pass the checkout step at this point (amount is not null),
        // so we can return the correct user selected method
        if ($this->getConfigData('preselect_payment_method') && $this->getInfoInstance()->getAmountOrdered() != null) {

            $infoInstance = $this->getInfoInstance();
            $additional_data = unserialize($infoInstance->getAdditionalData());
            if (isset($additional_data[self::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD_DESCRIPTION])) {
                $title = $additional_data[self::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD_DESCRIPTION];
            }

            return (isset($title)) ? $title : $this->getConfigData('title');
        } else {
            return $this->getConfigData('title');
        }
    }

    public function isDelayedCaptureCase($method)
    {
        $delayedMethods = $this->getConfigData('delayed_capture');
        if (strlen($delayedMethods) > 0) {
            $delayedMethods = explode(',', $delayedMethods);
        } else {
            $delayedMethods = array();
        }


        return in_array($method, $delayedMethods);
    }

    public function capture(Varien_Object $payment, $amount)
    {
        if (!$this->canCapture()) {
            Mage::throwException(Mage::helper('payment')->__('Capture action is not available.'));
        }

        $additional_data = unserialize($payment->getAdditionalData());
        if (isset($additional_data[self::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD])) {
            $method = $additional_data[self::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD];
        } else {
            $method = null;
        }

        if ($this->isDelayedCaptureCase($method)) {
            $result = $this->getGatewayImplementation()->addDeliveryInfo($payment);

            $payment->setTransactionId($result['pkg_id']);
            $payment->setIsTransactionClosed(0);
            $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $result);
        }

        return $this;
    }

    public function refund(Varien_Object $payment, $amount)
    {
        if (!$this->canRefund()) {
            Mage::throwException(Mage::helper('payment')->__('Refund action is not available.'));
        }

        $this->getGatewayImplementation()
            ->changePaymentTransaction($payment, $amount);

        return $this;

    }


}

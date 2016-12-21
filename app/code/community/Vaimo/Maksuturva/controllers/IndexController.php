<?php
/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */

/**
 * Maksuturva Checkout Controller
 */
class Vaimo_Maksuturva_IndexController extends Mage_Core_Controller_Front_Action
{
    // variables from GET on payment return
    protected $mandatoryFields = array(
        "pmt_action",
        "pmt_version",
        "pmt_id",
        "pmt_reference",
        "pmt_amount",
        "pmt_currency",
        "pmt_sellercosts",
        "pmt_paymentmethod",
        "pmt_escrow",
        "pmt_hash"
    );

    /**
     * After placing the order, render redirection page with appropriate form
     */
    public function redirectAction()
    {
        $response = $this->getLayout()->createBlock('maksuturva/redirect')->toHtml();
        $this->getResponse()->setBody($response);
    }

    /**
     * Success return from Maksuturva
     */
    public function successAction()
    {
        /** @var Mage_Checkout_Model_Session $session */
        $session = Mage::getSingleton('checkout/session');
        $params = $this->getRequest()->getParams();

        //Validate parameters
        // fields are mandatory, so we discard the request if it is empty
        // Also when return through the error url given to maksuturva
        foreach ($this->mandatoryFields as $field) {
            if (array_key_exists($field, $params)) {
                $values[$field] = $params[$field];
            } else {
                $this->_redirect('maksuturva/index/error', array('type' => Vaimo_Maksuturva_Model_Maksuturva::ERROR_EMPTY_FIELD, 'field' => $field));
                return;
            }
        }

        /** @var Vaimo_Maksuturva_Model_Maksuturva $method */
        $method = Mage::getModel('maksuturva/maksuturva');
        $implementation = $method->getGatewayImplementation();
        $calculatedHash = $implementation->generateReturnHash($values);

        if ($values['pmt_hash'] != $calculatedHash) {
            $this->_redirect('maksuturva/index/error', array('type' => Vaimo_Maksuturva_Model_Maksuturva::ERROR_INVALID_HASH));
            return;
        }

        $payment = Mage::getModel('sales/order_payment')->load($values['pmt_id'], 'maksuturva_pmt_id');
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($payment->getParentId());

        // Fallback for old method, will be removed in future
        if (!$order->getId()) {
            $order = $session->getLastRealOrder();
        }

        $implementation->setOrder($order);
        if (!$order->getId()) {
            Mage::getSingleton('core/session')->addError($this->__('Your order is not valid.'));
            $this->_redirect('checkout/cart');
            return;
        }
        if (!$order->canInvoice()) {
            Mage::getSingleton('core/session')->addSuccess($this->__('Your order is already paid.'));
            $this->_redirect('checkout/cart');
            return;
        }
        if ($order->getState() != Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            Mage::getSingleton('core/session')->addSuccess($this->__('Your order is already authorized.'));
            $this->_redirect('checkout/cart');
            return;
        }

        // validate amounts, values, etc
        // fields which will be ignored
        $form = $implementation->getForm();
        $ignore = array("pmt_hash", "pmt_escrow", "pmt_paymentmethod", "pmt_reference", "pmt_sellercosts");
        foreach ($values as $key => $value) {
            // just pass if ignore is on
            if (in_array($key, $ignore)) {
                continue;
            }
            //var_dump($key . ' received: ' . $value . ' had: ' . $impl->{$key});
            if ($form->{$key} != $value) {
                $this->_redirect('maksuturva/index/error', array('type' => Vaimo_Maksuturva_Model_Maksuturva::ERROR_VALUES_MISMATCH, 'message' => urlencode("different $key: $value != " . $form->{$key})));
                return;
            }
        }
        //check that sellercosts have not been altered to LOWER the order costs
        if ($form->{'pmt_sellercosts'} > $values['pmt_sellercosts']) {
            $this->_redirect('maksuturva/index/error', array('type' => Vaimo_Maksuturva_Model_Maksuturva::ERROR_SELLERCOSTS_VALUES_MISMATCH, 'message' => urlencode("Payment method returned shipping and payment costs of " . $values['pmt_sellercosts'] . " EUR. YOUR PURCHASE HAS NOT BEEN SAVED. Please contact the web store."), 'new_sellercosts' => $values['pmt_sellercosts'], 'old_sellercosts' => $form->{'pmt_sellercosts'}));
            return;
        }

        $isDelayedCapture = $method->isDelayedCaptureCase($values['pmt_paymentmethod']);
        $statusText = $isDelayedCapture ? "authorized" : "captured";

        //if sellercosts have increased, eg. for part payment, a comment is added to the order history
        if ($form->{'pmt_sellercosts'} != $values['pmt_sellercosts']) {
            $sellercosts_change = $values['pmt_sellercosts'] - $form->{'pmt_sellercosts'};
            if ($sellercosts_change > 0) {
                $msg = $this->__("Payment {$statusText} by Maksuturva. NOTE: Change in the sellercosts + {$sellercosts_change} EUR.");
            } else {
                $msg = $this->__("Payment {$statusText} by Maksuturva. NOTE: Change in the sellercosts {$sellercosts_change} EUR.");
            }
        } else {
            $msg = $this->__("Payment {$statusText} by Maksuturva");
        }

        try {
            if (!$isDelayedCapture) {
                $this->_createInvoice($order);
            }

            if (!$order->getEmailSent()) {
                try {
                    $order->sendNewOrderEmail();
                    $order->setEmailSent(true);
                } catch (Exception $e) {
                    Mage::logException($e);
                }
            }

            $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $msg, false);
            $order->save();

            // Support for Vaimo Order module
            Mage::dispatchEvent("ic_order_success", array("order" => $order));

            /* @var $quote Mage_Sales_Model_Quote */
            $quote = $session->getQuote();
            if ($quote->getId()) {
                $quote->setIsActive(false)->save();
            }

            $this->_redirect('checkout/onepage/success', array('_secure' => true));
        } catch (Exception $e) {
            $this->_redirect('maksuturva/index/error', array('type' => 9999));
        }

        return;
    }

    /**
     * Payment cancelled in Maksuturva
     */
    public function cancelAction()
    {
        $pmt_id = $this->getRequest()->getParam('pmt_id');
        $session = Mage::getSingleton('checkout/session');
        $order = Mage::getModel('sales/order');
        $incrementId = $session->getLastRealOrderId();

        if (empty($incrementId) || empty($pmt_id)) {
            $session->addError($this->__('Unknown error on maksuturva payment module.'));
            $this->_redirect('checkout/cart');
            return;
        }

        $order->loadByIncrementId($incrementId);
        $payment = $order->getPayment();
        $additional_data = unserialize($payment->getAdditionalData());
        // received pmt_id must always match to pmt_id on payment
        if ($additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_TRANSACTION_ID] !== $pmt_id) {
            $this->_redirect('checkout/cart');
            return;
        }

        if ($order->getState() == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            $order->setActionFlag(Mage_Sales_Model_Order::ACTION_FLAG_CANCEL, true);
            $order->cancel();
            $order->addStatusHistoryComment($this->__('Payment canceled on Maksuturva'), 'pay_aborted');
            $order->save();

            $session->addError($this->__('You have cancelled your payment on Maksuturva.'));
        } else {
            $session->addError($this->__('Unable cancel order that is already paid.'));
            $this->_redirect('checkout/cart');
            return;
        }

        $this->_redirect('checkout/onepage/failure');
    }

    /**
     * Payment error happened in Maksuturva
     */
    public function errorAction()
    {
        $pmt_id = $this->getRequest()->getParam('pmt_id');
        $session = Mage::getSingleton('checkout/session');
        $order = Mage::getModel('sales/order');
        $incrementId = $session->getLastRealOrderId();

        if (empty($incrementId) || empty($pmt_id)) {
            $session->addError($this->__('Unknown error on maksuturva payment module.'));
            $this->_redirect('checkout/cart');
            return;
        }

        $order->loadByIncrementId($incrementId);
        $payment = $order->getPayment();
        $additional_data = unserialize($payment->getAdditionalData());

        $paramsArray = $this->getRequest()->getParams();

        if (array_key_exists('pmt_id', $paramsArray)) {
            $session->addError($this->__('Maksuturva returned an error on your payment.'));
        } else {
            switch ($paramsArray['type']) {
                case Vaimo_Maksuturva_Model_Maksuturva::ERROR_INVALID_HASH:
                    $session->addError($this->__('Invalid hash returned'));
                    break;

                case Vaimo_Maksuturva_Model_Maksuturva::ERROR_EMPTY_FIELD:
                    $session->addError($this->__('Gateway returned and empty field') . ' ' . $paramsArray['field']);
                    break;

                case Vaimo_Maksuturva_Model_Maksuturva::ERROR_VALUES_MISMATCH:
                    $session->addError($this->__('Value returned from Maksuturva does not match:') . ' ' . $paramsArray['message']);
                    break;

                case Vaimo_Maksuturva_Model_Maksuturva::ERROR_SELLERCOSTS_VALUES_MISMATCH:
                    $session->addError($this->__('Shipping and payment costs returned from Maksuturva do not match.') . ' ' . $paramsArray['message']);
                    break;

                default:
                    $session->addError($this->__('Unknown error on maksuturva payment module.'));
                    break;
            }
        }

        // received pmt_id must always match to pmt_id on payment
        if ($additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_TRANSACTION_ID] !== $pmt_id) {
            $this->_redirect('checkout/cart');
            return;
        }

        if ($order->getState() == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            if (isset($paramsArray['type']) && $paramsArray['type'] == Vaimo_Maksuturva_Model_Maksuturva::ERROR_SELLERCOSTS_VALUES_MISMATCH) {
                $order->addStatusHistoryComment($this->__('Mismatch in seller costs returned from Maksuturva. New sellercosts: ' . $paramsArray["new_sellercosts"] . ' EUR,' . ' was ' . $paramsArray["old_sellercosts"] . ' EUR.'));
            } else {
                $order->addStatusHistoryComment($this->__('Error on Maksuturva return'));
            }

            $order->save();
            $this->_redirect('checkout/onepage/failure');
            return;
        }

        $this->_redirect('checkout/cart');
    }

    public function delayedAction()
    {
        //the order should remain on Pending Payment
        $session = Mage::getSingleton('checkout/session');

        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId()) {
                $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, $this->__('Waiting for delayed payment confirmation from Maksuturva'))->save();
            }
        }

        Mage::getModel('sales/quote')->load($session->getQuoteId())->setIsActive(false)->save();
        $this->_redirect('checkout/onepage/success', array('_secure' => true));
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @return bool|Mage_Sales_Model_Order_Invoice
     */
    protected function _createInvoice($order)
    {
        if (!$order->canInvoice()) {
            return false;
        }

        /**
         * Add transaction info to payment
         *
         * @var Mage_Sales_Model_Order_Payment $payment */
        $payment = $order->getPayment();
        $payment->setTransactionId($order->getPayment()->getMaksuturvaPmtId())
            ->setTransactionClosed(0);
        $order->save();

        /**
         * Create Invoice
         *
         * @var Mage_Sales_Model_Order_Invoice $invoice */
        $invoice = $order->prepareInvoice();
        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
        $invoice->register();

        /**
         * Create transaction
         */
        $payment->setCreatedInvoice($invoice);
        $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE, $invoice, true);

        if ($invoice->canCapture()) {
            $invoice->capture();
        }
        $invoice->save();
        $order->addRelatedObject($invoice);
        return $invoice;
    }
}
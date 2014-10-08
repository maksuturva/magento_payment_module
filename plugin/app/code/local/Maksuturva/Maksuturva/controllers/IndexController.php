<?php
/**
 * Maksuturva Payment Module
 * Creation date: 05/01/2012
 */

/**
 * Maksuturva Checkout Controller
 */
class Maksuturva_Maksuturva_IndexController extends Mage_Core_Controller_Front_Action
{
    // variables from GET on payment return
    var $mandatoryFields = array(
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
    
    public function redirectAction() 
    {
    	
		$session = Mage::getSingleton('checkout/session');
    	$currency = Mage::app()->getStore()->getCurrentCurrencyCode();
		if ($currency != 'EUR'){
	        Mage::getSingleton('core/session')->addError($this->__('Maksuturva accepts only Euro as currency.'));
        	$this->_redirect('checkout/cart');
			return;
		}
        
        $session->setMaksuturvaQuoteId($session->getQuoteId());
        $this->getResponse()->setBody($this->getLayout()->createBlock('maksuturva/redirect')->toHtml());
        Mage::getModel('sales/quote')->load($session->getQuoteId())->setIsActive(true)->save();
        $session->unsRedirectUrl();
    }
    
    public function successAction() {
        $returnParams = $this->getRequest()->getParams();
        
        //Validate parameters
        // fields are mandatory, so we discard the request if it is empty
        // Also when return through the error url given to maksuturva
        foreach ($this->mandatoryFields as $field) {
        	if (array_key_exists($field, $returnParams)) {
        	    $values[$field] = $returnParams[$field];
            } else {
                $this->_redirect('maksuturva/index/error', array('type' => Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_EMPTY_FIELD, 'field' => $field));
				return;
            }
        }
        
        $model = Mage::getModel('maksuturva/paymentMethod');
        $impl = $model->getGatewayImplementation();
        $calculatedHash = $impl->generateReturnHash($values);
        
        //var_dump('calculated: ' . $calculatedHash);
        //var_dump('received: ' . $values['pmt_hash']); 
		//exit;
        //check if the hashes match
        if ($values['pmt_hash'] != $calculatedHash) {
            $this->_redirect('maksuturva/index/error', array('type' => Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_INVALID_HASH));
			return;
        }
        
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getMaksuturvaQuoteId(true));
        Mage::getModel('sales/quote')->load($session->getQuoteId())->setIsActive(false)->save();
        $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
        if (!$order->canInvoice()){
            Mage::getSingleton('core/session')->addError($this->__('Your order is not valid or is already paid.'));
            $this->_redirect('checkout/cart'); 
            return;
        }
        
        
        // validate amounts, values, etc
    	// fields which will be ignored
    	$ignore = array("pmt_hash", "pmt_paymentmethod", "pmt_reference", "pmt_sellercosts");
    	foreach ($values as $key => $value) {
    		// just pass if ignore is on
    		if (in_array($key, $ignore)) {
    			continue;
    		}
    		//var_dump($key . ' received: ' . $value . ' had: ' . $impl->{$key});
    		if ($impl->{$key} != $value) {
    		    $this->_redirect('maksuturva/index/error', array('type' => Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_VALUES_MISMATCH, 'message' => urlencode("different $key: $value != " . $impl->{$key})));
				return;    
    		}
    	}
    	//check that sellercosts have not been altered to LOWER the order costs
    	if($impl->{'pmt_sellercosts'} > $values['pmt_sellercosts']){
    		$this->_redirect('maksuturva/index/error', array('type' => Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_SELLERCOSTS_VALUES_MISMATCH, 'message' => urlencode("Payment method returned shipping and payment costs of ".$values['pmt_sellercosts']." EUR. YOUR PURCHASE HAS NOT BEEN SAVED. Please contact the web store."), 'new_sellercosts' => $values['pmt_sellercosts'], 'old_sellercosts' => $impl->{'pmt_sellercosts'} ));
    		return;
    	}
        
        

        
        if ($session->getLastRealOrderId()) {
            
            if ($order->getId()) {
            	//if sellercosts have increased, eg. for part payment, a comment is added to the order history
            	if($impl->{'pmt_sellercosts'} != $values['pmt_sellercosts']) {
            		$sellercosts_change = $values['pmt_sellercosts'] - $impl->{'pmt_sellercosts'};
            		if($sellercosts_change > 0){
            			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->__('Payment confirmed by Maksuturva. NOTE: Change in the sellercosts + '.$sellercosts_change.' EUR.'))->save();
            		} else {
            			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->__('Payment confirmed by Maksuturva. NOTE: Change in the sellercosts '.$sellercosts_change.' EUR.'))->save();
            		}
            	} else {
            		$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->__('Payment confirmed by Maksuturva'))->save();
            	}     


                // Automatically create invoice
        		$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
        		$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
        		$invoice->register();
        		$transaction = Mage::getModel('core/resource_transaction')
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder());
        		$transaction->save();


				 $this->_redirect('checkout/onepage/success', array('_secure'=>true));
				 //addition rev. 124
					 try {
					 	if(1 == Mage::getStoreConfig('sales_email/order/enabled', Mage::app()->getStore())){
						 	$order->sendNewOrderEmail();
						 	$order->setEmailSent(true);
						 	$order->save();
				 			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->__('New Order email sent to customer'))->save();
				 		}
				 		else {
				 			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->__('New Order emails disabled'))->save();
				 		}
					 } catch (Exception $e) {
					 	Mage::logException($e);
					 }
				 
				 return;
            }
        }
        $this->_redirect('maksuturva/index/error', array('type'=>9999));
       
    }
    
    public function cancelAction() {
        $session = Mage::getSingleton('checkout/session');
        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId()) {
                $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, $this->__('Payment canceled on Maksuturva'))->save();
            }
        }
        Mage::getSingleton('core/session')->addError($this->__('You have cancelled your payment on Maksuturva.'));
        $this->_redirect('checkout/cart');
    }
    
    public function errorAction() {
        $session = Mage::getSingleton('core/session');
        
        $paramsArray = $this->getRequest()->getParams();
        
        if (array_key_exists('pmt_id', $paramsArray)) {
            $session->addError($this->__('Maksuturva returned an error on your payment.'));
        } else {
            switch ($paramsArray['type']) {
                case Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_INVALID_HASH:
                    $session->addError($this->__('Invalid hash returned'));
                break;
                
                case Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_EMPTY_FIELD:
                    $session->addError($this->__('Gateway returned and empty field') . ' ' . $paramsArray['field']);    
                break;
                
                case Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_VALUES_MISMATCH:
                    $session->addError($this->__('Value returned from Maksuturva does not match:') . ' ' . $paramsArray['message']);    
                break;
                
                case Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_SELLERCOSTS_VALUES_MISMATCH:
                	$session->addError($this->__('Shipping and payment costs returned from Maksuturva do not match.') . ' ' . $paramsArray['message']);
                break;
                	
                default:
                    $session->addError($this->__('Unknown error on maksuturva payment module.'));
                break;
            }
        }
        
        $session->setQuoteId($session->getMaksuturvaQuoteId(true));
        Mage::getModel('sales/quote')->load($session->getQuoteId())->setIsActive(true)->save();
        
        $checkoutSession = Mage::getSingleton('checkout/session');
        if ($checkoutSession->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($checkoutSession->getLastRealOrderId());
            if ($order->getId()) {
	            if($paramsArray['type'] == Maksuturva_Maksuturva_Model_PaymentMethod::ERROR_SELLERCOSTS_VALUES_MISMATCH) {
	            	$order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, $this->__('Order cancelled because of mismatch in seller costs returned from Maksuturva. New sellercosts: '.$paramsArray["new_sellercosts"].' EUR,'.' was '.$paramsArray["old_sellercosts"].' EUR.'))->save();
		        }
		        else {
		            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, $this->__('Order cancelled because of error on Maksuturva return'))->save();
		        }
            }
        }
        
        $this->_redirect('checkout/cart');
    }
    
    public function delayedAction() {
        //the order should remain on Pending Payment
        $session = Mage::getSingleton('checkout/session');
        
        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId()) {
                $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, $this->__('Waiting for delayed payment confirmation from Maksuturva'))->save();
            }
        }
        
        $session->setQuoteId($session->getMaksuturvaQuoteId(true));
        Mage::getModel('sales/quote')->load($session->getQuoteId())->setIsActive(false)->save();
        $this->_redirect('checkout/onepage/success', array('_secure'=>true));
    }
    
}

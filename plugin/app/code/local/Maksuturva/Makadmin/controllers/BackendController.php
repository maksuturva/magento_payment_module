<?php
/**
 * Maksuturva Payment Module
 * Creation date: 05/01/2012
 */

/**
 * Maksuturva Verify Controller
 */
class Maksuturva_Makadmin_BackendController extends Mage_Adminhtml_Controller_Action
{
    
    public function verifyAction() {
        $orderId = $this->getRequest()->getParam('order_id');
        
        $model = Mage::getModel('maksuturva/paymentMethod');
        $impl = $model->getGatewayImplementation($orderId);
        
        $order = $impl->getOrder();
        
        $config = $model->getConfigs();
        $data = array('pmtq_keygeneration' => $config['keyversion']);
        
        try {
    		$response = $impl->statusQuery($data);
    	} catch (Exception $e) {
    		// next status query verification
    		$session = Mage::getSingleton('core/session');
	        $session->addError($e->getMessage());
		    $this->_redirect('/../admin/sales_order/view', array('order_id' => $orderId, '_secure'=>true));
    	}

    	// errors
    	if ($response === false) {
    	    $session = Mage::getSingleton('core/session');
	        $session->addError($this->__("Invalid hash or network error."));
		    $this->_redirect('/../admin/sales_order/view', array('order_id' => $orderId, '_secure'=>true));
    	}
    	
        switch ($response["pmtq_returncode"]) {
	    		// set as paid if not already set
	    		case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_PAID:
	    		case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_PAID_DELIVERY:
	    		case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_COMPENSATED:
	    			//Mark the order paid
	    			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->__('Payment identified by Maksuturva'))->save();
                    
                    if($order->hasInvoices() == false) {
                        if($order->canInvoice()) {
                            
                            $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                    		$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                    		$invoice->register();
                    		$transaction = Mage::getModel('core/resource_transaction')
                                        ->addObject($invoice)
                                        ->addObject($invoice->getOrder());
                    		$transaction->save();
                            $session = Mage::getSingleton('core/session');
                            $session->addSuccess($this->__('Payment identified by Maksuturva. Invoice saved.'));
                        }
                    }
                    else {
	    			    $session = Mage::getSingleton('core/session');
    			        $session->addSuccess($this->__('Payment identified by Maksuturva. invoices exist'));
    			    }
    			    $this->_redirect('/../admin/sales_order/view', array('order_id' => $orderId, '_secure'=>true));
	    			break;

	    		// set payment cancellation with the notice
	    		// stored in response_text
	    		case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_PAYER_CANCELLED:
    			case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_PAYER_CANCELLED_PARTIAL:
    			case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_PAYER_CANCELLED_PARTIAL_RETURN:
    			case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_PAYER_RECLAMATION:
    			case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_CANCELLED:
    				//Mark the order cancelled
    				$order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, $this->__('Payment canceled by Maksuturva'))->save();
    				
    				$session = Mage::getSingleton('core/session');
    			    $session->addSuccess($this->__('Payment canceled by Maksuturva'));
    			    $this->_redirect('/../admin/sales_order/view', array('order_id' => $orderId, '_secure'=>true));
    				break;

    	        // no news for buyer and seller
	    		case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_NOT_FOUND:
	    		case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_FAILED:
	    		case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_WAITING:
    			case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_UNPAID:
    			case Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation::STATUS_QUERY_UNPAID_DELIVERY:
    			default:
    				// no action here
    			    $session = Mage::getSingleton('core/session');
    			    $session->addSuccess($this->__('No change, still awaiting payment'));
    			    $this->_redirect('/../admin/sales_order/view', array('order_id' => $orderId, '_secure'=>true));
	    			break;
	    	}
    }
    
}
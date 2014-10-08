<?php
/**
 * Maksuturva Payment Module
 * Creation date: 05/01/2012
 */
 
/**
* Our test CC module adapter
*/
class Maksuturva_Maksuturva_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
{
    
    const ERROR_INVALID_HASH = 'invalid_hash_error';
    const ERROR_EMPTY_FIELD = 'empty_field_error';
    const ERROR_VALUES_MISMATCH = 'values_mismatch_error';
    const ERROR_SELLERCOSTS_VALUES_MISMATCH = 'sellercosts_values_mismatch_error';
    
    /**
    * unique internal payment method identifier
    *
    * @var string [a-z0-9_]
    */
    protected $_code = 'maksuturva';
 
    /**
     * Here are examples of flags that will determine functionality availability
     * of this module to be used by frontend and backend.
     *
     * @see all flags and their defaults in Mage_Payment_Model_Method_Abstract
     *
     * It is possible to have a custom dynamic logic by overloading
     * public function can* for each flag respectively
     */
     
    /**
     * Is this payment method a gateway (online auth/charge) ?
     */
    protected $_isGateway               = true;
 
    /**
     * Can authorize online?
     */
    protected $_canAuthorize            = true;
 
    /**
     * Can capture funds online?
     */
    protected $_canCapture              = true;
 
    /**
     * Can capture partial amounts online?
     */
    protected $_canCapturePartial       = false;
 
    /**
     * Can refund online?
     */
    protected $_canRefund               = false;
 
    /**
     * Can void transactions online?
     */
    protected $_canVoid                 = true;
 
    /**
     * Can use this payment method in administration panel?
     */
    protected $_canUseInternal          = true;
 
    /**
     * Can show this payment method as an option on checkout payment page?
     */
    protected $_canUseCheckout          = true;
 
    /**
     * Is this payment method suitable for multi-shipping checkout?
     */
    protected $_canUseForMultishipping  = false;
 
    /**
     * Can save credit card information for future processing?
     */
    protected $_canSaveCc = false;
    
    protected $_isInitializeNeeded = true;
    
    protected $_formBlockType = 'maksuturva/form';
 
    
    public function getOrderPlaceRedirectUrl() {
        return Mage::getUrl('maksuturva/index/redirect', array('_secure' => true));
    }
    
    /**
     * Return form field array to
     * submit to maksuturva
     *
     * @return array
     */
    public function getCheckoutFormFields()
    {   
        $impl = $this->getGatewayImplementation();
        
        return $impl->getFieldArray();
    }
    
    /**
     * Return an intance of MaksuturvaGatewayImplementation
     * with proper order and module configuration data.
     * 
     * @return Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation
     */
    public function getGatewayImplementation($orderId = null)
    {   
        if ($orderId) {
            $order = Mage::getModel('sales/order')->load($orderId);
        } else {
            $orderIncrementId = $this->getCheckout()->getLastRealOrderId();
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);   
        }
        
        $config = $this->getConfigs();
        $config['orderId'] = $order->getId();
       
        return new Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation($order, $config);
    }
    
    
    public function getConfigs()
    {
        $config = array(
            'sandbox' => intval($this->getConfigData('sandboxmode')),
            'commurl' => $this->getConfigData('commurl'),
            'commencoding' => $this->getConfigData('commencoding'),
            'emaksut' => intval($this->getConfigData('emaksut')),
            'secretKey' => $this->getConfigData('secretkey'),
            'sellerId' => $this->getConfigData('sellerid'),
            'paymentdue' => intval($this->getConfigData('paymentdue')),
            'keyversion' => $this->getConfigData('keyversion')
        );
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
    
	/**
     * Get paypal session namespace
     *
     * @return Mage_Paypal_Model_Session
     */
    public function getSession()
    {
        return Mage::getSingleton('paypal/session');
    }
    
    public function getRequestUrl()
    {
         return MaksuturvaGatewayAbstract::getPaymentUrl($this->getConfigData('commurl'));   
    }
	
}
?>
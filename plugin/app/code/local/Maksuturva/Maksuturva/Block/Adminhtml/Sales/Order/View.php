<?php

class Maksuturva_Maksuturva_Block_Adminhtml_Sales_Order_View extends Mage_Adminhtml_Block_Sales_Order_View {
    
    public function  _construct() 
    {
        $order = $this->getOrder();
        $method = $order->getPayment()->getMethod();
        
        if ($method == 'maksuturva') {
            $this->_addButton('verify_payment', array(
                'label'     => 'Verify Payment',
                'onclick'   => 'setLocation(\'' . $this->getVerifyUrl($order) . '\')',
                'class'     => 'go'
            ), 0, 100, 'header', 'header');
        }
        
        parent::_construct();
    }
    
    public function getVerifyUrl($order) 
    {
        return Mage::helper("adminhtml")->getUrl('makadmin/backend/verify', array('order_id' => $order->getId()));
    }
}

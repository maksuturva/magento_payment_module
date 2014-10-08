<?php
/**
 * Maksuturva Payment Module
 * Creation date: 05/01/2012
 */

/**
 * Maksuturva payment "form"
 */
class Maksuturva_Maksuturva_Block_Form extends Mage_Payment_Block_Form
{
    /**
     * Set template and redirect message
     */
    protected function _construct()
    {
        
        $emaksut = intval(Mage::getModel('maksuturva/paymentMethod')->getConfigData('emaksut'));
        if ($emaksut) {
            $this->setMethodTitle('eMaksut');
        } else {
            $this->setMethodTitle('Maksuturva');
        }
        parent::_construct();
    }
    
    public function _toHTML(){
        $currency = Mage::app()->getStore()->getCurrentCurrencyCode();
        if ($currency != 'EUR'){
            //Mage::getSingleton('core/session')->addError($this->__('Maksuturva accepts only Euro as currency.'));
            return '<strong><span style="color: red">' . $this->__('Warning! Only Euro currency is supported for this payment method.') . '</span></strong>';
        }
        return '';
    }

}

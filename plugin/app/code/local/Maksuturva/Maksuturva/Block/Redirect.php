<?php
/**
 * Maksuturva Payment Module
 * Creation date: 05/01/2012
 */

class Maksuturva_Maksuturva_Block_Redirect extends Mage_Core_Block_Abstract
{
    protected function _toHtml()
    {
        $model = Mage::getModel('maksuturva/paymentMethod');
        
        $fields = $model->getCheckoutFormFields();
        
        $form = new Varien_Data_Form();
        $form->setAction($model->getRequestUrl())
            ->setId('maksuturva_checkout')
            ->setName('maksuturva_checkout')
            ->setMethod('POST')
            ->setUseContainer(true);
        foreach ($fields as $field=>$value) {
            $form->addField($field, 'hidden', array('name'=>$field, 'value'=>$value));
        }
        $html = '<html><body>';
        $html.= $this->__('You will be redirected to the Maksuturva website in a few seconds.');
        $html.= $form->toHtml();
        $html.= '<script type="text/javascript">document.getElementById("maksuturva_checkout").submit();</script>';
        $html.= '</body></html>';

        return $html;
    }
}

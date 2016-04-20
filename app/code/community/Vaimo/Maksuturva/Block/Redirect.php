<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Block_Redirect extends Mage_Core_Block_Abstract
{
    protected function _toHtml()
    {
        $method = Mage::getModel('maksuturva/maksuturva');

        $fields = $method->getCheckoutFormFields();

        $form = new Varien_Data_Form();
        $form->setAction($method->getPaymentRequestUrl())
            ->setId('maksuturva_checkout')
            ->setName('maksuturva_checkout')
            ->setMethod('POST')
            ->setUseContainer(true);
        foreach ($fields as $field => $value) {
            $form->addField($field, 'hidden', array('name' => $field, 'value' => $value));
        }
        $html = '<html><head></head><body>';
        $html .= $this->__('You will be redirected to the Maksuturva website in a few seconds.');
        $html .= $form->toHtml();
        $html .= '<script type="text/javascript">document.getElementById("maksuturva_checkout").submit();</script>';
        $html .= '</body></html>';

        return $html;
    }
}

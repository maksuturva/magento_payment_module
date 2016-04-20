<?php
/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */

/**
 * Example model to filter and set custom block for split payment method
 *
 * In addition to defining this, you'll need to rewrite or patch default Maksuturva method not to return "split off" methods in dropdown
 *
 * Class Vaimo_Customermodule_Model_Maksuturva_Fi01
 */
class Vaimo_Customermodule_Model_Maksuturva_Fi01 extends Vaimo_Maksuturva_Model_Maksuturva
{
    protected $_code = 'maksuturva_fi01';

    // use for custom block template & logic
    protected $_formBlockType = 'customermodule/maksuturva_bank';
    // use for Magento default style radio button
    //protected $_formBlockType = 'payment/form';

    /**
     * Filter payment methods returned by this split method
     *
     * @return array
     */
    public function getPaymentMethods()
    {
        $methods = parent::getPaymentMethods();

        if (is_array($methods)) {
            $filtered_methods = array_filter($methods, function ($item) {
                return ($item->code == "FI01") ? true : false;
            });
        } else {
            $filtered_methods = array();
        }

        return $filtered_methods;
    }
}

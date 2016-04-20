<?php

/**
 * Example block definition to set custom template for split payment method
 *
 * If default radio button style is wanted, overriding the block is not needed.
 *
 * Class Vaimo_Customermodule_Block_Maksuturva_Bank
 */
class Vaimo_Customermodule_Block_Maksuturva_Bank extends Vaimo_Maksuturva_Block_Form
{
    /**
     * Set template and redirect message
     */
    protected function _construct()
    {
        parent::_construct();

        $this->setTemplate('maksuturva/bank.phtml');
    }
}

<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Model_Source_Encoding
{
    /**
     * Options getter
     *
     * @return array
     * @deprecated
     */
    public function toOptionArray()
    {
        return array(
            array('value' => 'UTF-8', 'label' => Mage::helper('adminhtml')->__(' UTF-8')),
            array('value' => 'ISO-8859-1', 'label' => Mage::helper('adminhtml')->__(' ISO-8859-1')),
        );
    }
}
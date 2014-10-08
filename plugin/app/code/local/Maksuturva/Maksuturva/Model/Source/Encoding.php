<?php
/**
 * Used in creating options for UTF-8|ISO-8859-1 config value selection
 *
 */
class Maksuturva_Maksuturva_Model_Source_Encoding
{

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array('value' => 'UTF-8', 'label'=>Mage::helper('adminhtml')->__(' UTF-8')),
            array('value' => 'ISO-8859-1', 'label'=>Mage::helper('adminhtml')->__(' ISO-8859-1')),
        );
    }

}
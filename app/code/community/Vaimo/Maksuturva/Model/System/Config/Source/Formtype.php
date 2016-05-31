<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Model_System_Config_Source_Formtype
{
    public function toOptionArray()
    {
        $array = array(
            array('value' => Vaimo_Maksuturva_Block_Form::FORMTYPE_DROPDOWN, 'label' => 'Dropdown'),
            array('value' => Vaimo_Maksuturva_Block_Form::FORMTYPE_ICONS, 'label' => 'Icons'),
        );

        return $array;
    }
}

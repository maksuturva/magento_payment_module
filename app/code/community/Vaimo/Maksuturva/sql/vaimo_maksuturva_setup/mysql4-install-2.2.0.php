<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */

$installer = $this;
$connection = $installer->getConnection();

$installer->startSetup();

$installer->addAttribute('order_payment', 'maksuturva_pmt_id', array());

$installer->getConnection()->addIndex($installer->getTable('sales/order_payment'),
    $installer->getIdxName('sales/order_payment', array(
        'maksuturva_pmt_id'
    )),
    'maksuturva_pmt_id',
    Varien_Db_Adapter_Interface::INDEX_TYPE_INDEX
);

$installer->endSetup();
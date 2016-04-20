<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Model_Cron
{
    /**
     * Poll through unpaid Maksuturva orders and check if payment has been received by Maksuturva
     *
     * @param $schedule
     */
    public function checkPaymentStatus($schedule)
    {
        if (!Mage::getStoreConfigFlag('payment/maksuturva/cron_active')) {
            return;
        }

        Mage::log("starting maksuturva order status check", null, 'maksuturva_cron.log');
        $model = Mage::getModel('maksuturva/maksuturva');

        $jobsRoot = Mage::getConfig()->getNode('crontab/jobs');
        $jobConfig = $jobsRoot->{$schedule->getJobCode()};
        $lookback = (string)$jobConfig->lookback;

        $from = new DateTime();
        $from->modify($lookback);

        // let's give some time for user to complete payment in normal way
        $to = new DateTime();
        $to->modify("-15 minutes");

        // set to UTC, to get filterable UTC time
        $from->setTimezone(new DateTimeZone('UTC'));
        $to->setTimezone(new DateTimeZone('UTC'));

        $orderCollection = Mage::getModel("sales/order")->getCollection()
            ->join(array('payment' => 'sales/order_payment'), 'main_table.entity_id=parent_id', 'method')
            ->addFieldToFilter('status', "pending_payment")
            ->addFieldToFilter('method', "maksuturva")
            ->addAttributeToFilter('created_at', array('gteq' => $from->format('Y-m-d H:i:s'), 'lt' => $to->format('Y-m-d H:i:s')))
            ->addAttributeToSort('created_at', 'ASC');

        Mage::log("found " . count($orderCollection) . " orders to check", null, 'maksuturva_cron.log');

        foreach ($orderCollection as $order) {
            $order->load();
            Mage::log("checking " . $order->getIncrementId(), null, 'maksuturva_cron.log');
            $implementation = $model->getGatewayImplementation();
            $implementation->setOrder($order);

            $config = $model->getConfigs();
            $data = array('pmtq_keygeneration' => $config['keyversion']);

            try {
                $response = $implementation->statusQuery($data);
                $result = $implementation->ProcessStatusQueryResult($response);
                Mage::log($result['message'], null, 'maksuturva_cron.log');
            } catch (Exception $e) {
            }
        }

        Mage::log("finished order status check", null, 'maksuturva_cron.log');
    }
}
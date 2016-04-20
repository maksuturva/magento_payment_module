<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
class Vaimo_Maksuturva_Model_Gateway_Implementation extends Vaimo_Maksuturva_Model_Gateway_Abstract
{
    const MAKSUTURVA_PKG_RESULT_SUCCESS = 00;
    const MAKSUTURVA_PKG_RESULT_PAYMENT_NOT_FOUND = 10;
    const MAKSUTURVA_PKG_RESULT_FAILED = 11;
    const MAKSUTURVA_PKG_RESULT_NO_SERVICE = 12;
    const MAKSUTURVA_PKG_RESULT_INVALID_FORMAT = 20;
    const MAKSUTURVA_PKG_RESULT_EXISTING_PKG = 30;
    const MAKSUTURVA_PKG_RESULT_PKG_NOT_FOUND = 31;
    const MAKSUTURVA_PKG_RESULT_INVALID_METHODID = 40;
    const MAKSUTURVA_PKG_RESULT_METHOD_NOT_ALLOWED = 41;
    const MAKSUTURVA_PKG_RESULT_FORCED_UPDATE_REQUIRED = 42;
    const MAKSUTURVA_PKG_RESULT_ERROR = 99;

    private $sellerId = "";
    private $order = null;
    private $form = null;
    protected $preSelectPaymentMethod;
    protected $helper;

    function __construct($config)
    {
        $this->sellerId = $config['sellerId'];
        $this->secretKey = $config['secretKey'];
        $this->commUrl = $config['commurl'];
        $this->commEncoding = $config['commencoding'];
        $this->paymentDue = $config['paymentdue'];
        $this->keyVersion = $config['keyversion'];
        $this->preSelectPaymentMethod = $config['preselect_payment_method'];
        $this->helper = Mage::helper('maksuturva');

        parent::__construct();
    }

    public function getForm()
    {
        if (!$this->form) {

            if ($this->getOrder() instanceof Mage_Sales_Model_Order) {
                $order = $this->getOrder();
            } else {
                throw new Exception("order not found");
            }

            $dueDate = date("d.m.Y", strtotime("+" . $this->paymentDue . " day"));

            $items = $order->getAllItems();
            $itemcount = count($items);
            $orderData = $order->getData();
            $totalAmount = 0;
            $totalSellerCosts = 0;

            //Adding each product from order
            $taxInfo = $order->getFullTaxInfo();
            $products_rows = array();
            foreach ($items as $itemId => $item) {
                $itemData = $item->getData();
                $productName = $item->getName();
                $productDescription = $item->getProduct()->getShortDescription() ? $item->getProduct()->getShortDescription() : "SKU: " . $item->getSku();

                $sku = $item->getSku();
                if (mb_strlen($sku) > 10) {
                    $sku = mb_substr($sku, 0, 10);
                }

                $row = array(
                    'pmt_row_name' => $productName,                                                        //alphanumeric        max lenght 40             -
                    'pmt_row_desc' => $productDescription,                                                       //alphanumeric        max lenght 1000      min lenght 1
                    'pmt_row_quantity' => str_replace('.', ',', sprintf("%.2f", $item->getQtyToInvoice())),                                                     //numeric             max lenght 8         min lenght 1
                    'pmt_row_articlenr' => $sku,
                    'pmt_row_deliverydate' => date("d.m.Y"),                                                   //alphanumeric        max lenght 10        min lenght 10        dd.MM.yyyy
                    'pmt_row_price_net' => str_replace('.', ',', sprintf("%.2f", $item->getPrice())),          //alphanumeric        max lenght 17        min lenght 4         n,nn
                    'pmt_row_vat' => str_replace('.', ',', sprintf("%.2f", $itemData["tax_percent"])),                  //alphanumeric        max lenght 5         min lenght 4         n,nn
                    'pmt_row_discountpercentage' => "0,00",                                                    //alphanumeric        max lenght 5         min lenght 4         n,nn
                    'pmt_row_type' => 1,
                );

                //CONFIGURABLE PRODUCT - PARENT
                //copies child's name, shortdescription and SKU as parent's

                if ($item->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE && $item->getChildrenItems() != null && sizeof($item->getChildrenItems()) > 0) {
                    $children = $item->getChildrenItems();

                    if (sizeof($children) != 1) {
                        error_log("Maksuturva module FAIL: more than one children for configurable product!");
                        continue;
                    }

                    if (in_array($items[$itemId + 1], $children) == false) {
                        error_log("Maksuturva module FAIL: No children in order!");
                        continue;
                    }

                    $child = $children[0];
                    $row['pmt_row_name'] = $child->getName();
                    $childSku = $child->getSku();

                    if (strlen($childSku) > 0) {
                        if (mb_strlen($childSku) > 10) {
                            $childSku = mb_substr($childSku, 0, 10);
                        }

                        $row['pmt_row_articlenr'] = $childSku;
                    }
                    if (strlen($child->getProduct()->getShortDescription()) > 0) {
                        $row['pmt_row_desc'] = $child->getProduct()->getShortDescription();
                    }
                    $totalAmount += $itemData["price_incl_tax"] * $item->getQtyToInvoice();

                }

                //CONFIGURABLE PRODUCT - CHILD
                //as child's information already copied to parent's row, no child row is displayed

                else if ($item->getParentItem() != null && $item->getParentItem()->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
                    continue;
                }
                //BUNDLED PRODUCT - PARENT
                //bundled product parents won't be charged in invoice so unline other products, the quantity is fetched from qtyOrdered,
                //price will be nullified as the prices are available in children

                else if ($item->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE && $item->getChildrenItems() != null && sizeof($item->getChildrenItems()) > 0) {
                    $row['pmt_row_quantity'] = str_replace('.', ',', sprintf("%.2f", $item->getQtyOrdered()));
                    if ($item->getProduct()->getPriceType() == 0) { //if price is fully dynamic
                        $row['pmt_row_price_net'] = str_replace('.', ',', sprintf("%.2f", '0'));
                    } else {
                        $totalAmount += $itemData["price_incl_tax"] * $item->getQtyOrdered();
                    }
                    $row['pmt_row_type'] = 4; //mark product as tailored product
                }

                //BUNDLED PRODUCT - CHILD
                //the quantity information with parent's quantity is added to child's description

                else if ($item->getParentItem() != null && $item->getParentItem()->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE) {
                    $parentQty = $item->getParentItem()->getQtyOrdered();

                    if (intval($parentQty, 10) == $parentQty) {
                        $parentQty = intval($parentQty, 10);
                    }

                    $unitQty = $item->getQtyOrdered() / $parentQty;

                    if (intval($unitQty, 10) == $unitQty) {
                        $unitQty = intval($unitQty, 10);
                    }

                    $row['pmt_row_name'] = $unitQty . " X " . $parentQty . " X " . $item->getName();
                    $row['pmt_row_quantity'] = str_replace('.', ',', sprintf("%.2f", $item->getQtyOrdered()));
                    $totalAmount += $itemData["price_incl_tax"] * $item->getQtyOrdered();
                    $row['pmt_row_type'] = 4; //mark product as taloired product - by default not returnable

                } //SIMPLE OR GROUPED PRODUCT
                else {
                    $totalAmount += $itemData["price_incl_tax"] * $item->getQtyToInvoice();
                }
                array_push($products_rows, $row);
            }

            // row type 6
            $discount = 0;
            if ($orderData["discount_amount"] != 0) {
                $discount = $orderData["discount_amount"];
                if ($discount > ($orderData["shipping_amount"] + $totalAmount)) {
                    $discount = ($orderData["shipping_amount"] + $totalAmount);
                }
                $row = array(
                    'pmt_row_name' => "Discount",
                    'pmt_row_desc' => "Discount: " . $orderData["discount_description"],
                    'pmt_row_quantity' => 1,
                    'pmt_row_deliverydate' => date("d.m.Y"),
                    'pmt_row_price_net' =>
                        str_replace(
                            '.',
                            ',',
                            sprintf(
                                "%.2f",
                                $discount
                            )
                        ),
                    'pmt_row_vat' => str_replace('.', ',', sprintf("%.2f", 0)),
                    'pmt_row_discountpercentage' => "0,00",
                    'pmt_row_type' => 6, // discounts
                );
                array_push($products_rows, $row);
            }
            $totalAmount += $discount;

            // Adding the shipping cost as a row
            $shippingDescription = ($order->getShippingDescription() ? $order->getShippingDescription() : 'Free Shipping');

            $shippingCost = $orderData["shipping_amount"];

            $taxId = Mage::helper('tax')->getShippingTaxClass(Mage::app()->getStore()->getId());
            $request = Mage::getSingleton('tax/calculation')->getRateRequest();
            $request->setCustomerClassId($this->_getCustomerTaxClass())
                ->setProductClassId($taxId);
            $shippingTax = $orderData["shipping_tax_amount"];
            $shippingTaxRate = floatval(Mage::getSingleton('tax/calculation')->getRate($request));

            $row = array(
                'pmt_row_name' => $this->helper->__('Shipping'),
                'pmt_row_desc' => $shippingDescription,
                'pmt_row_quantity' => 1,
                'pmt_row_deliverydate' => date("d.m.Y"),
                'pmt_row_price_net' => str_replace('.', ',', sprintf("%.2f", $shippingCost)),
                'pmt_row_vat' => str_replace('.', ',', sprintf("%.2f", $shippingTaxRate)),
                'pmt_row_discountpercentage' => "0,00",
                'pmt_row_type' => 2,
            );
            $totalSellerCosts += $shippingCost + $shippingTax;
            array_push($products_rows, $row);

            // Payment Fee (Vaimo)
            if ($fee = $order->getBaseVaimoPaymentFee()) {
                $feeTax = $order->getBaseVaimoPaymentFeeTax();
                $feeTaxPercent = round($feeTax / $fee * 100); // this is simplification, because we don't store actual used tax percentage anywhere

                $row = array(
                    'pmt_row_name' => $this->helper->__('Payment fee'),
                    'pmt_row_desc' => $this->helper->__('Payment fee'),
                    'pmt_row_quantity' => 1,
                    'pmt_row_deliverydate' => date("d.m.Y"),
                    'pmt_row_price_net' => str_replace('.', ',', sprintf("%.2f", $fee)),
                    'pmt_row_vat' => str_replace('.', ',', sprintf("%.2f", $feeTaxPercent)),
                    'pmt_row_discountpercentage' => "0,00",
                    'pmt_row_type' => 3,
                );
                array_push($products_rows, $row);
                $totalSellerCosts += $fee + $feeTax;
                //$totalAmount += $fee;
            }

            // Gift Card payment (Vaimo)
            if ($order->getGiftCardsAmount() > 0) {
                $giftCardDescription = array();
                if ($giftCards = $order->getGiftCards()) {
                    if ($giftCards = @unserialize($giftCards)) {
                        if (is_array($giftCards)) {
                            foreach ($giftCards as $giftCard) {
                                $giftCardDescription[] = $giftCard['c'];
                            }
                        }
                    }
                }
                $giftCardDescription = implode(', ', $giftCardDescription);
                $row = array(
                    'pmt_row_name' => 'Gift Card',
                    'pmt_row_desc' => $giftCardDescription,
                    'pmt_row_quantity' => 1,
                    'pmt_row_deliverydate' => date('d.m.Y'),
                    'pmt_row_price_net' => str_replace('.', ',', sprintf('%.2f', -$order->getGiftCardsAmount())),
                    'pmt_row_vat' => '0,00',
                    'pmt_row_discountpercentage' => '0,00',
                    'pmt_row_type' => 6,
                );
                array_push($products_rows, $row);
                $totalAmount -= $order->getGiftCardsAmount();
            }

            // Store credit (Vaimo)
            if ($order->getCustomerBalanceAmount() > 0) {
                $row = array(
                    'pmt_row_name' => 'Store Credit',
                    'pmt_row_desc' => 'Store Credit',
                    'pmt_row_quantity' => 1,
                    'pmt_row_deliverydate' => date('d.m.Y'),
                    'pmt_row_price_net' => str_replace('.', ',', sprintf('%.2f', -$order->getCustomerBalanceAmount())),
                    'pmt_row_vat' => '0,00',
                    'pmt_row_discountpercentage' => '0,00',
                    'pmt_row_type' => 6,
                );
                array_push($products_rows, $row);
                $totalAmount -= $order->getCustomerBalanceAmount();
            }

            $options = array();
            $options["pmt_keygeneration"] = $this->keyVersion;


            // store unique transaction id on payment object for later retrieval
            $payment = $order->getPayment();
            $additional_data = unserialize($payment->getAdditionalData());
            if (isset($additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_TRANSACTION_ID])) {
                $pmt_id = $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_TRANSACTION_ID];
            } else {
                $pmt_id = Mage::helper('maksuturva')->generatePaymentId();
                $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_TRANSACTION_ID] = $pmt_id;
                $payment->setAdditionalData(serialize($additional_data));
                $payment->save();
            }

            $options["pmt_id"] = $pmt_id;

            $options["pmt_orderid"] = $order->getIncrementId();
            $options["pmt_reference"] = (string)($order->getIncrementId() + 100);
            $options["pmt_sellerid"] = $this->sellerId;
            $options["pmt_duedate"] = $dueDate;

            $options["pmt_okreturn"] = Mage::getUrl('maksuturva/index/success');
            $options["pmt_errorreturn"] = Mage::getUrl('maksuturva/index/error');
            $options["pmt_cancelreturn"] = Mage::getUrl('maksuturva/index/cancel');
            $options["pmt_delayedpayreturn"] = Mage::getUrl('maksuturva/index/delayed');

            $options["pmt_amount"] = str_replace('.', ',', sprintf("%.2f", $totalAmount));
            if ($this->getPreselectedMethod()) {
                $options["pmt_paymentmethod"] = $this->getPreselectedMethod();
            }

            // Customer Information
            $options["pmt_buyername"] = ($order->getBillingAddress() ? $order->getBillingAddress()->getName() : 'Empty field');
            $options["pmt_buyeraddress"] = ($order->getBillingAddress() ? implode(' ', $order->getBillingAddress()->getStreet()) : 'Empty field');
            $options["pmt_buyerpostalcode"] = ($order->getBillingAddress() && $order->getBillingAddress()->getPostcode() ? $order->getBillingAddress()->getPostcode() : '000');
            $options["pmt_buyercity"] = ($order->getBillingAddress() ? $order->getBillingAddress()->getCity() : 'Empty field');
            $options["pmt_buyercountry"] = ($order->getBillingAddress() ? $order->getBillingAddress()->getCountry() : 'fi');
            if ($order->getBillingAddress()->getTelephone()) {
                $options["pmt_buyerphone"] = preg_replace('/[^\+\d\s\-\(\)]/', '', $order->getBillingAddress()->getTelephone());
            }
            $options["pmt_buyeremail"] = ($order->getCustomerEmail() ? $order->getCustomerEmail() : 'empty@email.com');

            // emaksut, deprecated feature
            $options["pmt_escrow"] = "N";

            // Delivery information
            $options["pmt_deliveryname"] = ($order->getShippingAddress() ? $order->getShippingAddress()->getName() : '');
            $options["pmt_deliveryaddress"] = ($order->getShippingAddress() ? implode(' ', $order->getShippingAddress()->getStreet()) : '');
            $options["pmt_deliverypostalcode"] = ($order->getShippingAddress() ? $order->getShippingAddress()->getPostcode() : '');
            $options["pmt_deliverycity"] = ($order->getShippingAddress() ? $order->getShippingAddress()->getCity() : '');
            $options["pmt_deliverycountry"] = ($order->getShippingAddress() ? $order->getShippingAddress()->getCountry() : '');

            $options["pmt_sellercosts"] = str_replace('.', ',', sprintf("%.2f", $totalSellerCosts));

            $options["pmt_rows"] = count($products_rows);
            $options["pmt_rows_data"] = $products_rows;

            Mage::log(var_export($options, true), null, 'maksuturva.log', true);
            $this->form = Mage::getModel('maksuturva/form', array('secretkey' => $this->secretKey, 'options' => $options, 'encoding' => $this->commEncoding, 'url' => $this->commUrl));
        }

        return $this->form;
    }

    public function getFormFields()
    {
        return $this->getForm()->getFieldArray();
    }

    public function getHashAlgo()
    {
        return $this->_hashAlgoDefined;
    }

    /**
     * Perform a status query to maksuturva's server and gets allowed payment methods (Vaimo)
     *
     * Used with payment method preselection
     */
    public function getPaymentMethods($total)
    {
        $fields = array(
            "sellerid" => $this->sellerId,
            "request_locale" => "fi", // allowed values: fi, sv, en
            "totalamount" => number_format($total, 2, ",", ""),
        );

        try {
            $response = $this->getPostResponse($this->getPaymentMethodsUrl(), $fields, 5);
        } catch (MaksuturvaGatewayException $e) {
            return false;
        }

        $xml = simplexml_load_string($response);
        $obj = json_decode(json_encode($xml));

        if (isset($obj->paymentmethod)) {
            return $obj->paymentmethod;
        } else {
            return false;
        }
    }

    public function getPaymentMethodsUrl()
    {
        return $this->commUrl . 'GetPaymentMethods.pmt';
    }

    public function getPaymentRequestUrl()
    {
        return $this->commUrl . Vaimo_Maksuturva_Model_Gateway_Abstract::PAYMENT_SERVICE_URN;
    }

    /**
     * Calculate the status query url base on the admin module configuration
     * of the base url
     * @param string $baseUrl
     * @return string
     */
    public function getStatusQueryUrl()
    {
        return $this->commUrl . 'PaymentStatusQuery.pmt';
    }

    public function getAddDeliveryInfoUrl()
    {
        return $this->commUrl . 'addDeliveryInfo.pmt';
    }

    protected function getPreselectedMethod()
    {
        if ($this->preSelectPaymentMethod) {
            $payment = $this->getOrder()->getPayment();
            $additional_data = unserialize($payment->getAdditionalData());
            if (isset($additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD])) {
                return $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_PRESELECTED_PAYMENT_METHOD];
            } else {
                return "";
            }
        } else {
            return "";
        }
    }

    /**
     * Perform a status query to maksuturva's server using the current order
     * <code>
     * array(
     *        "pmtq_action",
     *        "pmtq_version",
     *        "pmtq_sellerid",
     *        "pmtq_id",
     *        "pmtq_resptype",
     *        "pmqt_return",
     *        "pmtq_hashversion",
     *        "pmtq_keygeneration"
     * );
     * </code>
     * The return data is an array if the order is successfully organized;
     * Otherwise, possible situations of errors:
     * 1) Exceptions in case of not having curl in PHP - exception
     * 2) Network problems (cannot connect, etc) - exception
     * 3) Invalid returned data (hash or consistency) - return false
     *
     * @param array $data Configuration values to be used
     * @return array|boolean
     */
    public function statusQuery($data = array())
    {
        $payment = $this->getOrder()->getPayment();
        $additional_data = unserialize($payment->getAdditionalData());
        $pmt_id = $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_TRANSACTION_ID];


        $defaultFields = array(
            "pmtq_action" => "PAYMENT_STATUS_QUERY",
            "pmtq_version" => "0005",
            "pmtq_sellerid" => $this->sellerId,
            "pmtq_id" => $pmt_id,
            "pmtq_resptype" => "XML",
            //"pmtq_return" => "",	// optional
            "pmtq_hashversion" => $this->_pmt_hashversion,
            "pmtq_keygeneration" => "001"
        );

        // overrides with user-defined fields
        $this->_statusQueryData = array_merge($defaultFields, $data);

        // hash calculation
        $hashFields = array(
            "pmtq_action",
            "pmtq_version",
            "pmtq_sellerid",
            "pmtq_id"
        );
        $hashString = '';
        foreach ($hashFields as $hashField) {
            $hashString .= $this->_statusQueryData[$hashField] . '&';
        }
        $hashString .= $this->secretKey . '&';
        // last step: the hash is placed correctly
        $this->_statusQueryData["pmtq_hash"] = strtoupper(hash($this->_hashAlgoDefined, $hashString));

        $res = $this->getPostResponse($this->getStatusQueryUrl(), $this->_statusQueryData);

        // we will not rely on xml parsing - instead, the fields are going to be collected by means of preg_match
        $parsedResponse = array();
        $responseFields = array(
            "pmtq_action", "pmtq_version", "pmtq_sellerid", "pmtq_id",
            "pmtq_amount", "pmtq_returncode", "pmtq_returntext", "pmtq_trackingcodes",
            "pmtq_sellercosts", "pmtq_paymentmethod", "pmtq_escrow", "pmtq_certification", "pmtq_paymentdate",
            "pmtq_buyername", "pmtq_buyeraddress1", "pmtq_buyeraddress2",
            "pmtq_buyerpostalcode", "pmtq_buyercity", "pmtq_hash"
        );
        foreach ($responseFields as $responseField) {
            preg_match("/<$responseField>(.*)?<\/$responseField>/i", $res, $match);
            if (count($match) == 2) {
                $parsedResponse[$responseField] = $match[1];
            }
        }

        // do not provide a response which is not valid
        if (!$this->_verifyStatusQueryResponse($parsedResponse)) {
            throw new MaksuturvaGatewayException(array("The authenticity of the answer could't be verified. Hashes didn't match."), self::EXCEPTION_CODE_HASHES_DONT_MATCH);
        }

        // return the response - verified
        return $parsedResponse;
    }

    public function processStatusQueryResult($response)
    {
        $order = $this->getOrder();
        $result = array('success' => 'error', 'message' => '');

        switch ($response["pmtq_returncode"]) {
            // set as paid if not already set
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_PAID:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_PAID_DELIVERY:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_COMPENSATED:

                $isDelayedCapture = Mage::getModel('maksuturva/maksuturva')->isDelayedCaptureCase($response['pmtq_paymentmethod']);
                if ($isDelayedCapture) {
                    if ($order->getState() != Mage_Sales_Model_Order::STATE_PROCESSING) {
                        Mage::dispatchEvent("ic_order_success", array("order" => $order));
                        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->helper->__('Authorization identified by Maksuturva'))->save();
                    }
                    $result['message'] = $this->helper->__('Authorization identified by Maksuturva.');
                    $result['success'] = 'success';
                } else {
                    if ($order->hasInvoices() == false) {
                        if ($order->canInvoice()) {

                            $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                            $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                            $invoice->register();
                            $transaction = Mage::getModel('core/resource_transaction')
                                ->addObject($invoice)
                                ->addObject($invoice->getOrder());
                            $transaction->save();
                            $result['message'] = $this->helper->__('Payment identified by Maksuturva. Invoice saved.');
                            $result['success'] = 'success';

                            Mage::dispatchEvent("ic_order_success", array("order" => $order));

                            $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->helper->__('Payment identified by Maksuturva'))->save();
                        }
                    } else {
                        $result['message'] = $this->helper->__('Payment identified by Maksuturva. invoices already exist');
                        $result['success'] = 'success';
                    }
                }

                break;

            // set payment cancellation with the notice
            // stored in response_text
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_PAYER_CANCELLED:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_PAYER_CANCELLED_PARTIAL:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_PAYER_CANCELLED_PARTIAL_RETURN:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_PAYER_RECLAMATION:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_CANCELLED:
                //Mark the order cancelled
                $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, $this->helper->__('Payment canceled by Maksuturva'))->save();

                $result['message'] = $this->helper->__('Payment canceled by Maksuturva');
                $result['success'] = "error";

                break;

            // no news for buyer and seller
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_NOT_FOUND:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_FAILED:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_WAITING:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_UNPAID:
            case Vaimo_Maksuturva_Model_Gateway_Implementation::STATUS_QUERY_UNPAID_DELIVERY:
            default:
                // no action here
                $result['message'] = $this->helper->__('No change, still awaiting payment');
                $result['success'] = "notice";
                break;
        }

        return $result;
    }

    public function addDeliveryInfo($payment)
    {
        $additional_data = unserialize($payment->getAdditionalData());
        $pkg_id = $additional_data[Vaimo_Maksuturva_Model_Maksuturva::MAKSUTURVA_TRANSACTION_ID];

        Mage::log("Adding delivery info for pkg_id {$pkg_id}", null, 'maksuturva.log', true);

        $deliveryData = array(
            "pkg_version" => "0002",
            "pkg_sellerid" => $this->sellerId,
            "pkg_id" => $pkg_id,
            "pkg_deliverymethodid" => "UNRDL",
            "pkg_adddeliveryinfo" => "Capture from Magento",
            "pkg_allsent" => "Y",
            "pkg_resptype" => "XML",
            "pkg_hashversion" => $this->_pmt_hashversion,
            "pkg_keygeneration" => "001"
        );

        // hash calculation
        $hashFields = array(
            "pkg_id",
            "pkg_deliverymethodid",
            "pkg_allsent",
        );
        $hashString = '';
        foreach ($hashFields as $hashField) {
            $hashString .= $deliveryData[$hashField] . '&';
        }
        $hashString .= $this->secretKey . '&';
        $deliveryData["pkg_hash"] = strtoupper(hash($this->_hashAlgoDefined, $hashString));

        $res = $this->getPostResponse($this->getAddDeliveryInfoUrl(), $deliveryData);

        $xml = new Varien_Simplexml_Element($res);
        $resultCode = (string)$xml->pkg_resultcode;

        switch ($resultCode) {
            case self::MAKSUTURVA_PKG_RESULT_SUCCESS:
                return array('pkg_resultcode' => $resultCode, 'pkg_id' => (string)$xml->pkg_id, 'pkg_resulttext' => (string)$xml->pkg_resulttext);
            default:
                Mage::throwException("Error on Maksuturva pkg creation: " . (string)$xml->pkg_resulttext);
        }
    }

    private function _getCustomerTaxClass()
    {
        $customerGroup = $this->getQuote()->getCustomerGroupId();
        if (!$customerGroup) {
            $customerGroup = Mage::getStoreConfig('customer/create_account/default_group', $this->getQuote()->getStoreId());
        }
        return Mage::getModel('customer/group')->load($customerGroup)->getTaxClassId();
    }

    public function getQuote()
    {
        return Mage::getSingleton('checkout/session')->getQuote();
    }
}
<?php
/**
 * Maksuturva Payment Module
 * Creation date: 05/01/2012
 */

require_once dirname(__FILE__) . '/MaksuturvaGatewayAbstract.php';

/**
 * Main class for gateway payments
 * @author Maksuturva
 */
class Maksuturva_Maksuturva_Model_MaksuturvaGatewayImplementation extends MaksuturvaGatewayAbstract
{
    private $secretKey = "";
    private $sellerId = "";
    private $order = null;

	function __construct($order, $config)
	{
	    $this->order = $order;
	    
	    if ($config['sandbox']) {
	        $this->secretKey = '11223344556677889900';
	        $this->sellerId = 'testikauppias';
	    } else {
	        $this->secretKey = $config['secretKey'];
	        $this->sellerId = $config['sellerId'];
	    }
		$dueDate = date("d.m.Y", strtotime("+" . $config['paymentdue'] . " day"));
		
	    $items = $order->getAllItems();
        $itemcount = count($items);
        $orderData = $order->getData();
        $totalAmount = 0;

		//Adding each product from order
		$taxInfo = $order->getFullTaxInfo();
		$products_rows = array();
		foreach ($items as $itemId => $item) {
			$itemData = $item->getData();

		    $productName = $item->getName();
		    
		    $productDescription = $item->getProduct()->getShortDescription() ? $item->getProduct()->getShortDescription() : "SKU: " . $item->getSku();
            
		    $row = array(
		        'pmt_row_name' => $productName,                                                        //alphanumeric        max lenght 40             -
            	'pmt_row_desc' => $productDescription,                                                       //alphanumeric        max lenght 1000      min lenght 1
            	'pmt_row_quantity' => $item->getQtyToInvoice(),                                                     //numeric             max lenght 8         min lenght 1
		    	'pmt_row_articlenr' => $item->getSku(),
		    	'pmt_row_deliverydate' => date("d.m.Y"),                                                   //alphanumeric        max lenght 10        min lenght 10        dd.MM.yyyy
            	'pmt_row_price_net' => str_replace('.', ',', sprintf("%.2f", $item->getPrice())),          //alphanumeric        max lenght 17        min lenght 4         n,nn
            	'pmt_row_vat' => str_replace('.', ',', sprintf("%.2f", $itemData["tax_percent"])),                  //alphanumeric        max lenght 5         min lenght 4         n,nn
            	'pmt_row_discountpercentage' => "0,00",                                                    //alphanumeric        max lenght 5         min lenght 4         n,nn
            	'pmt_row_type' => 1,
		    );
		    
		    //CONFIGURABLE PRODUCT - PARENT
		    //copies child's name, shortdescription and SKU as parent's
		    if($item->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE && $item->getChildrenItems() != null && sizeof($item->getChildrenItems()) > 0) {
		    	$children = $item->getChildrenItems();
		    	if(sizeof($children) != 1){
		    		error_log("Maksuturva module FAIL: more than one children for configurable product!");
		    		continue;
		    	}
		    	if(in_array($items[$itemId+1], $children) == false){
		    		error_log("Maksuturva module FAIL: No children in order!");
		    		continue;
		    	}
		    	$child = $children[0];
		    	$row['pmt_row_name'] = $child->getName();
		    	if(strlen($child->getSku()) > 0){
		    		$row['pmt_row_articlenr'] = $child->getSku();
		    	}
		    	if(strlen($child->getProduct()->getShortDescription()) > 0){
		    		$row['pmt_row_desc'] = $child->getProduct()->getShortDescription();
		    	}
		    	$totalAmount += $itemData["price_incl_tax"] * $item->getQtyToInvoice();
		    }
		    //CONFIGURABLE PRODUCT - CHILD
		    //as child's information already copied to parent's row, no child row is displayed
		    else if($item->getParentItem() != null && $item->getParentItem()->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE){
		    	continue;
		    }
		    //BUNDLED PRODUCT - PARENT
		    //bundled product parents won't be charged in invoice so unline other products, the quantity is fetched from qtyOrdered, 
		    //price will be nullified as the prices are available in children
		    else if($item->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE && $item->getChildrenItems() != null && sizeof($item->getChildrenItems()) > 0) {
		    	$row['pmt_row_quantity'] = str_replace('.', ',', sprintf("%.2f", $item->getQtyOrdered()));
		    	if($item->getProduct()->getPriceType() == 0){ //if price is fully dynamic
		    		$row['pmt_row_price_net'] = str_replace('.', ',', sprintf("%.2f", '0'));
		    	}
		    	else {
		    		$totalAmount += $itemData["price_incl_tax"] * $item->getQtyOrdered();
		    	}
		    	$row['pmt_row_type'] = 4; //mark product as tailored product 
		    }
		    //BUNDLED PRODUCT - CHILD
		    //the quantity information with parent's quantity is added to child's description
		    else if($item->getParentItem() != null && $item->getParentItem()->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE){
		    	$parentQty = $item->getParentItem()->getQtyOrdered();
		    	if(intval($parentQty, 10) == $parentQty){
		    		$parentQty = intval($parentQty, 10);
		    	}
		    	$unitQty = $item->getQtyOrdered() / $parentQty;
		    	
		    	if(intval($unitQty, 10) == $unitQty){
		    		$unitQty = intval($unitQty, 10);
		    	}
		    	$row['pmt_row_name'] = $unitQty." X ".$parentQty. " X ".$item->getName();
		    	$row['pmt_row_quantity'] = str_replace('.', ',', sprintf("%.2f", $item->getQtyOrdered()));
		    	$totalAmount += $itemData["price_incl_tax"] * $item->getQtyOrdered();
		    	$row['pmt_row_type'] = 4; //mark product as taloired product - by default not returnable
		    }
		    //SIMPLE OR GROUPED PRODUCT
		    else {
		    	$totalAmount += $itemData["price_incl_tax"] * $item->getQtyToInvoice();
		    }
		    array_push($products_rows, $row);
		}
		
		// row type 6
		$discount = 0;
		if ($orderData["discount_amount"] != 0) {
      		$discount = $orderData["discount_amount"];
			if ($discount > ($orderData["shipping_amount"] + $totalAmount)){
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
		
		$shippingTax = (($orderData["shipping_tax_amount"] > 0) ? (($orderData["shipping_tax_amount"] / $orderData["shipping_amount"]) * 100) : 0);
		$shippingCost = $orderData["shipping_amount"];
		$row = array(
		    'pmt_row_name' => 'Shipping',
        	'pmt_row_desc' => $shippingDescription,
        	'pmt_row_quantity' => 1,
        	'pmt_row_deliverydate' => date("d.m.Y"),
        	'pmt_row_price_net' => str_replace('.', ',', sprintf("%.2f", $shippingCost)),
        	'pmt_row_vat' => str_replace('.', ',', sprintf("%.2f", $shippingTax)),
        	'pmt_row_discountpercentage' => "0,00",
        	'pmt_row_type' => 2,
		);
		array_push($products_rows, $row);

		$customer_data = Mage::getModel('customer/customer')->load($order->getCustomerId());
		
		$options = array(
		    "pmt_keygeneration"	=> $config['keyversion'],
		
			"pmt_id" => $order->getIncrementId(),
			"pmt_orderid" => $order->getIncrementId(),
			"pmt_reference" => ($order->getIncrementId() + 100),
			"pmt_sellerid" 	=> $this->sellerId,
			"pmt_duedate" 	=> $dueDate,

			"pmt_okreturn"	=> Mage::getUrl('maksuturva/index/success'),
			"pmt_errorreturn"	=> Mage::getUrl('maksuturva/index/error'),
			"pmt_cancelreturn"	=> Mage::getUrl('maksuturva/index/cancel'),
			"pmt_delayedpayreturn"	=> Mage::getUrl('maksuturva/index/delayed'),
		
			"pmt_amount" 		=> str_replace('.', ',', sprintf("%.2f", $totalAmount)),

			// Customer Information
			"pmt_buyername" 	=> ($order->getBillingAddress() ? $order->getBillingAddress()->getName() : 'Empty field'),
		    "pmt_buyeraddress" => ($order->getBillingAddress() ? implode(' ', $order->getBillingAddress()->getStreet()) : 'Empty field'),
			"pmt_buyerpostalcode" => ($order->getBillingAddress() && $order->getBillingAddress()->getPostcode() ? $order->getBillingAddress()->getPostcode() : '000') ,
			"pmt_buyercity" => ($order->getBillingAddress() ? $order->getBillingAddress()->getCity() : 'Empty field'),
			"pmt_buyercountry" => ($order->getBillingAddress() ? $order->getBillingAddress()->getCountry() : 'fi'),
		    "pmt_buyeremail" => ($order->getCustomerEmail() ? $order->getCustomerEmail() : 'empty@email.com'),

			// emaksut
			"pmt_escrow" => ($config['emaksut'] ? "N" : "Y"),

		    // Delivery information
			"pmt_deliveryname" => ($order->getShippingAddress() ? $order->getShippingAddress()->getName() : ''),
			"pmt_deliveryaddress" => ($order->getShippingAddress() ? implode(' ', $order->getShippingAddress()->getStreet()) : ''),
			"pmt_deliverypostalcode" => ($order->getShippingAddress() ? $order->getShippingAddress()->getPostcode() : ''),
		    "pmt_deliverycity" => ($order->getShippingAddress() ? $order->getShippingAddress()->getCity() : ''),
			"pmt_deliverycountry" => ($order->getShippingAddress() ? $order->getShippingAddress()->getCountry() : ''),

			"pmt_sellercosts" => str_replace('.', ',', sprintf("%.2f", $shippingCost*(1+$shippingTax/100))),

		    "pmt_rows" => count($products_rows),
		    "pmt_rows_data" => $products_rows

		);
		//var_dump( $options ); exit; 
		parent::__construct($this->secretKey, $options, $config['commencoding'], $config['commurl']);
	}

    public function calcPmtReferenceCheckNumber()
    {
        return $this->getPmtReferenceNumber($this->_formData['pmt_reference']);
    }

    public function calcHash()
    {
        return $this->generateHash();
    }

    public function getHashAlgo()
    {
        return $this->_hashAlgoDefined;
    }
    
    public function getOrder()
    {
        return $this->order;
    }
    
    /**
     * Overrides the magic method to create all setters
     */
    public function __call($method, $args) 
    {   
        $matches = array();
        
        if (preg_match('/^set(\w+)$/', $method, $matches)) {
            $parameter = lcfirst($matches[1]);
            
            $this->{$parameter} = $args[0];
            
            return $this;
        }
        
        parent::__call($method,$args);
    }

}

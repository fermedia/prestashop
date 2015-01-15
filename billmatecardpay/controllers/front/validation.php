<?php

require_once BCARDPAY_BASE. '/Billmate.php';
require_once BCARDPAY_BASE .'/lib/billmateCart.php';
//error_reporting(E_ERROR);
class BillmateCardpayValidationModuleFrontController extends ModuleFrontController
{
	public $ssl = true;
	public $ajax = true;

	public function postProcess()
	{

		if (!$this->module->active)
			Tools::redirectLink(__PS_BASE_URI__.'order.php?step=1');

		// Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
		$authorized = false;
		foreach (Module::getPaymentModules() as $module)
			if ($module['name'] == 'billmatecardpay')
			{
				$authorized = true;
				break;
			}

		if (!$authorized)
			Tools::redirectLink(__PS_BASE_URI__.'order&step=3');

		$customer = new Customer($this->context->cart->id_customer);
		if (!Validate::isLoadedObject($customer))
			Tools::redirectLink(__PS_BASE_URI__.'order&step=1');


		$k = $this->getBillmate();
		$_DATA = $k->verify_hash($_REQUEST);
		if(isset($_DATA['code'])){
			$this->context->smarty->assign('error_message', $_DATA['message']) ;
		}
		if(isset($_DATA['status']) && !empty($_DATA['number']))
		{

			$invoiceid = $_DATA['number'];
			
			$this->context->cart->id = (int)Order::getCartByOrderId($_DATA['orderid']);
			
			//$eid = (int)Configuration::get('BCARDPAY_STORE_ID_SETTINGS');
			
		    if( $_DATA['status'] == 'Paid' ){
		        try{
					
					$data = $measurements = array();
					
					$order = new Order($_DATA['orderid']);
					$orderhistory = OrderHistory::getLastOrderState((int)$_DATA['orderid']);

					if( $orderhistory->id != Configuration::get('BCARDPAY_ORDER_STATUS_SETTINGS')){

						$t = new billmateCart();
						$t->id = $_DATA['orderid'];

						$timestart = microtime(true);
						$customer = new Customer((int)$this->context->cart->id_customer);
						$measurements['after_customer'] =  microtime(true) - $timestart;

						$timestart = microtime(true);
						$total = $this->context->cart->getOrderTotal(true, Cart::BOTH);
						$measurements['calculatetotal'] = microtime(true) - $timestart;
						
						$timestart = microtime(true);
						$extra = array('transaction_id'=>$invoiceid);
						$t->completeOrder($extra,$this->context->cart->id);
						//$this->module->validateOrder((int)$this->context->cart->id, Configuration::get('PS_OS_PREPARATION'), $total, $this->module->displayName, null, $extra, null, false, $customer->secure_key);
						$measurements['validateorder'] = microtime(true) - $timestart;
						
						$timestart = microtime(true);
						if( (Configuration::get('BCARDPAY_AUTHMOD') != 'sale') ) {
							//$k = $this->getBillmate();
							$k->UpdatePayment( array('PaymentData'=> array("number"=>(string)$invoiceid, "orderid"=>(string)$_DATA['orderid'], "currency" => "SEK", "language" => "sv", "country" => "se")) );
						}
						unset($_SESSION["uniqueId"]);
						$measurements['update_order_no'] = microtime(true) - $timestart;
						//$duration = ( microtime(true)-$timetotalstart ) * 1000;

						//$api->stat("client_card_order_measurements", json_encode(array('order_id'=>$this->module->currentOrder, 'measurements'=>$measurements)), '', $duration);
					} else {
						$customer = new Customer((int)$this->context->cart->id_customer);
					}					
					$this->module->currentOrder = $_DATA['orderid'];
					if( isset($_SESSION['billmate_order_id'])){
						unset($_SESSION['billmate_order_id']);
					}
					
					Tools::redirectLink(__PS_BASE_URI__.'order-confirmation.php?key='.$customer->secure_key.'&id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->module->id.'&id_order='.(int)$this->module->currentOrder);
					die;
		        }catch(Exception $ex){
    		       $this->context->smarty->assign('error_message', utf8_encode($ex->getMessage())) ;
		        }
		    } else {
		       $this->context->smarty->assign('error_message', $_DATA['status']) ;
		    }
		}
		$len = strlen( $_DATA['status']) > 0;
		$this->context->smarty->assign('posted', $len) ;
	}

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		$this->display_column_left = false;
		parent::initContent();
		if(version_compare(_PS_VERSION_,'1.5','<')){
			$this->context = Context::getContext();
		}
		$t = new billmateCart();
		$t->name="billmatecardpay";
		$extra = array('transaction_id'=>time());
		$customer = new Customer((int)$this->context->cart->id_customer);
		if(isset($_SESSION['INVOICE_CREATED_CARD']) ){
			$t->cancelOrder($_SESSION['billmate_order_id']);
			unset($_SESSION['INVOICE_CREATED_CARD']);
		}
		if( isset($_SESSION['billmate_order_id'])){
			if(!isset($_REQUEST['pay_method'])) $t->cancelOrder($_SESSION['billmate_order_id']);
			unset($_SESSION['billmate_order_id']);
		}

		$total =  $this->context->cart->getOrderTotal(true, Cart::BOTH);
		try{
			$t->validateOrder((int)$this->context->cart->id, Configuration::get('BILLMATE_PAYMENT_PENDING'), $total, $this->module->displayName, null, $extra, null, false, $customer->secure_key);
		}catch(Exception $ex ){
			echo $ex->getMessage();
		}
		
		$order_id = $_SESSION['billmate_order_id'] = $t->currentOrder;
		$orderid = $order_id;/*.'-'.$this->context->cart->id;*/

		$data_return = $this->processReserveInvoice( strtoupper($this->context->country->iso_code), $orderid);	
		$data['url'] = $data_return->url;
		$this->context->smarty->assign($data);
		$this->setTemplate('validation.tpl');
	}
	public function logData($merchant_id){

		if(isset( $_REQUEST['order_id'])) $order_id = $_REQUEST['order_id'];

		$timetotalstart = microtime(true);
		$timestart = microtime(true);

		$measurements = array();
		$measurements['add_order'] =  microtime(true) - $timestart;
		$duration = ( microtime(true)-$timetotalstart ) * 1000;
		//$k->stat("client_card_add_order_measurements",json_encode(array('order_id'=>$order_id, 'measurements'=>$measurements)), '', $duration);
	}

	function getBillmate(){

        $eid = (int)Configuration::get('BCARDPAY_STORE_ID_SETTINGS');
        $secret = Configuration::get('BCARDPAY_SECRET_SETTINGS');

		$ssl = true;
		$debug = false;
        
        return new BillMate($eid,$secret,$ssl,$debug,Configuration::get('BCARDPAY_MOD'));
	}
    public function processReserveInvoice( $isocode, $order_id = ''){
       	$order_id = $order_id == '' ? time(): $order_id;

        $adrsDelivery = new Address((int)$this->context->cart->id_address_delivery);
        $adrsBilling = new Address((int)$this->context->cart->id_address_invoice);
        $country = strtoupper($adrsDelivery->country);
        $countryObj = new Country(intval($adrsDelivery->id_country));
		
        $countryname = BillmateCountry::getContryByNumber( BillmateCountry::fromCode($countryObj->iso_code)  );
        $countryname = Tools::strtoupper($countryname);
        
		$k = $this->getBillmate();
	
        $personalnumber = '';
        $country_to_currency = array(
            'NOR' => 'NOK',
            'SWE' => 'SEK',
            'FIN' => 'EUR',
            'DNK' => 'DKK',
            'DEU' => 'EUR',
            'NLD' => 'EUR',
        );
		
		$encoding = 2;
		$country = $countryname == 'SWEDEN' ? '209' : $countryname;
        $ship_address = array(
			"firstname" 	=> $adrsDelivery->firstname,
			"lastname" 		=> $adrsDelivery->lastname,
			"company" 		=> $adrsDelivery->company,
			"street" 		=> $adrsDelivery->address1,
			"street2" 		=> "",
			"zip" 			=> $adrsDelivery->postcode,
			"city" 			=> $adrsDelivery->city,
			"country" 		=> (string)$countryname,
			"phone" 		=> $adrsDelivery->phone_mobile,
        );

        $country = new Country(intval($adrsBilling->id_country));
        $countryname = BillmateCountry::getContryByNumber( BillmateCountry::fromCode($countryObj->iso_code)  );
		$country = $countryname == 'SWEDEN' ? 209 : Tools::strtoupper($countryname);
        
        $bill_address = array(
			"firstname" 	=> $adrsBilling->firstname,
			"lastname" 		=> $adrsBilling->lastname,
			"company" 		=> $adrsBilling->company,
			"street" 		=> $adrsBilling->address1,
			"street2" 		=> "",
			"zip" 			=> $adrsBilling->postcode,
			"city" 			=> $adrsBilling->city,
			"country" 		=> (string)$countryname,
			"phone" 		=> $adrsBilling->phone_mobile,
			"email" 		=> $this->context->customer->email,
        );
        
        /*foreach( $ship_address as $key => $col ){
            if( !is_array( $col )) {
                $ship_address[$key] = utf8_decode( Encoding::fixUTF8($col));
            }
        }
        foreach( $bill_address as $key => $col ){
            if( !is_array( $col )) {
                $bill_address[$key] = utf8_decode( Encoding::fixUTF8($col));
            }
        }*/
		
        $products = $this->context->cart->getProducts();
    	$cart_details = $this->context->cart->getSummaryDetails(null, true);
    	
        $taxrate =  0;
		foreach ($products as $product) {
			if(!empty($product['price'])){
				$goods_taxrate = $product['rate'];
				$goods_withoutTax = $product['price'] * $product['cart_quantity'];
				$goods_tax = ($goods_withoutTax/100)*$goods_taxrate;
				$goods_list[] = array(
									"artnr" => $product['reference'],
									"title" => $product['name'],
									"quantity" => $product['cart_quantity'],
									"aprice" => round($product['price'] * 100, 0),
									"taxrate" => $goods_taxrate,
									"discount" => 0.0,
									"withouttax" => round($goods_withoutTax * 100, 0),
									"tax" => round($goods_tax * 100, 0),
								);				
			}
			$taxrate = $product['rate'];
		}

		$carrier = $cart_details['carrier'];
		if( !empty($cart_details['total_discounts'])){
			$discountamount = ($cart_details['total_discounts'] / (($taxrate+100)/100))*100;
			if( !empty( $discountamount )){
				$goods_list[] = array(
									"artnr" => '',
									"title" => $this->context->controller->module->l('Rabatt'),
									"quantity" => 1,
									"aprice" => 0 - abs(round($discountamount,0)),
									"taxrate" => $taxrate,
									"discount" => 0.0,
									"withouttax" => 0 - abs(round($discountamount,0)),
									"tax" => 0 - round((($discountamount/100)*$taxrate),0),
								);
			}
		}

		$totals = array('total_shipping','total_handling');
		$label =  array();
		$shippingPrice = $shippingTax = $shippingTaxRate = $handlingPrice = $handlingTax = $handlingTaxRate = 0;
		foreach ($totals as $total) {
		    $flag = $total == 'total_handling' ? 16 : ( $total == 'total_shipping' ? 8 : 0);
		    if(empty($cart_details[$total]) || $cart_details[$total]<=0 ) continue;
			if( $total == 'total_shipping' && $cart_details['free_ship'] == 1 ) continue;
			if( empty($cart_details[$total]) ) {continue;}

			if($total == 'total_shipping'){
				$carrier = new Carrier($this->context->cart->id_carrier, $this->context->cart->id_lang);
				$taxrate = $carrier->getTaxesRate(new Address($this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));

				$shippingPrice = $cart_details[$total] / (1+$taxrate/100);
				$shippingTax = (($shippingPrice/100)*$taxrate);
				$shippingTaxRate = $taxrate;
			}

			if($total == 'total_handling'){
				$handlingPrice = $cart_details[$total] / (1+$taxrate/100);
				$handlingTax = (($handlingPrice/100)*$taxrate);
				$handlingTaxRate = $taxrate;
			}
		}
		
		$pclass = -1;
		$cutomerId = (int)$this->context->cart->id_customer;
		$cutomerId = $cutomerId >0 ? $cutomerId: time();

		if( empty($bill_address) || empty($ship_address) || empty($goods_list)) return false;

		if( isset($_SESSION['INVOICE_CREATED_CARD']) ){
			$result1 = array($_SESSION['INVOICE_CREATED_CARD']);
		} else {
			$invoiceValues = array();
			$invoiceValues['PaymentData'] = array(	"method" => "8",		//1=Factoring, 2=Service, 4=PartPayment, 8=Card, 16=Bank, 24=Card/bank and 32=Cash.
													"paymentplanid" => $pclass,
													"currency" => $this->context->currency->iso_code, //"SEK",
													"language" => "sv",
													"country" => "SE",
													"autoactivate" => (Configuration::get('BCARDPAY_AUTHMOD') == 'sale')?"1":"0",
													"orderid" => (string)$order_id,
												);
			$invoiceValues['PaymentInfo'] = array( 	"paymentdate" => date('Y-m-d'),
													"paymentterms" => "14",
													"yourreference" => "",
													"ourreference" => "",
													"projectname" => "",
													"delivery" => "Post",
													"deliveryterms" => "FOB",
											);
			$callback = $accept = $cancel = '';
			if(version_compare(_PS_VERSION_,'1.5','<')){
				$accept =  _PS_BASE_URL_.__PS_BASE_URI__.'modules/billmatebank/controllers/front/validation.php';
				$cancel =  _PS_BASE_URL_.__PS_BASE_URI__.'modules/billmatebank/controllers/front/cancelorder.php';
				$callback = _PS_BASE_URL_.__PS_BASE_URI__.'modules/billmatebank/controllers/front/callback.php';
			} else {
				$accept = $this->context->link->getModuleLink('billmatebank', 'validation', array(), true);
				$cancel = $this->context->link->getModuleLink('billmatebank', 'cancelorder', array(), true);
				$callback = $this->context->link->getModuleLink('billmatebank', 'callback', array(), true);
			}
			$invoiceValues['Card'] = array(	"promptname" => (Configuration::get('BILL_PRNAME') == 'YES')?1:0,
											"3dsecure" => (Configuration::get('BILL_3DSECURE')=='YES'?1:0),
											"recurring" => "",
											"recurringnr" => "",
											"accepturl" => $accept,
											"cancelurl" => $cancel,
											"callbackurl" => $callback,
									);
			$invoiceValues['Customer'] = array(	'customernr'=> $cutomerId, 
												'pno'=>$personalnumber, 
												'Billing'=> $bill_address, 
												'Shipping'=> $ship_address
											);
			$invoiceValues['Articles'] = $goods_list;
			
			$totalwithouttax = round($this->context->cart->getOrderTotal(false, Cart::BOTH)*100,0);
			$totalwithtax = round($this->context->cart->getOrderTotal(true, Cart::BOTH)*100,0);
			$totaltax = round(($totalwithtax - $totalwithouttax),0);
			$rounding = $totalwithtax - ($totalwithouttax+$totaltax);
			
			$invoiceValues['Cart'] = array(
										"Handling" => array(
											"withouttax" => ($handlingPrice)?round($handlingPrice*100,0):0,
											"taxrate" => ($handlingTaxRate)?$handlingTaxRate:0
										),
										"Shipping" => array(
											"withouttax" => ($shippingPrice)?round($shippingPrice*100,0):0,
											"taxrate" => ($shippingTaxRate)?$shippingTaxRate:0
										),
										"Total" => array(
											"withouttax" => $totalwithouttax,
											"tax" => $totaltax,
											"rounding" => $rounding,
											"withtax" => $totalwithtax,
										)
									);
			$result1 = (object)$k->AddPayment($invoiceValues);
		}

		if(is_string($result1) || (isset($result1->message) && is_object($result1)) || is_array($result1) )
		{
			echo $result1->message. ' <a href="'.$this->context->link->getPageLink('order.php', true).'">click here</a>';
			throw new Exception($result1);
		}else{
			$_SESSION['INVOICE_CREATED_CARD'] = $result1->number;
		}
		return $result1;
    }
}

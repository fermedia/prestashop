<?php
@session_start();
if(!function_exists('my_dump')){
    function my_dump($data, $die = false){
        if($_SERVER['REMOTE_ADDR'] == '122.173.165.160' ){
            echo '<pre>';
            var_dump($data);
            echo '</pre>';
            if( $die ) die("die");
        }
    }
}

/*
* 2007-2013 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2013 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

/**
 * @since 1.5.0
 */
 if (version_compare(_PS_VERSION_,'1.5','<')){
	if( !class_exists('ModuleFrontController')){
		class ModuleFrontController extends FrontController{
		}
	}
}

include_once(_PS_MODULE_DIR_.'/billmateinvoice/commonfunctions.php');
require_once BILLMATE_BASE. '/Billmate.php';
require_once(_PS_MODULE_DIR_.'billmatepartpayment/backward_compatibility/backward.php');

class BillmateInvoiceGetaddressModuleFrontController extends ModuleFrontController
{
	public $ssl = true;
	public $ajax = true;
    public function init(){
	
		global $link;
        parent::init();
        if( !empty($_GET['clearFee'] )){
            $cart = $this->context->cart;
            $address = new Address(intval($cart->id_address_delivery));
            $country = new Country(intval($address->id_country));
            
            $countryname = BillmateCountry::getContryByNumber( BillmateCountry::fromCode($country->iso_code)  );
            $countryname = Tools::strtoupper($countryname);

            $this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
            header('location:'.__PS_BASE_URI__.'index.php?controller=order&step=1&multi-shipping=0');
            die;
        }

		
        $adrsDelivery = new Address((int)$this->context->cart->id_address_delivery);
        $adrsBilling = new Address((int)$this->context->cart->id_address_invoice);
	    $customer = new Customer((int)$this->context->cart->id_customer);
        
        $country = strtoupper($adrsDelivery->country);
		$country = new Country((int)$adrsBilling->id_country);
        $countryname = BillmateCountry::getContryByNumber( BillmateCountry::fromCode($country->iso_code)  );
        $countryname = Tools::strtoupper($countryname);
		
		$id_product = Configuration::get('BM_INV_FEE_ID_'.$countryname);
        $eid = (int)Configuration::get('BM_INV_STORE_ID_'.$countryname);
        $secret = Configuration::get('BM_INV_SECRET_'.$countryname);
		define('BILLMATE_INVOICE_EID', $eid);

        $ssl = true;
        $debug = false;
        
		try{
			$k = new BillMate($eid,$secret,$ssl,$debug, Configuration::get('BILLMATEINV_MOD'));
					
			$person = trim(Tools::getValue('pno'));
			$md5 = md5('invoice_'.$eid.$secret.$person);

			if(!isset($_SESSION['billmate'][$md5]) || $person != $_SESSION['billmate'][$md5]){
				$addr = $cache_addr = (object)$k->GetAddress(array('pno'=>$person));
			}else{
				$addr = $cache_addr = $_SESSION['billmate']['invoice_person_nummber_data'];
			}
			if(isset($addr->message) || empty($addr) || !is_object($addr)){
				$this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));

				$error = isset($addr->message) && is_object($addr) ? $addr->message : $addr;
				$message = '{success:false, content: "'.($error).'"}';
				
//				$k->stat('client_address_error', $message, $eid);
				die($message);
			}
			$addr->country = 209;
        }catch(Exception $ex ){
            $this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));        
        	$return = array('success' => false, 'content' =>( $ex->getMessage() ));
			
			$message = '{success:false, content: "'.($ex->getMessage()).'"}';
			
//			$k->stat('client_address_error', $message );
			die($message);
        }
		
       // echo BillmateCountry::getContryByNumber($addr->country);
        $fullname = $adrsDelivery->firstname.' '.$adrsDelivery->lastname.' '.$adrsDelivery->company;

		$_SESSION['billmate'][$md5] = $person;
		$_SESSION['billmate']['invoice_person_nummber_data'] = $cache_addr;

        if(strlen($addr->firstname) <= 0 ){
            $apiName = $adrsDelivery->firstname.' '.$adrsDelivery->lastname.' '.$adrsDelivery->company;
        } else {
            $apiName  = $addr->firstname.' '.$addr->lastname;
        }

        
        $usership = $adrsDelivery->firstname.' '.$adrsDelivery->lastname;
        $userbill = $adrsBilling->firstname.' '.$adrsBilling->lastname;

        $firstArr = explode(' ', $adrsDelivery->firstname);
        $lastArr  = explode(' ', $adrsDelivery->lastname);
        
        if( empty( $addr->firstname ) ){
            $apifirst = $firstArr;
            $apilast  = $lastArr ;
        }else {
            $apifirst = explode(' ', $addr->firstname );
            $apilast  = explode(' ', $addr->lastname );
        }
        $matchedFirst = array_intersect($apifirst, $firstArr );
        $matchedLast  = array_intersect($apilast, $lastArr );
        $apiMatchedName   = !empty($matchedFirst) && !empty($matchedLast);

        $address_same = matchstr( $usership, $userbill ) && 
            matchstr( $adrsBilling->city, $adrsDelivery->city ) && 
            matchstr( $adrsBilling->postcode, $adrsDelivery->postcode ) &&
            matchstr( $adrsBilling->address1, $adrsDelivery->address1 ) ;


        if( 
            !(
               $apiMatchedName  
               && matchstr( $adrsDelivery->address1, $addr->street ) 
               && matchstr( $adrsDelivery->postcode ,$addr->zip ) 
               && matchstr( $adrsDelivery->city ,$addr->city) 
               && matchstr( BillmateCountry::getContryByNumber($addr->country), $countryname )
			   && $address_same
           )  )
        { 
            if( Tools::getValue('geturl') == 'yes'){
                //$this->logData( $k ,'Customer clicked confirm addresschange, now completing purchase' );
		    	$cart_details = $this->context->cart->getSummaryDetails(null, true);
				$carrier_id = $cart_details['carrier']->id;
				if(version_compare(_PS_VERSION_,'1.5','>=')){
					$shippingPrice = $this->context->cart->getTotalShippingCost();
					$carrier = Carrier::getCarrierByReference( $cart_details['carrier']->id_reference);
			    }
                $addressnew = new Address();
        		$addressnew->id_customer = (int)$this->context->customer->id;

				if( empty( $addr->firstname ) ){
					$addressnew->firstname = $adrsDelivery->firstname;
					$addressnew->lastname = $adrsDelivery->lastname;
					$addressnew->company = $addr->lastname;
				} else {
					$addressnew->firstname = $addr->firstname;
					$addressnew->lastname = $addr->lastname;
					$addressnew->company = '';
				}
				$addressnew->phone = $adrsBilling->phone;
				$addressnew->phone_mobile = $adrsBilling->phone_mobile;

			    $addressnew->address1 = $addr->street;
                $addressnew->postcode = $addr->zip;
                $addressnew->city = $addr->city;
                $addressnew->country = BillmateCountry::getContryByNumber($addr->country);
                $addressnew->alias   = 'Bimport-'.time().ip2long($_SERVER['REMOTE_ADDR']);
                
                $addressnew->id_country = Country::getByIso(BillmateCountry::getCode($addr->country));
                $addressnew->save();


				$sql = 'UPDATE `'._DB_PREFIX_.'cart_product`
				SET `id_address_delivery` = '.(int)$addressnew->id.'
				WHERE  `id_cart` = '.(int)$this->context->cart->id.'
					AND `id_address_delivery` = '.(int)$this->context->cart->id_address_delivery;
				Db::getInstance()->execute($sql);
		
				$sql = 'UPDATE `'._DB_PREFIX_.'customization`
					SET `id_address_delivery` = '.(int)$addressnew->id.'
					WHERE  `id_cart` = '.(int)$this->context->cart->id.'
						AND `id_address_delivery` = '.(int)$this->context->cart->id_address_delivery;
				Db::getInstance()->execute($sql);

				
				$this->context->cart->id_address_invoice = (int)$addressnew->id;
				$this->context->cart->id_address_delivery = (int)$addressnew->id;
				$this->context->cart->update();                             
				
				if(version_compare(_PS_VERSION_,'1.5','>=')){
					if( Configuration::get('PS_ORDER_PROCESS_TYPE') == 1) {
						if(version_compare(_PS_VERSION_,'1.5','>=')){
							$carrierurl = $link->getPageLink("order-opc", true);
						} else {
							$carrierurl = $link->getPageLink("order-opc.php", true);
						}
						$return = array(
							'success' => true,
							'action'  => array(
								'method'=> 'updateCarrierAndGetPayments' , //updateExtraCarrier
								'gift' => 0,
								'gift_message'=> '',
								'recyclable'=>0,
								'delivery_option['.$addressnew->id.']' => $carrier->id.',',
								'ajax' => true,
								'token'=> Tools::getToken(false),
							)
						);
					} else {
						if(version_compare(_PS_VERSION_,'1.5','>=')){
							$carrierurl = $link->getPageLink("order", true);
						} else {
							$carrierurl = $link->getPageLink("order.php", true);
						}
						$return = array(
							'success' => true,
							'action'  => array(
								'method'=> 'updateExtraCarrier',
								'id_delivery_option'=> $carrier_id.',', 
								'id_address' => $addressnew->id,
								'allow_refresh'=> 1, 
								'ajax' => true,
								'token'=> Tools::getToken(false),
							)
						);
				   }
			   }else{
					$return = array( 'success' => true );
			   }
            } else {

				$countryname = '';
				if( BillmateCountry::getCode($addr->country) != 'se' ){
					$countryname = billmate_translate_country(BillmateCountry::getCode($addr->country));
				}
				//$previouslink = 
                $this->context->smarty->assign('firstname', $addr->firstname);
                $this->context->smarty->assign('lastname', $addr->lastname);
                $this->context->smarty->assign('address', $addr->street);
                $this->context->smarty->assign('zipcode', $addr->zip);
                $this->context->smarty->assign('city', $addr->city);
                $this->context->smarty->assign('country',$countryname );
				if(version_compare(_PS_VERSION_,'1.5','>=')){
					$previouslink = $link->getModuleLink('billmateinvoice', 'getaddress', array('ajax'=> 0,'clearFee' => true), true);
				} else {
					$previouslink = $link->getPageLink("order.php", true).'?step=3';
				}
				$this->context->smarty->assign('previouslink', $previouslink);
				$extra = '.tpl';

                //$this->logData( $k ,'Customer clicked confirm trying to open addresschange popup' );
				if((version_compare(_PS_VERSION_,'1.5','<'))){
					$this->context = Context::getContext();
				}
				if( $this->context->getMobileDevice() ) $extra = '-mobile.tpl';
                $html = $this->context->smarty->fetch(BILLMATE_BASE.'/views/templates/front/wrongaddress.tpl');
                $return  = array( 'success'=> false, 'content'=> $html , 'popup' => true );
           }
        } else {


            $return = array('success' => true );
        }
        if( $return['success'] && !isset($return['action'])){
        	try{
				$data = $measurements = array();
				$api = null;

				$timetotalstart = $timestart = microtime(true);
				$timestart = microtime(true);
                $this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
				$measurements['deleteproduct'] = microtime(true) - $timestart;
				
				$timestart = microtime(true);
				StockAvailable::setQuantity((int)Configuration::get('BM_INV_FEE_ID_'.$countryname), '', 1, (int)Configuration::get('PS_SHOP_DEFAULT'));
			    $this->context->cart->updateQty(1, (int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
				$measurements['updateqty'] = microtime(true) - $timestart;

				$timestart = microtime(true);
            	$invoiceid = $this->processReserveInvoice( strtoupper(BillmateCountry::getCode($addr->country)));
				$measurements['add_invoice'] = microtime(true) - $timestart;
				
				$timestart = microtime(true);
			    $customer = new Customer((int)$this->context->cart->id_customer);
				$measurements['customer'] = microtime(true) - $timestart;
				
				$timestart = microtime(true);
			    $total = $this->context->cart->getOrderTotal();
				
				$measurements['calculatetotal'] = microtime(true) - $timestart;
				
				$timestart = microtime(true);
				$extra = array('transaction_id'=>$invoiceid);
			    $this->module->validateOrder((int)$this->context->cart->id, Configuration::get('PS_OS_PREPARATION'), $total, $this->module->displayName, null, $extra, null, false, $customer->secure_key);
			    $order_id = $this->module->currentOrder;
				$measurements['validateorder'] = microtime(true) - $timestart;
				
				//billmate_log_data(array(array('order_id'=>$order_id,'measurements'=>$measurements)), $eid );
				$timestart = microtime(true);
				$k->UpdatePayment( array('PaymentData'=> array("number"=>$invoiceid, "orderid"=>(string)$order_id, "currency" => "SEK", "language" => "sv", "country" => "se")) ); 
				unset($_SESSION["uniqueId"]);
				$measurements['update_order_no'] = microtime(true) - $timestart;

				$duration = ( microtime(true)-$timetotalstart ) * 1000;
				$k->stat("client_order_measurements",json_encode(array('order_id'=>$order_id, 'measurements'=>$measurements)), '', $duration);
				
				//set order status
				$order = new Order($order_id);
				$new_history = new OrderHistory();
				$new_history->id_order = (int)$order_id;
				//$new_history->changeIdOrderState((int)Configuration::get('BILLMATE_PAYMENT_ACCEPTED'), $order, true);
				$new_history->changeIdOrderState((int)Configuration::get('BM_INV_ORDER_STATUS_SWEDEN'), $order, true);
				$new_history->addWithemail(true);

				$url = 'order-confirmation&id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->module->id.'&id_order='.(int)$order_id.'&key='.$customer->secure_key;
				$return['redirect'] = Context::getContext()->link->getPageLink($url);
           
        	}catch(Exception $ex ){
                $this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
        		$return['success'] = false;
        		unset($return['redirect']);
				
//				$k->stat('client_error' ,array($ex->getMessage()), $eid );
        		$return['content'] = Tools::safeOutput(utf8_encode($ex->getMessage()));
        	}
        }
		//$k->stat('client_$return, $eid);
        die(Tools::jsonEncode($return));
  }
    public function processReserveInvoice( $isocode ){
		$cart = $this->context->cart;
		$order_id2 = Order::getOrderByCartId((int)$cart->id);
       	$order_id = $order_id2 == '' ? time(): $order_id2;
        
        $adrsDelivery = new Address((int)$this->context->cart->id_address_delivery);
        $adrsBilling = new Address((int)$this->context->cart->id_address_invoice);
        $country = strtoupper($adrsDelivery->country);

        $countryname = BillmateCountry::getContryByNumber( BillmateCountry::fromCode($isocode)  );
        $countryname = Tools::strtoupper($countryname);

        $eid = (int)Configuration::get('BM_INV_STORE_ID_'.$countryname);
        $secret = Configuration::get('BM_INV_SECRET_'.$countryname);

		$ssl = true;
		$debug = false;
        
        $k = new BillMate($eid,$secret,$ssl,$debug,Configuration::get('BILLMATEINV_MOD'));

        $personalnumber = trim(Tools::getValue('pno'));
        $country_to_currency = array(
            'NOR' => 'NOK',
            'SWE' => 'SEK',
            'FIN' => 'EUR',
            'DNK' => 'DKK',
            'DEU' => 'EUR',
            'NLD' => 'EUR',
        );
                
        $ship_address = array(
			"firstname" => $adrsDelivery->firstname,
			"lastname" => $adrsDelivery->lastname,
			"company" => $adrsDelivery->company,
			"street" => $adrsDelivery->address1,
			"street2" => "",
			"zip" => $adrsDelivery->postcode,
			"city" => $adrsDelivery->city,
			"country" => $adrsDelivery->country,
			"phone" => $adrsDelivery->phone_mobile,
        );
        
        $bill_address = array(
			"firstname" => $adrsBilling->firstname,
			"lastname" => $adrsBilling->lastname,
			"company" => $adrsBilling->company,
			"street" => $adrsBilling->address1,
			"street2" => "",
			"zip" => $adrsBilling->postcode,
			"city" => $adrsBilling->city,
			"country" => $adrsBilling->country,
			"phone" => $adrsBilling->phone_mobile,
			"email" => $this->context->customer->email,
        );
        
         foreach( $ship_address as $key => $col ){
            if( !is_array( $col )) {
                $ship_address[$key] = utf8_decode( Encoding::fixUTF8($col));
            }
        }
        foreach( $bill_address as $key => $col ){
            if( !is_array( $col )) {
                $bill_address[$key] = utf8_decode( Encoding::fixUTF8($col));
            }
        }
    	$cart_details = $this->context->cart->getSummaryDetails(null, true);
		$this->context->cart->update();
		
        $products = $this->context->cart->getProducts();
		$taxrate = 0;
		$handlingPrice = $handlingTaxRate = 0;
		foreach ($products as $product) {
			if(!empty($product['price'])){
				$goods_taxrate = $taxrate = $product['rate'];
				$goods_withoutTax = $product['price'] * $product['cart_quantity'];
				$goods_tax = ($goods_withoutTax/100)*$goods_taxrate;
				if( $product['id_product'] == Configuration::get('BM_INV_FEE_ID_'.$countryname)){
					$handlingPrice = $goods_withoutTax;
					$handlingTaxRate = $goods_taxrate;
				} else {				
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
			}
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
		$notfree = !( isset($cart_details['free_ship']) && $cart_details['free_ship'] == 1 );
		
		if ($carrier->active && $notfree)
		{
		
			if(version_compare(_PS_VERSION_,'1.5','<'))
				$shippingPrice = $cart->getOrderShippingCost();
			else
				$shippingPrice = $cart->getTotalShippingCost();
		
			$carrier = new Carrier($cart->id_carrier, $this->context->cart->id_lang);
			$taxrate = $carrier->getTaxesRate(new Address($this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));

			if( !empty( $shippingPrice ) ){
				$shippingPrice = $shippingPrice / (1+$taxrate/100);
				$shippingTax = (($shippingPrice/100)*$taxrate);
				$shippingTaxRate = $taxrate;
				/*$goods_list[] = array(
									"artnr" => (string)$carrier->name.$cart->id_carrier,
									"title" => $carrier->name,
									"quantity" => 1,
									"aprice" => round($shippingPrice*100, 0),
									"taxrate" => $taxrate,
									"discount" => 0.0,
									"withouttax" => round($shippingPrice*100, 0),
									"tax" => round($shippingTax*100, 0),
								);*/
			}
		}

		$label =  array();

		$pclass = -1;

		if(empty($personalnumber) || empty($bill_address) || empty($ship_address) || empty($goods_list)) return false;
		$md5 = md5('invoice_'.$eid.$secret.$personalnumber);
		
		$invoiceValues = array();
		$invoiceValues['PaymentData'] = array(	"method" => "1",		//1=Factoring, 2=Service, 4=PartPayment, 8=Card, 16=Bank, 24=Card/bank and 32=Cash.
												"paymentplanid" => $pclass,
												"currency" => "SEK",
												"language" => "sv",
												"country" => "SE",
												"autoactivate" => "0",
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
		$invoiceValues['Card'] = array();
		$invoiceValues['Customer'] = array(	'customernr'=> (int)$this->context->cart->id_customer, 
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
		if(is_string($result1) || (isset($result1->message) && is_object($result1)) || is_array($result1))
		{
			throw new Exception($result1->message.$personalnumber);
		}
		$_SESSION['billmate'] = array();
		unset( $_SESSION['billmate']);
		return $result1->number;
    }
	public function postProcess()
	{
		die(__METHOD__);
	}

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		die(__METHOD__);
	}
}

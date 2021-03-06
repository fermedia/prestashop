<?php
@session_start();
if (!function_exists('my_dump'))
{
	function my_dump($data, $die = false)
	{
		if ($_SERVER['REMOTE_ADDR'] == '122.173.165.160' )
		{
			echo '<pre>';
			var_dump($data);
			echo '</pre>';
			if ($die) die('die');
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

 if (version_compare(_PS_VERSION_, '1.5', '<')){
	if(!class_exists('ModuleFrontController'))
	{
		class ModuleFrontController extends FrontController{
		}
	}
}

include_once(_PS_MODULE_DIR_.'/billmateinvoice/commonfunctions.php');
require_once BILLMATE_BASE.'/Billmate.php';
require_once(_PS_MODULE_DIR_.'billmatepartpayment/backward_compatibility/backward.php');

class BillmatePartpaymentGetaddressModuleFrontController extends ModuleFrontController
{
	public $ssl = true;
	public $ajax = true;
	public function init()
	{
		global $link;
		parent::init();

		if ((version_compare(_PS_VERSION_, '1.5', '<')))
			$this->context = Context::getContext();

		if (Tools::getValue('clearFee'))
		{
			$cart = $this->context->cart;
			$address = new Address((int)$cart->id_address_delivery);
			$country = new Country((int)$address->id_country);

			$countryname = BillmateCountry::getContryByNumber(BillmateCountry::fromCode($country->iso_code));
			$countryname = Tools::strtoupper($countryname);

			$this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
			Tools::redirect(__PS_BASE_URI__.'index.php?controller=order&step=1&multi-shipping=0');
			die;
		}

		
		$address_delivery = new Address((int)$this->context->cart->id_address_delivery);
		$adrsBilling = new Address((int)$this->context->cart->id_address_invoice);
		$customer = new Customer((int)$this->context->cart->id_customer);

		$country = Tools::strtoupper($address_delivery->country);
		$country = new Country((int)$adrsBilling->id_country);
		$countryname = BillmateCountry::getContryByNumber(BillmateCountry::fromCode($country->iso_code));
		$countryname = Tools::strtoupper($countryname);
		
		$id_product = Configuration::get('BM_INV_FEE_ID_'.$countryname);
		$eid = (int)Configuration::get('BILLMATE_STORE_ID_'.$countryname);
		$secret = Configuration::get('BILLMATE_SECRET_'.$countryname);
		define('BILLMATE_INVOICE_EID', $eid);
		
		$ssl = true;
		$debug = false;

		try{
			$k = new BillMate($eid, $secret, $ssl, $debug, Configuration::get('BILLMATE_MOD'));
			
			$person = trim(Tools::getValue('billmate_pno'));
			$md5 = md5('partpayment_'.$eid.$secret.$person);
			
			if (!isset($_SESSION['billmate'][$md5]) || $person != $_SESSION['billmate'][$md5])
				$addr = $cache_addr = $k->GetAddress($person);
			else
				$addr = $cache_addr = $_SESSION['billmate']['partpayment_person_nummber_data'];


			if (isset($addr['error']) || empty($addr) || !is_array($addr))
			{
				$error = isset($addr['error']) && is_array($addr) ? $addr['error'] : $addr;
				$this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
				$message = '{success:false, content: "'.utf8_encode($error).'"}';
				
//				$k->stat('client_address_error', $message, $eid);
				die($message);
			}
			foreach ($addr[0] as $key => $adr)
			{
				$addr[0][$key] = utf8_encode($adr);
			}
		}catch(Exception $ex ){
			$this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
			$return = array('success' => false, 'content' =>utf8_encode( $ex->getMessage() ));
			
			$message = '{success:false, content: "'.utf8_encode($ex->getMessage()).'"}';
			
			//$k->stat('client_address_error', $message );
			die($message);
		}
		
	   // echo BillmateCountry::getContryByNumber($addr[0][5]);
		$fullname = $address_delivery->firstname.' '.$address_delivery->lastname.' '.$address_delivery->company;

		$_SESSION['billmate'][$md5] = $person;
		$_SESSION['billmate']['partpayment_person_nummber_data'] = $cache_addr;

		if (Tools::strlen($addr[0][0]) <= 0)
			$api_name = $address_delivery->firstname.' '.$address_delivery->lastname.' '.$address_delivery->company;
		else
			$api_name  = $addr[0][0].' '.$addr[0][1];

		$usership = $address_delivery->firstname.' '.$address_delivery->lastname;
		$userbill = $adrsBilling->firstname.' '.$adrsBilling->lastname;

		$firstArr = explode(' ', $address_delivery->firstname);
		$lastArr  = explode(' ', $address_delivery->lastname);

		if (empty($addr[0][0]))
		{
			$apifirst = $firstArr;
			$apilast  = $lastArr ;
		}
		else
		{
			$apifirst = explode(' ', $addr[0][0]);
			$apilast  = explode(' ', $addr[0][1]);
		}
		$matchedFirst = array_intersect($apifirst, $firstArr);
		$matchedLast  = array_intersect($apilast, $lastArr);
		$apiMatchedName   = !empty($matchedFirst) && !empty($matchedLast);

		$address_same = matchstr($usership, $userbill) &&
			matchstr($adrsBilling->city, $address_delivery->city) &&
			matchstr($adrsBilling->postcode, $address_delivery->postcode) &&
			matchstr($adrsBilling->address1, $address_delivery->address1);


		if (
			!(
			   $apiMatchedName
			   && matchstr($address_delivery->address1, $addr[0][2])
			   && matchstr($address_delivery->postcode , $addr[0][3])
			   && matchstr($address_delivery->city, $addr[0][4])
			   && matchstr(BillmateCountry::getContryByNumber($addr[0][5]), $countryname)
			   && $address_same
		   )  )
		{
			if (Tools::getValue('geturl') == 'yes')
			{

				$cart_details = $this->context->cart->getSummaryDetails(null, true);
				$carrier_id = $cart_details['carrier']->id;

				if (version_compare(_PS_VERSION_, '1.5', '>='))
				{
					$shippingPrice = $this->context->cart->getTotalShippingCost();
					$carrier = Carrier::getCarrierByReference( $cart_details['carrier']->id_reference);
				}
				$addressnew = new Address();
				$addressnew->id_customer = (int)$this->context->customer->id;

				if (empty($addr[0][0]))
				{
					$addressnew->firstname = $address_delivery->firstname;
					$addressnew->lastname = $address_delivery->lastname;
					$addressnew->company = $addr[0][1];
				}
				else
				{
					$addressnew->firstname = $addr[0][0];
					$addressnew->lastname = $addr[0][1];
					$addressnew->company = '';
				}
				$addressnew->phone = $adrsBilling->phone;
				$addressnew->phone_mobile = $adrsBilling->phone_mobile;

				$addressnew->address1 = $addr[0][2];
				$addressnew->postcode = $addr[0][3];
				$addressnew->city = $addr[0][4];
				$addressnew->country = BillmateCountry::getContryByNumber($addr[0][5]);
				$addressnew->alias = 'Bimport-'.time().ip2long($_SERVER['REMOTE_ADDR']);

				$addressnew->id_country = Country::getByIso(BillmateCountry::getCode($addr[0][5]));
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
				
				if (version_compare(_PS_VERSION_, '1.5', '>='))
				{
					if (Configuration::get('PS_ORDER_PROCESS_TYPE') == 1)
					{
						if (version_compare(_PS_VERSION_, '1.5', '>='))
							$carrierurl = $link->getPageLink("order-opc", true);
						else
							$carrierurl = $link->getPageLink("order-opc.php", true);

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
					}
					else
					{
						if (version_compare(_PS_VERSION_, '1.5', '>='))
							$carrierurl = $link->getPageLink("order", true);
						else
							$carrierurl = $link->getPageLink("order.php", true);

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
				}
				else
					$return = array('success' => true);

			}
			else
			{

				$countryname = '';
				if (BillmateCountry::getCode($addr[0][5]) != 'se')
					$countryname = billmate_translate_country(BillmateCountry::getCode($addr[0][5]));

				//$previouslink =
				$this->context->smarty->assign('firstname', $addr[0][0]);
				$this->context->smarty->assign('lastname', $addr[0][1]);
				$this->context->smarty->assign('address', $addr[0][2]);
				$this->context->smarty->assign('zipcode', $addr[0][3]);
				$this->context->smarty->assign('city', $addr[0][4]);
				$this->context->smarty->assign('country', $countryname);

				if (version_compare(_PS_VERSION_, '1.5', '>='))
					$previouslink = $link->getModuleLink('billmateinvoice', 'getaddress', array('ajax'=> 0,'clearFee' => true), true);
				else
					$previouslink = $link->getPageLink("order.php", true).'?step=3';

				$this->context->smarty->assign('previouslink', $previouslink);
				$extra = '.tpl';



				if ($this->context->getMobileDevice()) $extra = '-mobile.tpl';

				$html = $this->context->smarty->fetch(BILLMATE_BASE.'/views/templates/front/wrongaddress.tpl');
				$return = array( 'success'=> false, 'content'=> $html , 'popup' => true );
		   }
		}
		else
			$return = array('success' => true );

		if ($return['success'] && !isset($return['action']))
		{
			try{
				$data = $measurements = array();
				$api = null;
				$timetotalstart = $timestart = microtime(true);
				$timestart = microtime(true);

				$this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
				$measurements['deleteproduct'] = microtime(true) - $timestart;

				$timestart = microtime(true);
				$invoiceid = $this->processReserveInvoice(Tools::strtoupper(BillmateCountry::getCode($addr[0][5])));
				$measurements['add_invoice'] = microtime(true) - $timestart;
				
				$timestart = microtime(true);
				$customer = new Customer((int)$this->context->cart->id_customer);
				$measurements['customer'] = microtime(true) - $timestart;
				
				$timestart = microtime(true);
				$total = $this->context->cart->getOrderTotal();
				
				$measurements['calculatetotal'] = microtime(true) - $timestart;
				
				$timestart = microtime(true);
				$extra = array('transaction_id'=>$invoiceid);
				
				if ((version_compare(_PS_VERSION_, '1.5', '<')))
					$this->module = new BillmatePartpayment();

				$this->module->validateOrder((int)$this->context->cart->id, (Configuration::get('BILLMATE_ORDER_STATUS_SWEDEN')) ? Configuration::get('BILLMATE_ORDER_STATUS_SWEDEN') : Configuration::get('PS_OS_PAYMENT'), $total, $this->module->displayName, null, $extra, null, false, $customer->secure_key);
				$order_id = $this->module->currentOrder;
				$measurements['validateorder'] = microtime(true) - $timestart;
				
				//billmate_log_data(array(array('order_id'=>$order_id,'measurements'=>$measurements)), $eid );
				
				$timestart = microtime(true);

				$k->UpdateOrderNo($invoiceid, (string)$order_id);
				unset($_SESSION["uniqueId"]);
				$measurements['update_order_no'] = microtime(true) - $timestart;
				
				$duration = (microtime(true) - $timetotalstart) * 1000;
				$k->stat("client_order_measurements", Tools::jsonEncode(array('order_id'=>$order_id, 'measurements'=>$measurements)), '', $duration);

				if(version_compare(_PS_VERSION_, '1.5', '>='))
				{
					$url = 'order-confirmation&id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->module->id.'&id_order='.(int)$order_id.'&key='.$customer->secure_key;
					$return['redirect'] = Context::getContext()->link->getPageLink($url);
				}
				else
					$return['redirect'] = __PS_BASE_URI__.'order-confirmation.php?key='.$customer->secure_key.'&id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->module->id.'&id_order='.(int)$order_id;


			}catch(Exception $ex ){
				$this->context->cart->deleteProduct((int)Configuration::get('BM_INV_FEE_ID_'.$countryname));
				$return['success'] = false;
				unset($return['redirect']);

				$return['content'] = Tools::safeOutput(utf8_encode($ex->getMessage()));
			}
		}

		die(Tools::jsonEncode($return));
  }
	public function processReserveInvoice($isocode)
	{
		if (version_compare(_PS_VERSION_, '1.5', '<'))
			$this->context->controller->module = new BillmatePartpayment();

		$cart = $this->context->cart;
		$order_id2 = Tools::substr($cart->id.'-'.time(), 0, 10);//Order::getOrderByCartId((int)$cart->id);
		$order_id = $order_id2 == '' ? time(): $order_id2;

		$adrsDelivery = new Address((int)$this->context->cart->id_address_delivery);
		$adrsBilling = new Address((int)$this->context->cart->id_address_invoice);
		$country = Tools::strtoupper($adrsDelivery->country);

		$countryname = BillmateCountry::getContryByNumber(BillmateCountry::fromCode($isocode));
		$countryname = Tools::strtoupper($countryname);

		$eid = (int)Configuration::get('BILLMATE_STORE_ID_'.$countryname);
		$secret = Configuration::get('BILLMATE_SECRET_'.$countryname);

		$ssl = true;
		$debug = false;

		$k = new BillMate($eid, $secret, $ssl, $debug, Configuration::get('BILLMATE_MOD'));

		$personalnumber = trim(Tools::getValue('billmate_pno'));
		$country_to_currency = array(
			'NOR' => 'NOK',
			'SWE' => 'SEK',
			'FIN' => 'EUR',
			'DNK' => 'DKK',
			'DEU' => 'EUR',
			'NLD' => 'EUR',
		);

		switch ($isocode)
		{
			// Sweden
			case 'SE':
				$country = 209;
				$language = 138;
				$encoding = 2;
				$currency = 0;
				break;
			// Finland
			case 'FI':
				$country = 73;
				$language = 37;
				$encoding = 4;
				$currency = 2;
				break;
			// Denmark
			case 'DK':
				$country = 59;
				$language = 27;
				$encoding = 5;
				$currency = 3;
				break;
			// Norway
			case 'NO':
				$country = 164;
				$language = 97;
				$encoding = 3;
				$currency = 1;
				break;
			// Germany
			case 'DE':
				$country = 81;
				$language = 28;
				$encoding = 6;
				$currency = 2;
				break;
			// Netherlands
			case 'NL':
				$country = 154;
				$language = 101;
				$encoding = 7;
				$currency = 2;
				break;
		}
	
		$ship_address = array(
			'email'           => $this->context->customer->email,
			'telno'           => $adrsDelivery->phone,
			'cellno'          => $adrsDelivery->phone_mobile,
			'fname'           => $adrsDelivery->firstname,
			'lname'           => $adrsDelivery->lastname,
			'company'         => $adrsDelivery->company,
			'careof'          => '',
			'street'          => $adrsDelivery->address1,
			'house_number'    => '',
			'house_extension' => '',
			'zip'             => $adrsDelivery->postcode,
			'city'            => $adrsDelivery->city,
			'country'         => $adrsDelivery->country,
		);

		$bill_address = array(
			'email'           => $this->context->customer->email,
			'telno'           => $adrsBilling->phone,
			'cellno'          => $adrsBilling->phone_mobile,
			'fname'           => $adrsBilling->firstname,
			'lname'           => $adrsBilling->lastname,
			'company'         => $adrsBilling->company,
			'careof'          => '',
			'street'          => $adrsBilling->address1,
			'house_number'    => '',
			'house_extension' => '',
			'zip'             => $adrsBilling->postcode,
			'city'            => $adrsBilling->city,
			'country'         => $adrsBilling->country,
		);

		 foreach($ship_address as $key => $col)
		 {
			if (!is_array($col))
				$ship_address[$key] = utf8_decode( Encoding::fixUTF8($col));

		}
		foreach( $bill_address as $key => $col )
		{
			if (!is_array($col))
				$bill_address[$key] = utf8_decode( Encoding::fixUTF8($col));

		}
		$cart_details = $this->context->cart->getSummaryDetails(null, true);
		$this->context->cart->update();
		
		$products = $this->context->cart->getProducts();
		$taxrate = 0;
		$goods_list = array();
		foreach ($products as $product)
		{
			if (!empty($product['price']))
			{
				$taxrate = ($product['price_wt'] == $product['price']) ? 0 : $product['rate'];
				$goods_list[] = array(
					'qty'   => (int)$product['cart_quantity'],
					'goods' => array(
						'artno'    => $product['reference'],
						'title'    => $product['name'],
						'price'    => $product['price'] * 100,
						'vat'      => (float)$taxrate,
						'discount' => 0.0,
						'flags'    => 0,
					)
					
				);
			}
			$taxrate = $product['rate'];
		}

		$carrier = $cart_details['carrier'];
		if (!empty($cart_details['total_discounts']))
		{
			$discountamount = $cart_details['total_discounts'] / (($taxrate + 100) / 100);
			if (!empty($discountamount))
			{
				$goods_list[] = array(
					'qty'   => (int)1,
					'goods' => array(
						'artno'    => '',
						'title'    => $this->context->controller->module->l('Rabatt'),
						'price'    => 0 - abs($discountamount * 100),
						'vat'      => $taxrate,
						'discount' => 0.0,
						'flags'    => 0,
					)
					
				);
			}
		}

		// Do we have any gift products in cart
		if (isset($cart_details['gift_products']) && !empty($cart_details['gift_products']))
		{
			foreach ($cart_details['gift_products'] as $gift_product)
			{
				$discountamount = 0;
				foreach ($products as $product){
					if($gift_product['id_product'] == $product['id_product']){
						$taxrate = ($product['price_wt'] == $product['price']) ? 0 : $product['rate'];
						$discountamount = $product['price'];
						$ref = $product['reference'];
					}

				}
				$goods_list[] = array(
					'qty' => (int) $gift_product['cart_quantity'],
					'goods' => array(
						'artno' => $ref,
						'title' => $this->module->l('Gift :','getaddress').' '.$gift_product['name'],
						'price' => $gift_product['price'] - round($discountamount * 100,0),
						'vat' => $taxrate,
						'discount' => 0.0,
						'flags' => 0
					)
				);
			}
		}
		$notfree = !(isset($cart_details['free_ship']) && $cart_details['free_ship'] == 1);
		
		if ($carrier->active && $notfree)
		{
            if(version_compare(_PS_VERSION_, '1.5', '<'))
				$shippingPrice = $cart->getOrderShippingCost();
			else
				$shippingPrice = $cart->getTotalShippingCost();

			$carrier = new Carrier($cart->id_carrier, $this->context->cart->id_lang);
			if (version_compare(_PS_VERSION_, '1.5', '>='))
				$taxrate = $carrier->getTaxesRate(new Address($this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));
			else
				$taxrate = Tax::getCarrierTaxRate($cart->id_carrier, Configuration::get('PS_TAX_ADDRESS_TYPE'));

			if (!empty($shippingPrice))
			{
				$shippingPrice = $shippingPrice / (1 + $taxrate / 100);
				$goods_list[] = array(
					'qty'   => 1,
					'goods' => array(
						'artno'    => (string)$carrier->name.$cart->id_carrier,
						'title'    => $carrier->name,
						'price'    => $shippingPrice * 100,
						'vat'      => (float)$taxrate,
						'discount' => 0.0,
						'flags'    => 8, //16|32
					)
				);
			}
		}

        $label = array();

		$pclass = (int)Tools::getValue('paymentAccount');
		
		$transaction = array(
			'order1'=>(string)$order_id,
			'order2' => '',
			'comment'=>'',
			'flags'=>0,
			'gender'=>1,
			'reference'=>'',
			'reference_code'=>'',
			'currency'=>$currency,
			'country'=>$country,
			'language'=>$language,
			'pclass'=>$pclass,
			'shipInfo'=>array('delay_adjust'=>'1'),
			'travelInfo'=>array(),
			'incomeInfo'=>array(),
			'bankInfo'=>array(),
			'sid'=>array('time'=>microtime(true)),
			'extraInfo'=>array(array('cust_no'=>(int)$this->context->cart->id_customer))
		);
		if (empty($personalnumber) || empty($bill_address) || empty($ship_address) || empty($goods_list)) return false;
		$md5 = md5('partpayment_'.$eid.$secret.$personalnumber);

		$result1 = $k->AddInvoice($personalnumber, $bill_address, $ship_address, $goods_list, $transaction);

		if(is_string($result1) || isset($result1['error']) || !is_array($result1))
			throw new Exception($result1.' '.$personalnumber);

		return $result1[0];
	}
	public function postProcess()
	{

	}

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
	}
}

<?php
	/**
	 * Created by PhpStorm.* User: jesper* Date: 15-03-17 * Time: 15:09
	 *
	 * @author    Jesper Johansson jesper@boxedlogistics.se
	 * @copyright Billmate AB 2015
	 * @license   OpenSource
	 */

	require_once(_PS_MODULE_DIR_.'billmategateway/library/Billmate.php');


	class BillmategatewayBillmateapiModuleFrontController extends ModuleFrontController {

		protected $method;
		public $module;
		/**
		 * The total sum for costs
		 * @var $totals
		 */
		protected $totals = 0;
		/** @var int $paid_amount for use with Billmate Invoice to sett correct amount */
		protected $paid_amount = 0;
		/**
		 * The total tax amount
		 * @var $tax
		 */
		protected $tax = 0;

		/** @var array with the format array('taxrate' => 'totalamount') */
		protected $prepare_discount = array();
		/** @var  pno | The personal number if invoice or Partpay */
		protected $pno;
		protected $billmate;

		public function __construct()
		{
			if (!defined('BILLMATE_LANGUAGE'))
				define('BILLMATE_LANGUAGE', $this->context->language->iso_code);

			if (!defined('BILLMATE_CLIENT'))
				define('BILLMATE_CLIENT', 'PrestaShop:2.0.0');

		}

		public function postProcess()
		{
			$this->method = Tools::getValue('method');

			$eid    = Configuration::get('BILLMATE_ID');
			$secret = Configuration::get('BILLMATE_SECRET');
			$ssl    = true;
			$debug  = false;
			require_once(_PS_MODULE_DIR_.'billmategateway/methods/Billmate'.Tools::ucfirst($this->method).'.php');

			$class        = 'Billmate'.Tools::ucfirst($this->method);
			$this->module = new $class;

			$testmode = $this->module->testMode;

			$this->billmate = Common::getBillmate($eid, $secret, $testmode, $ssl, $debug);

			$this->pno = $this->method == 'invoice' || $this->method == 'partpay'
				? ((Tools::getIsset('pno_billmateinvoice'))
					? Tools::getValue('pno_billmateinvoice')
					: (Tools::getIsset('pno_billmatepartpay')
						? Tools::getValue('pno_billmatepartpay')
						: ''))
				: '';
			/**
			 * @var $data PaymentData
			 */
			$data = array();

			switch ($this->method)
			{
				case 'invoice':
				case 'partpay':
					$result = $this->checkAddress();

					if (is_array($result))
						die(Tools::jsonEncode($result));

					$data = $this->prepareInvoice($this->method);
					break;
				case 'bankpay':
				case 'cardpay':
					$data = $this->prepareDirect($this->method);
					break;
			}

			// Populate Data with the Customer Data and Cart stuff
			$data['Customer'] = $this->prepareCustomer();
			$data['Articles'] = $this->prepareArticles();
			$data['Cart']     = $this->prepareTotals();

			$result = $this->billmate->addPayment($data);

			$this->sendResponse($result);
		}

		/**
		 * Returns the Customer object
		 * @return array
		 */
		public function prepareCustomer()
		{
			$customer             = array();
			$customer['nr']       = $this->context->cart->id_customer;
			$customer['pno']      = ($this->method == 'invoice' || $this->method == 'partpay') ? $this->pno : '';
			$billing_address       = new Address($this->context->cart->id_address_invoice);
			$shipping_address      = new Address($this->context->cart->id_address_delivery);
			$customer['Billing']  = array(
				'firstname' => $billing_address->firstname,
				'lastname'  => $billing_address->lastname,
				'company'   => $billing_address->company,
				'street'    => $billing_address->address1,
				'street2'   => '',
				'zip'       => $billing_address->postcode,
				'city'      => $billing_address->city,
				'country'   => Country::getNameById($this->context->language->id, $billing_address->id_country),
				'phone'     => $billing_address->phone,
				'email'     => $this->context->customer->email
			);
			$customer['Shipping'] = array(
				'firstname' => $shipping_address->firstname,
				'lastname'  => $shipping_address->lastname,
				'company'   => $shipping_address->company,
				'street'    => $shipping_address->address1,
				'street2'   => '',
				'zip'       => $shipping_address->postcode,
				'city'      => $shipping_address->city,
				'country'   => Country::getNameById($this->context->language->id, $shipping_address->id_country),
				'phone'     => $shipping_address->phone,
			);

			return $customer;
		}

		/**
		 * Returns the Articles object and sets the totals of the Articles
		 * @return array
		 */
		public function prepareArticles()
		{
			$articles_arr = array();
			$articles    = $this->context->cart->getProducts();
			foreach ($articles as $article)
			{
				$taxrate       = ($article['price_wt'] == $article['price']) ? 0 : $article['rate'];
				$articles_arr[] = array(
					'quantity'   => $article['cart_quantity'],
					'title'      => $article['name'],
					'artnr'      => $article['reference'],
					'aprice'     => ($article['price'] / $article['cart_quantity']) * 100,
					'taxrate'    => $taxrate,
					'discount'   => 0,
					'withouttax' => $article['price'] * 100

				);
				if (!isset($this->prepare_discount))
					$this->prepare_discount[$taxrate] = $article['price'];
				else
					$this->prepare_discount[$taxrate] += $article['price'];

				$this->totals += $article['price'] * 100;
				$this->tax += ($article['price'] * ($taxrate / 100)) * 100;

			}

			return $articles_arr;
		}

		public function prepareDiscounts()
		{
			$details = $this->context->cart->getSummaryDetails(null, true);

			$discounts = array();
			if (!empty($details['total_discounts']))
			{
				foreach ($this->prepare_discount as $key => $value)
				{
					$amount_incl_tax   = $value * ($key / 100);
					$percent_discount = $amount_incl_tax / $this->context->cart->getOrderTotal();

					$discount_amount = ($percent_discount * $details['total_discount']) / (1 + ($key / 100));
					$discounts[]    = array(
						'quantity'   => 1,
						'artnr'      => 'discount-'.$key,
						'title'      => sprintf($this->module->l('Discount $1%s% VAT'), $key),
						'aprice'     => -($discount_amount * 100),
						'taxrate'    => $key,
						'discount'   => 0,
						'withouttax' => -$discount_amount * 100
					);

					$this->totals -= $discount_amount * 100;
					$this->tax -= $discount_amount * ($key / 100) * 100;

				}

			}
			if (!empty($details['gift_products']))
			{
				foreach ($details['gift_products'] as $gift)
				{
					$discount_amount = 0;
					$taxrate        = 0;
					foreach ($this->context->cart->getProducts() as $product)
					{
						$taxrate        = ($product['price_wt'] == $product['price']) ? 0 : $product['rate'];
						$discount_amount = $product['price'];
					}
					$price          = $gift['price'] / $gift['cart_quantity'];
					$discount_amount = $discount_amount / $gift['cart_quantity'];
					$total          = $discount_amount * $gift['cart_quantity'] * 100;
					$discounts[]    = array(
						'quantity'   => $gift['cart_quantity'],
						'artnr'      => $this->module->l('Discount'),
						'title'      => $gift['name'],
						'aprice'     => $price - round($discount_amount * 100, 0),
						'taxrate'    => $taxrate,
						'discount'   => 0,
						'withouttax' => $total
					);

					$this->totals -= $total;
					$this->tax -= $total * ($taxrate / 100);
				}
			}

			return $discounts;
		}

		/**
		 * Returns the Cart Object with Totals for Handling, Shipping and Total
		 * @return array
		 */
		public function prepareTotals()
		{
			$totals     = array();
			$details    = $this->context->cart->getSummaryDetails(null, true);
			$carrier    = $details['carrier'];
			$order_total = $this->context->cart->getOrderTotal();
			$notfree    = !(isset($details['free_ship']) && $details['free_ship'] == 1);

			if ($carrier->active && $notfree)
			{
				$carrier_obj = new Carrier($this->context->cart->id_carrier, $this->context->cart->id_lang);
				$taxrate    = $carrier_obj->getTaxesRate(new Address($this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));

				$total_shipping_cost  = $this->context->cart->getTotalShippingCost();
				$totals['Shipping'] = array(
					'withouttax' => $total_shipping_cost * 100,
					'taxrate'    => $taxrate
				);
				$this->totals += $total_shipping_cost * 100;
				$this->tax += ($total_shipping_cost * ($taxrate / 100)) * 100;
			}
			if (Configuration::get('BINVOICE_FEE') > 0 && $this->method == 'invoice')
			{
				$fee           = Configuration::get('BINVOICE_FEE');
				$invoice_fee_tax = Configuration::get('BINVOICE_FEE_TAX');

				$tax                = new Tax($invoice_fee_tax);
				$tax_calculator      = new TaxCalculator(array($tax));
				$tax_rate            = $tax_calculator->getTotalRate();
				$totals['Handling'] = array(
					'withouttax' => $fee * 100,
					'taxrate'    => $tax_rate
				);
				$order_total += $fee * (1 + ($tax_rate / 100));
				$this->totals += $fee * 100;
				$this->tax += (($tax_rate / 100) * $fee) * 100;
			}
			$rounding         = ($order_total * 100) - $this->tax - $this->totals;
			$totals['Total']  = array(
				'withouttax' => $this->totals,
				'tax'        => $this->tax,
				'rounding'   => $rounding,
				'withtax'    => $this->totals + $this->tax + $rounding
			);
			$this->paid_amount = $totals['Total']['withtax'];

			return $totals;
		}

		/**
		 * Check if the address is matched with our Api
		 * @return true|Array
		 */
		public function checkAddress()
		{
			$address = $this->billmate->getAddress(array('pno' => $this->pno));
			if (isset($address['code']))
			{
				$return = array('success' => false, 'content' => $address['message']);
				die(Tools::jsonEncode($return));
			}
			foreach ($address as $key => $value)
				$address[$key] = utf8_encode($value);

			$billing  = new Address($this->context->cart->id_address_invoice);
			$shipping = new Address($this->context->cart->id_address_delivery);

			$user_ship = $shipping->firstname.' '.$shipping->lastname;
			$user_bill = $billing->firstname.' '.$billing->lastname;

			$first_arr = explode(' ', $shipping->firstname);
			$last_arr  = explode(' ', $shipping->lastname);

			$apifirst = explode(' ', $address['firstname']);
			$apilast  = explode(' ', $address['lastname']);

			$matched_first = array_intersect($first_arr, $apifirst);
			$matched_last  = array_intersect($last_arr, $apilast);

			$api_matched_name = !empty($matched_first) && !empty($matched_last);

			$address_same = Common::matchstr($user_ship, $user_bill) &&
							Common::matchstr($billing->city, $shipping->city) &&
							Common::matchstr($billing->postcode, $shipping->postcode) &&
							Common::matchstr($billing->address1, $shipping->address1);
			if (!(
				$api_matched_name
				&& Common::matchstr($shipping->address1, $address['street'])
				&& Common::matchstr($shipping->postcode, $address['zip'])
				&& Common::matchstr($shipping->city, $address['city'])
				&& Common::matchstr($address['country'], $shipping->country)
				&& $address_same
			))
			{
				if (Tools::getValue('geturl') == 'yes')
				{
					// The customer clicked yes
					$cart_details = $this->context->cart->getSummaryDetails(null, true);
					$carrier_id   = $cart_details['carrier']->id;

					$carrier = Carrier::getCarrierByReference($cart_details['carrier']->id_reference);

					$customer_addresses = $this->context->customer->getAddresses($this->context->language->id);

					if (count($customer_addresses) == 1)
						$customer_addresses[] = $customer_addresses;

					$matched_address_id = false;
					foreach ($customer_addresses as $customer_address)
					{
						if (Common::matchstr($customer_address['address1'], $address['street']) &&
							Common::matchstr($customer_address['postcode'], $address['zip']) &&
							Common::matchstr($customer_address['city'], $address['city']) &&
							Common::matchstr($customer_address['country'], $address['country']))
							$matched_address_id = $customer_address['id'];

					}
					if (!$matched_address_id)
					{
						$addressnew              = new Address();
						$addressnew->id_customer = (int)$this->context->customer->id;

						$addressnew->firstname = $address['firstname'];
						$addressnew->lastname  = $address['lastname'];
						$addressnew->company   = $address['company'];

						$addressnew->phone        = $billing->phone;
						$addressnew->phone_mobile = $billing->phone_mobile;

						$addressnew->address1 = $address['street'];
						$addressnew->postcode = $address['zip'];
						$addressnew->city     = $address['city'];
						$addressnew->country  = $address['country'];
						$addressnew->alias    = 'Bimport-'.date('Y-m-d');

						$addressnew->id_country = Country::getIdByName($this->context->language->id, $address['country']);
						$addressnew->save();
						$matched_address_id = $addressnew->id;
					}
					$this->context->cart->updateAddressId($this->context->cart->id_address_delivery, $matched_address_id);

					$this->context->cart->id_address_invoice  = (int)$matched_address_id;
					$this->context->cart->id_address_delivery = (int)$matched_address_id;
					$this->context->cart->update();

					if (Configuration::get('PS_ORDER_PROCESS_TYPE') == 1)
					{
						$return = array(
							'success' => true,
							'action'  => array(
								'method'                                 => 'updateCarrierAndGetPayments',
								//updateExtraCarrier
								'gift'                                   => 0,
								'gift_message'                           => '',
								'recyclable'                             => 0,
								'delivery_option['.$matched_address_id.']' => $carrier->id.',',
								'ajax'                                   => true,
								'token'                                  => Tools::getToken(false),
							)
						);
					}
					else
					{
						$return = array(
							'success' => true,
							'action'  => array(
								'method'             => 'updateExtraCarrier',
								'id_delivery_option' => $carrier_id.',',
								'id_address'         => $matched_address_id,
								'allow_refresh'      => 1,
								'ajax'               => true,
								'token'              => Tools::getToken(false),
							)
						);
					}
				}
				else
				{
					$this->context->smarty->assign(array(
						'ps_version' => _PS_VERSION_,
						'method'     => $this->method
					));
					$this->context->smarty->assign('firstname', $address['firstname']);
					$this->context->smarty->assign('lastname', $address['lastname']);
					$this->context->smarty->assign('address', $address['street']);
					$this->context->smarty->assign('zipcode', $address['zip']);
					$this->context->smarty->assign('city', $address['city']);
					$this->context->smarty->assign('country', $address['country']);

					$previouslink = $this->context->link->getPageLink('order.php', true).'?step=3';
					$this->context->smarty->assign('previousLink', $previouslink);

					$html   = $this->context->smarty->fetch(_PS_MODULE_DIR_.'billmategateway/views/templates/front/wrongaddress.tpl');
					$return = array('success' => false, 'content' => $html, 'popup' => true);
				}

				return $return;
			}
			else
				return true;

		}

		/**
		 * A method for invoicePreparation for Invoice and Partpayment
		 * @return array Billmate Request
		 */
		public function prepareInvoice($method)
		{
			$payment_data                = array();
			$payment_data['PaymentData'] = array(
				'method'        => ($method == 'invoice') ? 1 : 4,
				'paymentplanid' => ($method == 'partpay') ? Tools::getValue('paymentAccount') : '',
				'currency'      => Tools::strtoupper($this->context->currency->iso_code),
				'language'      => Tools::strtolower($this->context->language->iso_code),
				'country'       => Tools::strtolower($this->context->country->iso_code),
				'orderid'       => Tools::substr($this->context->cart->id.'-'.time(), 0, 10)
			);

			$payment_data['PaymentInfo'] = array(
				'paymentdate' => date('Y-m-d')
			);

			return $payment_data;

		}

		/**
		 * A method for taking care of Direct and Card payments
		 */
		public function prepareDirect($method)
		{
			$payment_data                = array();
			$payment_data['PaymentData'] = array(
				'method'   => ($method == 'cardpay') ? 8 : 16,
				'currency' => Tools::strtoupper($this->context->currency->iso_code),
				'language' => Tools::strtolower($this->context->language->iso_code),
				'country'  => Tools::strtolower($this->context->country->iso_code),
				'orderid'  => Tools::substr($this->context->cart->id.'-'.time(), 0, 10)
			);
			$payment_data['PaymentInfo'] = array(
				'paymentdate' => date('Y-m-d')
			);

			$payment_data['Card'] = array(
				'promptname'   => ($method == 'cardpay' && Configuration::get('BILLMATE_CARD_PROMPT')) ? 1 : 0,
				'3dsecure'     => ($method == 'cardpay' && Configuration::get('BILLMATE_CARD_3DSECURE')) ? 1 : 0,
				'accepturl'    => $this->context->link->getModuleLink('billmategateway', 'accept', array('method' => $this->method)),
				'cancelurl'    => $this->context->link->getModuleLink('billmategateway', 'cancel', array('method' => $this->method)),
				'callbackurl'  => $this->context->link->getModuleLink('billmategateway', 'callback', array('method' => $this->method)),
				'returnmethod' => 'POST'
			);

			return $payment_data;
		}

		public function sendResponse($result)
		{
			$return = array();
			switch ($this->method)
			{
				case 'invoice':
				case 'partpay':
					if (!isset($result['code']))
					{
						$status   = ($this->method == 'invoice') ? Configuration::get('BINVOICE_ORDER_STATUS') : Configuration::get('BPARTPAY_ORDER_STATUS');
						$extra    = array('transaction_id' => $result['number']);
						$total    = $this->context->cart->getOrderTotal();
						$customer = new Customer((int)$this->context->cart->id_customer);
						$this->module->validateOrder((int)$this->context->cart->id,
								$status,
								($this->method == 'invoice') ? $this->paid_amount : $total,
								$this->module->displayName,
								null, $extra, null, false, $customer->secure_key);
						$values                = array();
						$values['PaymentData'] = array(
							'number'  => $result['number'],
							'orderid' => (Configuration::get('BILLMATE_SEND_REFERENCE')) ? $this->module->currentOrderReference : $this->module->currentOrder
						);
						$this->billmate->updatePayment($values);

						$url                = 'order-confirmation&id_cart='.(int)$this->context->cart->id.
											  '&id_module='.(int)$this->module->id.'&id_order='.(int)$this->module->currentOrder.
											  '&key='.$customer->secure_key;
						$return['success']  = true;
						$return['redirect'] = $this->context->link->getPageLink($url);
					}
					else
						$return = array('success' => false, 'content' => utf8_encode($result['message']));

					break;
				case 'bankpay':
				case 'cardpay':
					if (!isset($result['code']))
						$return = array('success' => true, 'redirect' => $result['url']);
					else
						$return = array('success' => false, 'content' => utf8_encode($result['message']));

					break;
			}
			die(Tools::JsonEncode($return));
		}

	}
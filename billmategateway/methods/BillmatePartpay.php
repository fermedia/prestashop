<?php
	/**
	 * Created by PhpStorm.* User: jesper* Date: 15-03-17 * Time: 15:09
	 *
	 * @author    Jesper Johansson jesper@boxedlogistics.se
	 * @copyright Billmate AB 2015
	 * @license   OpenSource
	 */

	/*
	 * Class for BillmatePartpayment related stuff
	 */

	class BillmatePartpay extends BillmateGateway {

		public function __construct()
		{
			parent::__construct();
			$this->name                 = 'billmatepartpay';
			$this->displayName          = $this->l('Billmate Part Pay');
			$this->testMode             = Configuration::get('BPARTPAY_MOD');
			$this->limited_countries    = array('sv');
			$this->allowed_currencies   = array('SEK');
			$this->authorization_method = false;
			$this->validation_controller = $this->context->link->getModuleLink('billmategateway', 'billmateapi', array('method' => 'partpay'));
			$this->icon                 = 'billmategateway/views/img/billmate_partpayment_l.png';
		}

		/**
		 * Returns Payment info for appending in payment methods list
		 */
		public function getPaymentInfo($cart)
		{
			if (!pClasses::hasPclasses(Language::getIsoById($cart->id_lang)) || Configuration::get('BPARTPAY_ENABLED') == 0)
				return false;

			return array(
				'name'         => $this->displayName,
				'type'         => $this->name,
				'controller'   => $this->validation_controller,
				'icon'         => $this->icon,
				'agreements'   => sprintf($this->l('My email is accurate and can be used for invoicing.').'<a id="terms" style="cursor:pointer!important"> '.$this->l('I confirm the terms for invoice payment').'</a>'),
				'pClasses'     => $this->getPclasses($cart),
				'monthly_cost' => $this->getMonthlyCost($cart)

			);
		}

		public function getSettings()
		{
			$settings       = array();
			$statuses       = OrderState::getOrderStates((int)$this->context->language->id);
			$currency       = Currency::getCurrency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
			$statuses_array = array();
			foreach ($statuses as $status)
				$statuses_array[$status['id_order_state']] = $status['name'];

			$settings['activated'] = array(
				'name'     => 'partpayActivated',
				'required' => true,
				'type'     => 'checkbox',
				'label'    => $this->l('Enabled'),
				'desc'     => $this->l('Should Billmate Partpay be Enabled'),
				'value'    => (Tools::safeOutput(Configuration::get('BPARTPAY_ENABLED'))) ? 1 : 0,

			);

			$settings['testmode'] = array(
				'name'     => 'partpayTestmode',
				'required' => true,
				'type'     => 'checkbox',
				'label'    => $this->l('Test Mode'),
				'desc'     => $this->l('Enable Test Mode'),
				'value'    => (Tools::safeOutput(Configuration::get('BPARTPAY_MOD'))) ? 1 : 0
			);

			$settings['order_status']  = array(
				'name'     => 'partpayBillmateOrderStatus',
				'required' => true,
				'type'     => 'select',
				'label'    => $this->l('Set Order Status'),
				'desc'     => $this->l(''),
				'value'    => (Tools::safeOutput(Configuration::get('BPARTPAY_ORDER_STATUS'))) ? Tools::safeOutput(Configuration::get('BPARTPAY_ORDER_STATUS')) : Tools::safeOutput(Configuration::get('PS_OS_PAYMENT')),
				'options'  => $statuses_array
			);
			$settings['minimum_value'] = array(
				'name'     => 'partpayBillmateMinimumValue',
				'required' => false,
				'value'    => (float)Configuration::get('BPARTPAY_MIN_VALUE'),
				'type'     => 'text',
				'label'    => $this->l('Minimum Value ').' ('.$currency['sign'].')',
				'desc'     => $this->l(''),
			);
			$settings['maximum_value'] = array(
				'name'     => 'partpayBillmateMaximumValue',
				'required' => false,
				'value'    => Configuration::get('BPARTPAY_MAX_VALUE') != 0 ? (float)Configuration::get('BPARTPAY_MAX_VALUE') : 99999,
				'type'     => 'text',
				'label'    => $this->l('Maximum Value ').' ('.$currency['sign'].')',
				'desc'     => $this->l(''),
			);
			$pclasses = new pClasses(Configuration::get('BILLMATE_ID'));
			$settings['paymentplans'] = array(
				'name' => 'paymentplans',
				'label' => $this->l('Paymentplans'),
				'type' => 'table',
				'pclasses' => $pclasses->getPClasses('')
			);
			return $settings;

		}

		public function getPclasses($cart)
		{
			$pclasses = new pClasses(Configuration::get('BILLMATE_ID'));

			return $pclasses->getPClasses('', $this->context->language->iso_code, true, $this->context->cart->getOrderTotal());
		}

		public function getMonthlyCost($cart)
		{
			$pclasses = new pClasses(Configuration::get('BILLMATE_ID'));

			return $pclasses->getCheapestPClass($this->context->cart->getOrderTotal(), BillmateFlags::CHECKOUT_PAGE, $this->context->language->iso_code);
		}
	}
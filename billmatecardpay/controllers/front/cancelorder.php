<?php

require_once BCARDPAY_BASE. '/Billmate.php';
require_once BCARDPAY_BASE .'/lib/billmateCart.php';

class BillmateCardpayCancelorderModuleFrontController extends ModuleFrontController
{
	public $ssl = true;
	public $ajax = true;

	public function postProcess()
	{
		$eid = (int)Configuration::get('BCARDPAY_STORE_ID_SETTINGS');
		$secret = Configuration::get('BCARDPAY_SECRET_SETTINGS');
		$ssl = true;
		$debug = false;
		$k = new BillMate($eid,$secret,$ssl,$debug,Configuration::get('BCARDPAY_MOD'));
		$response_data = $k->verify_hash($_REQUEST);

		$this->context = Context::getContext();

		$order = new Order($response_data['orderid']);

		$new_history = new OrderHistory();
		$new_history->id_order = (int)$order->id;
		$new_history->changeIdOrderState((int)Configuration::get('PS_OS_CANCELED'), $order->id, true);
		$new_history->addWithemail(true);
		$orderUrl = $this->context->link->getPageLink('order.php', true);
		Tools::redirectLink($orderUrl);
	}
	
	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		$this->display_column_left = false;
	}
}
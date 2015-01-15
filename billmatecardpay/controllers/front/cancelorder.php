<?php

require_once BCARDPAY_BASE. '/Billmate.php';
require_once BCARDPAY_BASE .'/lib/billmateCart.php';

class BillmateCardpayCancelorderModuleFrontController extends ModuleFrontController
{
	public $ssl = true;
	public $ajax = true;

	public function postProcess()
	{
		$response_data = json_decode($_REQUEST['data']);
		$this->context = Context::getContext();
		$ids = explode("-",$response_data->orderid);
		if( sizeof($ids) < 2 ) return false;
		$order_id = $ids[0]; $cart_id = $ids[1];
		$order = new Order($order_id);

		$new_history = new OrderHistory();
		$new_history->id_order = (int)$order->id;
		$new_history->changeIdOrderState((int)Configuration::get('PS_OS_CANCELED'), $order, true);
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
<?php
require_once BBANK_BASE. '/Billmate.php';
require_once BBANK_BASE .'/lib/billmateCart.php';

class BillmateBankCallbackModuleFrontController extends ModuleFrontController
{
	public $ssl = true;
	public $ajax = true;

	public function postProcess()
	{
		$this->context = Context::getContext();
		$input = file_get_contents("php://input");
		//$input = '{"credentials":{"hash":"a7d093069679d0dcb866d714d365c8b83c766ee2a9a389ed89a4bfc0da246b4933302ea5ff2230e30a441ec58a2f0bbce0ba4643d3408c9e1223240197985f73"},"data":{"number":"6149","status":"Paid","url":"http:\/\/billmate.se\/invoice\/","orderid":"511-144"}}';

		$eid = (int)Configuration::get('BCARDPAY_STORE_ID_SETTINGS');
		$secret = Configuration::get('BCARDPAY_SECRET_SETTINGS');
		$ssl = true;
		$debug = false;
		$k = new BillMate($eid,$secret,$ssl,$debug,Configuration::get('BCARDPAY_MOD'));
		$_DATA = $k->verify_hash($input);
		$order = new Order($_DATA['orderid']);
		if(isset($_DATA['code'])){
			$new_history = new OrderHistory();
			$new_history->id_order = (int)$_DATA['orderid'];
			$new_history->changeIdOrderState((int)Configuration::get('PS_OS_ERROR'), $_DATA['orderid'], true);
			$new_history->addWithemail(true);
			$error = Tools::displayError('Order Paiment failed: '.$_DATA['message']);
			PrestaShopLogger::addLog($error, 4, '0000002', 'Cart', intval($order->id_cart));
			die($error);
		}
		$invoiceid = $_DATA['number'];

		if( isset($_DATA['status']) && ($order->module == 'billmatebank') ) {
			$invoiceid = $_DATA['number'];
			echo 'Updating order';
			$t = new billmateCart();
			$t->id = $_DATA['orderid'];
			$cart = Order::getCartByOrderId($_DATA['orderid']);
			$this->context->cart->id = (int)$cart->id;
			
			$t->completeOrder(array(),$cart->id);
			$this->context->cart = Cart::getCartByOrderId($_DATA['orderid']);
			$this->context->cart->id = (int)$cart->id;

			$extra = array('transaction_id'=>$invoiceid);
			$order = new Order($_DATA['orderid']);
			if( !empty($extra)){
				Db::getInstance()->update('order_payment',$extra,'order_reference="'.$order->reference.'"');
			}

			$eid = (int)Configuration::get('BBANK_STORE_ID_SWEDEN');
			$secret = Configuration::get('BBANK_SECRET_SWEDEN');
			$ssl = true;
			$debug = false;
			//$k = new BillMate($eid,$secret,$ssl,$debug,Configuration::get('BBANK_MOD'));
			$k->UpdatePayment( array('PaymentData'=> array("number"=>(string)$invoiceid, "orderid"=>(string)$_DATA['orderid'], "currency" => "SEK", "language" => "sv", "country" => "se")) );
			
		}
		exit("finalize");
	}
	
	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		$this->display_column_left = false;
	}
}
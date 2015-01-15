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
		
		$input = json_decode($input, true); $_DATA = $input['data'];
		$ids = explode("-",$_DATA['orderid']);
		if( sizeof($ids) < 2 ) return false;
		$_DATA['order_id'] = $ids[0];
		$_DATA['cart_id'] = $ids[1];
		$invoiceid = $_DATA['number'];
		
		$order = new Order($_DATA['order_id']);

		if( isset($_DATA['status']) && ($order->module == 'billmatebank') ) {
			echo 'Updating order';
			$t = new billmateCart();
			$t->id = $_DATA['order_id'];
			$this->context->cart->id = (int)$_DATA['cart_id'];
			
			$t->completeOrder(array(),$_DATA['cart_id']);
			$this->context->cart = Cart::getCartByOrderId($_DATA['order_id']);
			$this->context->cart->id = (int)$_DATA['cart_id'];

			$extra = array('transaction_id'=>$invoiceid);
			$order = new Order($_DATA['order_id']);
			if( !empty($extra)){
				Db::getInstance()->update('order_payment',$extra,'order_reference="'.$order->reference.'"');
			}

			$eid = (int)Configuration::get('BBANK_STORE_ID_SWEDEN');
			$secret = Configuration::get('BBANK_SECRET_SWEDEN');
			$ssl = true;
			$debug = false;
			$k = new BillMate($eid,$secret,$ssl,$debug,Configuration::get('BBANK_MOD'));
			$k->UpdatePayment( array('PaymentData'=> array("number"=>(string)$invoiceid, "orderid"=>(string)$_DATA['order_id'], "currency" => "SEK", "language" => "sv", "country" => "se")) );
			
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
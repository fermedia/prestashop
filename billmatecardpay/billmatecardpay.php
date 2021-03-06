<?php
/**
 * The file for specified the Billmate Cardpay payment module
 *
 * PHP Version 5.3
 *
 * @category  Payment
 * @package   BillMate_Prestashop
 * @author    Gagan Preet <gaganpreet172@gmail.com>
 */

if (!defined('_CAN_LOAD_FILES_'))
	exit;

define('BCARDPAY_BASE', dirname(dirname(__FILE__)).'/billmateinvoice');
include_once(BCARDPAY_BASE . '/Billmate.php');
include_once(_PS_MODULE_DIR_.'/billmateinvoice/commonfunctions.php');
require_once(BCARDPAY_BASE.'/lib/BillmateNew.php');

/**
 * BillmateCardpay class
 *
 * @category  Payment
 * @package   BillMate_Prestashop
 * @author    Gagan Preet <gaganpreet172@gmail.com>
 * 
 */
define('CARDPAY_TESTURL', 'https://cardpay.billmate.se/pay/test');
define('CARDPAY_LIVEURL', 'https://cardpay.billmate.se/pay');

class BillmateCardpay extends PaymentModule
{
	private $_html = '';

	private $_postErrors = array();
	public $billmate;
	public $kitt;
	public $core;
	public $settings;
	public $billmate_merchant_id;
	public $billmate_secret;
	public $billmate_pending_status;
	public $billmate_accepted_status;
	public $billmate_countries;

	private $_postValidations = array();
	private $countries = array(
		'SE' => array('name' =>'SETTINGS', 'code' => BillmateCountry::SE, 'langue' => BillmateLanguage::SV, 'currency' => BillmateCurrency::SEK)
	);

	const RESERVED = 1;
	const SHIPPED = 2;
	const CANCEL = 3;

	/**
	 * Constructor for BillmateCardpay
	 */
	public function __construct()
	{
		$this->name = 'billmatecardpay';
		$this->moduleName='billmatecardpay';
		$this->tab = 'payments_gateways';
		$this->version = '1.36';
		$this->author  = 'Billmate AB';

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		parent::__construct();
		$this->core = null;
		$this->billmate = null;
		$this->country = null;
		//$this->limited_countries = array('se'); //, 'no', 'fi', 'dk', 'de', 'nl'

		/* The parent construct is required for translations */
		$this->page = basename(__FILE__, '.php');
		$this->displayName = $this->l('Billmate Cardpay');
		$this->description = $this->l('Accepts cardpay payments by Billmate');
		$this->confirmUninstall = $this->l(
			'Are you sure you want to delete your settings?'
		);
		$this->billmate_merchant_id = Configuration::get('BCARDPAY_MERCHANT_ID');
		$this->billmate_secret = Configuration::get('BCARDPAY_SECRET');
		$this->billmate_countries = unserialize( Configuration::get('BILLMATE_ENABLED_COUNTRIES_LIST'));
		require(_PS_MODULE_DIR_.'billmatepartpayment/backward_compatibility/backward.php');
	}

	private function _displayForm()
	{


		$this->_html .=
		'<form action="'.Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']).'" method="post">
			<fieldset>
			<legend><img src="../img/admin/contact.gif" />'.$this->l('Billmate Configurations:').'</legend>
				<table border="0" width="100%" cellpadding="0" cellspacing="0" id="form">
					<tr><td colspan="2">'.$this->l('Billmate Credentials').'.<br /><br /></td></tr>
					<tr><td width="130" style="height: 35px;">'.$this->l('Merchant ID:').'</td><td><input type="text" name="billmate_merchant_id" value="'.htmlentities( Tools::getValue('billmate_merchant_id', $this->billmate_merchant_id), ENT_COMPAT, 'UTF-8').'" style="width: 300px;" /></td></tr>
					<tr><td width="130" style="height: 35px;">'.$this->l('Billmate Secret:').'</td><td><input type="text" name="billmate_secret" value="'.htmlentities(Tools::getValue('billmate_secret', $this->billmate_secret), ENT_COMPAT, 'UTF-8').'" style="width: 300px;" /></td></tr>
					<tr><td colspan="2" align="center"><input class="button" name="btnSubmit" value="'.$this->l('Update settings').'" type="submit" /></td></tr>
				</table>
			</fieldset>
		</form>';
	}
    public function hookDisplayBackOfficeHeader()
    {
        if (isset($this->context->cookie->error) && strlen($this->context->cookie->error) > 2)
        {
            if (get_class($this->context->controller) == "AdminOrdersController")
            {
                $this->context->controller->errors[] = $this->context->cookie->error;
                unset($this->context->cookie->error);
                unset($this->context->cookie->error_orders);
            }
        }
        if(isset($this->context->cookie->diff) && strlen($this->context->cookie->diff) > 2){
            if (get_class($this->context->controller) == "AdminOrdersController")
            {
                $this->context->controller->errors[] = $this->context->cookie->diff;
                unset($this->context->cookie->diff);
                unset($this->context->cookie->diff_orders);
            }
        }
	    if (isset($this->context->cookie->api_error) && strlen($this->context->cookie->api_error) > 2){
		    if (get_class($this->context->controller) == "AdminOrdersController")
		    {
			    $this->context->controller->errors[] = $this->context->cookie->api_error;
			    unset($this->context->cookie->api_error);
			    unset($this->context->cookie->api_error_orders);
		    }
	    }
        if (isset($this->context->cookie->information) && strlen($this->context->cookie->information) > 2)
        {
            if (get_class($this->context->controller) == "AdminOrdersController")
            {
                $this->context->controller->warnings[] = $this->context->cookie->information;
                unset($this->context->cookie->information);
                unset($this->context->cookie->information_orders);
            }
        }
        if (isset($this->context->cookie->confirmation) && strlen($this->context->cookie->confirmation) > 2)
        {
            if (get_class($this->context->controller) == "AdminOrdersController")
            {
                $this->context->controller->confirmations[] = $this->context->cookie->confirmation;
                unset($this->context->cookie->confirmation);
                unset($this->context->cookie->confirmation_orders);
            }
        }
    }
    public function hookActionOrderStatusUpdate($params){
        $orderStatus = Configuration::get('BCARDPAY_ACTIVATE_ON_STATUS');
        $activated = Configuration::get('BCARDPAY_ACTIVATE');
        if($orderStatus && $activated) {
            $order_id = $params['id_order'];

            $id_status = $params['newOrderStatus']->id;
            $order = new Order($order_id);

            $payment = OrderPayment::getByOrderId($order_id);
            $orderStatus = unserialize($orderStatus);
            if ($order->module == $this->moduleName && Configuration::get('BCARDPAY_AUTHMOD') != 'sale' && in_array($id_status,$orderStatus)) {
                $eid = Configuration::get('BCARDPAY_STORE_ID_SETTINGS');
                $secret = Configuration::get('BCARDPAY_SECRET_SETTINGS');
                $testMode = Configuration::get('BCARDPAY_MOD');
                $ssl = true;
                $debug = false;

                $k = new BillMate((int)$eid, $secret, $ssl, $debug, $testMode);
                $api = new BillMateNew($eid, $secret, $ssl, $testMode, $debug);
                $invoice = $k->CheckInvoiceStatus((string)$payment[0]->transaction_id);
                if (Tools::strtolower($invoice) == 'created'){
                    $resultCheck = $api->getPaymentinfo(array('number' => $payment[0]->transaction_id));
                    $total = $resultCheck['Cart']['Total']['withtax'] / 100;
                    $orderTotal = $order->getTotalPaid();
                    $diff = $total - $orderTotal;
                    $diff = abs($diff);
                    if($diff <= 1)
                    {
                        $result = $k->ActivateInvoice((string)$payment[0]->transaction_id);
                        if (is_string($result) || !is_array($result) || isset($result['error'])) {
                            $this->context->cookie->error = (isset($result['error'])) ? utf8_encode($result['error']) : utf8_encode($result);
                            $this->context->cookie->error_orders = isset($this->context->cookie->error_orders) ? $this->context->cookie->error_orders . ', ' . $order_id : $order_id;
                        }

                        $this->context->cookie->confirmation = !isset($this->context->cookie->confirmation_orders) ? sprintf($this->l('Order %s has been activated through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se/faktura">' . $this->l('Open Billmate Online') . '</>)' : sprintf($this->l('The following orders has been activated through Billmate: %s'), $this->context->cookie->confirmation_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                        $this->context->cookie->confirmation_orders = isset($this->context->cookie->confirmation_orders) ? $this->context->cookie->confirmation_orders . ', ' . $order_id : $order_id;
                    }
                    elseif (isset($resultCheck['code']))
                    {
	                    if ($resultCheck['code'] == 5220) {
		                    $mode                             = $testMode ? 'test' : 'live';
		                    $this->context->cookie->api_error = ! isset( $this->context->cookie->api_error_orders ) ? sprintf( $this->l('Order %s failed to activate through Billmate. The order does not exist in Billmate Online. The order exists in (%s) mode however. Try changing the mode in the modules settings.'), $order_id, $mode ) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)' : sprintf( $this->l('The following orders failed to activate through Billmate: %s. The orders does not exist in Billmate Online. The orders exists in (%s) mode however. Try changing the mode in the modules settings.'), $this->context->cookie->api_error_orders, '. ' . $order_id, $mode ) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
	                    }
	                    else
		                    $this->context->cookie->api_error = $resultCheck['message'];

	                    $this->context->cookie->api_error_orders = isset($this->context->cookie->api_error_orders) ? $this->context->cookie->api_error_orders.', '.$order_id : $order_id;

                    }
                    else
                    {
                        $this->context->cookie->diff = !isset($this->context->cookie->diff_orders) ? sprintf($this->l('Order %s failed to activate through Billmate. The amounts don\'t match: %s, %s. Activate manually in Billmate Online.'),$order_id, $orderTotal, $total).' (<a target="_blank" href="http://online.billmate.se">'.$this->l('Open Billmate Online').'</a>)' : sprintf($this->l('The following orders failed to activate through Billmate: %s. The amounts don\'t match. Activate manually in Billmate Online.'),$this->context->cookie->diff_orders.', '.$order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                        $this->context->cookie->diff_orders = isset($this->context->cookie->diff_orders) ? $this->context->cookie->diff_orders.', '.$order_id : $order_id;
                    }
                }
                elseif(Tools::strtolower($invoice) == 'paid') {
                    $this->context->cookie->information = !isset($this->context->cookie->information_orders) ? sprintf($this->l('Order %s is already activated through Billmate.'),$order_id).' (<a target="_blank" href="http://online.billmate.se">'.$this->l('Open Billmate Online').'</a>)' : sprintf($this->l('The following orders has already been activated through Billmate: %s'),$this->context->cookie->information_orders.', '.$order_id).' (<a target="_blank" href="http://online.billmate.se">'.$this->l('Open Billmate Online').'</a>)';
                    $this->context->cookie->information_orders = isset($this->context->cookie->information_orders) ? $this->context->cookie->information_orders . ', ' . $order_id : $order_id;
                }
                else {
                    $this->context->cookie->error = !isset($this->context->cookie->error_orders) ? sprintf($this->l('Order %s failed to activate through Billmate.'),$order_id).' (<a target="_blank" href="http://online.billmate.se">'.$this->l('Open Billmate Online').'</a>)' : sprintf($this->l('The following orders failed to activate through Billmate: %s.'),$this->context->cookie->error_orders.', '.$order_id).' (<a target="_blank" href="http://online.billmate.se">'.$this->l('Open Billmate Online').'</a>)';
                    $this->context->cookie->error_orders = isset($this->context->cookie->error_orders) ? $this->context->cookie->error_orders . ', ' . $order_id : $order_id;
                }

            }
        }
        //Logger::AddLog(print_r($params,true));
    }

	/**
	 * Get the content to display on the backend page.
	 *
	 * @return string
	 */
	public function getContent()
	{
		$html = '';
		if (version_compare(_PS_VERSION_, '1.5', '>'))
			$this->context->controller->addJQueryPlugin('fancybox');
		else
			$html .= '<script type="text/javascript" src="'.__PS_BASE_URI__.'js/jquery/jquery.fancybox-1.3.4.js"></script><link type="text/css" rel="stylesheet" href="'.__PS_BASE_URI__.'css/jquery.fancybox-1.3.4.css" />';



		if (!empty($_POST) && Tools::getIsset('submitBillmate'))
		{
			$this->_postValidation();
			if (count($this->_postValidations))
				$html .= $this->_displayValidation();
			if (count($this->_postErrors))
				$html .= $this->_displayErrors();
		}
		$html .= $this->_displayAdminTpl();
		return $html;
	}
	public function enable($force_all = false)
	{
		parent::enable($force_all);
		Configuration::updateValue('BCARDPAY_ACTIVE_CARDPAY', true);
	}
	public function disable($force_all = false)
	{
		parent::disable($force_all);
		Configuration::updateValue('BCARDPAY_ACTIVE_CARDPAY', false);
	}

	/**
	 * @brief Method that will displayed all the tabs in the configurations forms
	 *
	 * @return Rendered form
	 */
	private function _displayAdminTpl()
	{
		$smarty = $this->context->smarty;

		$tab = array(
			'credential' => array(
				'title' => $this->l('Settings'),
				'content' => $this->_displayCredentialTpl(),
				'icon' => '../modules/'.$this->moduleName.'/img/icon-settings.gif',
				'tab' => 1,
				'selected' => true, //other has to be false
			),
		);

		$smarty->assign('tab', $tab);
		$smarty->assign('moduleName', $this->moduleName);
		$smarty->assign($this->moduleName.'Logo', '../modules/'.$this->moduleName.'/img/logo.png');
		$smarty->assign('js', array('../modules/'.$this->moduleName.'/js/billmate.js'));
		$smarty->assign($this->moduleName.'Css', '../modules/'.$this->moduleName.'/css/billmate.css');

		return $this->display(__FILE__, 'tpl/admin.tpl');
	}
	/**
	 * @brief Credentials Form Method
	 *
	 * @return Rendered form
	 */
	private function _displayCredentialTpl()
	{
		$smarty = $this->context->smarty;
		$activateCountry = array();
		$currency = Currency::getCurrency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
		$statuses = OrderState::getOrderStates((int)$this->context->language->id);
		$statuses_array = array();
		$countryNames = array();
		$countryCodes = array();
		$input_country = array();

		foreach ($statuses as $status)
			$statuses_array[$status['id_order_state']] = $status['name'];
		foreach ($this->countries as $country)
		{
			$countryNames[$country['name']] = array('flag' => '../modules/'.$this->moduleName.'/img/flag_SWEDEN.png', 'country_name' => $country['name']);
			$countryCodes[$country['code']] = $country['name'];

			$input_country[$country['name']]['eid_'.$country['name']] = array(
				'name' => 'billmateStoreId'.$country['name'],
				'required' => true,
				'value' => Tools::safeOutput(Configuration::get('BCARDPAY_STORE_ID_'.$country['name'])),
				'type' => 'text',
				'label' => $this->l('E-store ID'),
				'desc' => $this->l(''),
			);
			$input_country[$country['name']]['secret_'.$country['name']] = array(
				'name' => 'billmateSecret'.$country['name'],
				'required' => true,
				'value' => Tools::safeOutput(Configuration::get('BCARDPAY_SECRET_'.$country['name'])),
				'type' => 'text',
				'label' => $this->l('Secret'),
				'desc' => $this->l(''),
			);
			$input_country[$country['name']]['order_status_'.$country['name']] = array(
				'name' => 'billmateOrderStatus'.$country['name'],
				'required' => true,
				'type' => 'select',
				'label' => $this->l('Set Order Status'),
				'desc' => $this->l(''),
				'value'=> (Tools::safeOutput(Configuration::get('BCARDPAY_ORDER_STATUS_'.$country['name']))) ? Tools::safeOutput(Configuration::get('BCARDPAY_ORDER_STATUS_'.$country['name'])) : Tools::safeOutput(Configuration::get('PS_OS_PAYMENT')),
				'options' => $statuses_array
			);			
			$input_country[$country['name']]['minimum_value_'.$country['name']] = array(
				'name' => 'billmateMinimumValue'.$country['name'],
				'required' => false,
				'value' => (float)Configuration::get('BCARDPAY_MIN_VALUE_'.$country['name']),
				'type' => 'text',
				'label' => $this->l('Minimum Value ').' ('.$currency['sign'].')',
				'desc' => $this->l(''),
			);
			$input_country[$country['name']]['maximum_value_'.$country['name']] = array(
				'name' => 'billmateMaximumValue'.$country['name'],
				'required' => false,
				'value' => Configuration::get('BCARDPAY_MAX_VALUE_'.$country['name']) != 0 ? (float)Configuration::get('BCARDPAY_MAX_VALUE_'.$country['name']) : 99999,
				'type' => 'text',
				'label' => $this->l('Maximum Value ').' ('.$currency['sign'].')',
				'desc' => $this->l(''),
			);

			if (Configuration::get('BCARDPAY_STORE_ID_'.$country['name']))
				$activateCountry[] = $country['name'];
		}
        $activateStatuses = array();
        $activateStatuses = $activateStatuses + $statuses_array;
        $status_activate = array(
            'name' => 'billmateActivateOnOrderStatus[]',
            'required' => true,
            'id' => 'activationSelect',
            'type' => 'select_activate',
            'label' => $this->l('Order statuses for automatic order activation in Billmate Online'),
            'desc' => $this->l(''),
            'value'=> (Tools::safeOutput(Configuration::get('BCARDPAY_ACTIVATE_ON_STATUS'))) ? unserialize(Configuration::get('BCARDPAY_ACTIVATE_ON_STATUS')) : 0,
            'options' => $activateStatuses
        );
		$smarty->assign($this->moduleName.'FormCredential',	'./index.php?tab=AdminModules&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&tab_module='.$this->tab.'&module_name='.$this->name);
		$smarty->assign($this->moduleName.'CredentialTitle', $this->l('Location'));
		$smarty->assign($this->moduleName.'CredentialText', $this->l('In order to use the Billmate module, please select your host country and supply the appropriate credentials.'));
		$smarty->assign($this->moduleName.'CredentialFootText', $this->l('Please note: The selected currency and country must match the customers\' registration').'<br/>'.
			$this->l('E.g. Swedish customer, SEK, Sweden and Swedish.').'<br/>'.
			$this->l('In order for your customers to use Billmate, your customers must be located in the same country in which your e-store is registered.'));

        if(version_compare(_PS_VERSION_, '1.5', '>='))
            $showActivate = true;
        else
            $showActivate = false;


        $smarty->assign(array(
                'show_activate' => $showActivate,
                'billmate_activation' => Configuration::get('BCARDPAY_ACTIVATE'),
                'status_activate' => $status_activate,
				'billmate_mod' => Configuration::get('BCARDPAY_MOD'),
				'billmate_prompt_name' => Configuration::get('BILL_PRNAME'),
				'billmate_3dsecure' => strlen(Configuration::get('BILL_3DSECURE'))?Configuration::get('BILL_3DSECURE'):'YES',
				'billmate_authmod'=>Configuration::get('BCARDPAY_AUTHMOD', 'sale'),
				'billmate_return_method' => 'GET',
				'billmate_active_cardpay' => Configuration::get('BCARDPAY_ACTIVE_CARDPAY'),
				'credentialInputVar' => $input_country,
				'countryNames' => $countryNames,
				'countryCodes' => $countryCodes,
				'img' => '../modules/'.$this->moduleName.'/img/',
				'activateCountry' => $activateCountry));

		return $this->display(__FILE__, 'tpl/credential.tpl');
	}

	/**
	 * @brief Error display Method
	 *
	 * @return
	 */
	private function _displayErrors()
	{
		$this->context->smarty->assign('billmateError', $this->_postErrors);
		return $this->display(__FILE__, 'tpl/error.tpl');
	}

	/**
	 * @brief Validate Method
	 *
	 * @return update the module depending on billmate webservices
	 */

	private function _postValidation()
	{

		if (Tools::getValue('billmate_mod') == 'live')
			Configuration::updateValue('BCARDPAY_MOD', 0);
		else
			Configuration::updateValue('BCARDPAY_MOD', 1);

		Configuration::updateValue('BCARDPAY_AUTHMOD', Tools::getValue('billmate_authmod'));

		//$prompt = !empty($_POST['billmate_prompt_name']) ? $_POST['billmate_prompt_name'] : 'NO';
		//$p3dsecure = !empty($_POST['billmate_3dsecure']) ? $_POST['billmate_3dsecure'] : 'NO';
		$prompt = Tools::getValue('billmate_prompt_name','NO');
		$p3dsecure = Tools::getValue('billmate_3dsecure','NO');
		
		Configuration::updateValue('BILL_PRNAME', $prompt);
		Configuration::updateValue('BILL_3DSECURE', $p3dsecure );
		
		if (Tools::getIsset('billmate_active_cardpay') && Tools::getValue('billmate_active_cardpay'))
			Configuration::updateValue('BCARDPAY_ACTIVE_CARDPAY', true);
		else
			billmate_deleteConfig('BCARDPAY_ACTIVE_CARDPAY');

		if (Tools::getValue('billmate_activation') == 1)
			Configuration::updateValue('BCARDPAY_ACTIVATE_ON_STATUS',serialize(Tools::getValue('billmateActivateOnOrderStatus')));

		Configuration::updateValue('BCARDPAY_ACTIVATE', Tools::getValue('billmate_activation'));

        foreach ($this->countries as $country)
		{
			billmate_deleteConfig('BCARDPAY_STORE_ID_'.$country['name']);
			billmate_deleteConfig('BCARDPAY_SECRET_'.$country['name']);
		}

		foreach ($this->countries as $key => $country)
		{

			if (Tools::getIsset('activate'.$country['name']))
			{
				$storeId = (int)Tools::getValue('billmateStoreId'.$country['name']);
				$secret = pSQL(Tools::getValue('billmateSecret'.$country['name']));

				Configuration::updateValue('BCARDPAY_STORE_ID_'.$country['name'], $storeId);
				Configuration::updateValue('BCARDPAY_SECRET_'.$country['name'], $secret);
				Configuration::updateValue('BCARDPAY_ORDER_STATUS_'.$country['name'], (int)(Tools::getValue('billmateOrderStatus'.$country['name'])));
				Configuration::updateValue('BCARDPAY_MIN_VALUE_'.$country['name'], (float)Tools::getValue('billmateMinimumValue'.$country['name']));
				Configuration::updateValue('BCARDPAY_MAX_VALUE_'.$country['name'], (Tools::getValue('billmateMaximumValue'.$country['name']) != 0 ? (float)Tools::getValue('billmateMaximumValue'.$country['name']) : 99999));

				$this->_postValidations[] = $this->l('Your account has been updated to be used in ').$country['name'];
			}
		} 
	}
	/**
	 * @brief Validation display Method
	 *
	 * @return
	 */
	private function _displayValidation()
	{
		$this->context->smarty->assign('billmateValidation', $this->_postValidations);
		return $this->display(__FILE__, 'tpl/validation.tpl');
	}


	private function _postProcess()
	{   

		if (Tools::isSubmit('btnSubmit'))
		{
			Configuration::updateValue('BCARDPAY_MERCHANT_ID_', Tools::getValue('billmate_merchant_id'));
			Configuration::updateValue('BCARDPAY_SECRET_', Tools::getValue('billmate_secret'));

		}
		$this->_html .= '<div class="conf confirm"> '.$this->l('Settings updated').'</div>';
	}

	/******************************************************************/
	/** add payment state ***********************************/
	/******************************************************************/
	private function addState($en, $color)
	{
		$orderState = new OrderState();
		$orderState->name = array();
		foreach (Language::getLanguages() as $language)
			$orderState->name[$language['id_lang']] = $en;
		$orderState->send_email = false;
		$orderState->color = $color;
		$orderState->hidden = false;
		$orderState->delivery = false;
		$orderState->logable = true;
		if ($orderState->add())
			copy(dirname(__FILE__).'/logo.gif', dirname(__FILE__).'/../../img/os/'.(int)$orderState->id.'.gif');
		return $orderState->id;
	}
	/**
	 * Install the Billmate Cardpay module
	 *
	 * @return bool
	 */
	public function install()
	{
		if (!parent::install() || !$this->registerHook('payment') || !$this->registerHook('adminOrder') || !$this->registerHook('paymentReturn') || !$this->registerHook('orderConfirmation'))
			return false;


		$this->registerHook('displayPayment');
		$this->registerHook('header');
        $this->registerHook('actionOrderStatusUpdate');
		if (!Configuration::get('BILLMATE_PAYMENT_ACCEPTED'))
			Configuration::updateValue('BILLMATE_PAYMENT_ACCEPTED', $this->addState('Billmate : Payment accepted', '#DDEEFF'));
		if (!Configuration::get('BILLMATE_PAYMENT_PENDING'))
			Configuration::updateValue('BILLMATE_PAYMENT_PENDING', $this->addState('Billmate : payment in pending verification', '#DDEEFF'));

        $include = array();
        $hooklists = Hook::getHookModuleExecList('displayBackOfficeHeader');
        foreach($hooklists as $hooklist)
        {
            if(!in_array($hooklist['module'],array('billmatebank','billmateinvoice','billmatepartpayment'))){
                $include[] = true;
            }
        }
        if(in_array(true,$include))
            $this->registerHook('displayBackOfficeHeader');
		/*auto install currencies*/
		$currencies = array(
			'Euro' => array('iso_code' => 'EUR', 'iso_code_num' => 978, 'symbole' => '€', 'format' => 2),
			'Danish Krone' => array('iso_code' => 'DKK', 'iso_code_num' => 208, 'symbole' => 'DAN kr.', 'format' => 2),
			'krone' => array('iso_code' => 'NOK', 'iso_code_num' => 578, 'symbole' => 'NOK kr', 'format' => 2),
			'Krona' => array('iso_code' => 'SEK', 'iso_code_num' => 752, 'symbole' => 'SEK kr', 'format' => 2)
		);


		foreach ($currencies as $key => $val)
		{
			if (_PS_VERSION_ >= 1.5)
				$exists = Currency::exists($val['iso_code_num'], $val['iso_code_num']);
			else
				$exists = Currency::exists($val['iso_code_num']);
			if (!$exists)
			{
				$currency = new Currency();
				$currency->name = $key;
				$currency->iso_code = $val['iso_code'];
				$currency->iso_code_num = $val['iso_code_num'];
				$currency->sign = $val['symbole'];
				$currency->conversion_rate = 1;
				$currency->format = $val['format'];
				$currency->decimals = 1;
				$currency->active = true;
				$currency->add();
			}
		}
		
		Currency::refreshCurrencies();
		
		$version = str_replace('.', '', _PS_VERSION_);
		$version = Tools::substr($version, 0, 2);
		
		
		/* The hook "displayMobileHeader" has been introduced in v1.5.x - Called separately to fail silently if the hook does not exist */


		return true;
	}

	public function hookOrderConfirmation($params){
		global $smarty;

	}

	/**
	 * Hook the payment module to be shown in the payment selection page
	 *
	 * @param array $params The Smarty instance params
	 *
	 * @return string
	 */
	public function hookdisplayPayment($params)
	{
	
		return $this->hookPayment($params);
	}
	
	public function hookPayment($params)
	{
		global $smarty, $link;

		if ( !Configuration::get('BCARDPAY_ACTIVE_CARDPAY'))
			return false;
		//Rabatt($this->context->language);
		//die;
		
		$total = $this->context->cart->getOrderTotal();

		$cart = $params['cart'];
		$address = new Address((int)$cart->id_address_delivery);
		$country = new Country((int)$address->id_country);

		$countryname = BillmateCountry::getContryByNumber(BillmateCountry::fromCode($country->iso_code));
		$countryname = Tools::strtoupper($countryname);

		$minVal = Configuration::get('BCARDPAY_MIN_VALUE_SETTINGS');
		$maxVal = Configuration::get('BCARDPAY_MAX_VALUE_SETTINGS');

		if (version_compare(_PS_VERSION_, '1.5', '>='))
			$moduleurl = $link->getModuleLink('billmatecardpay', 'validation', array(), true);
		else
			$moduleurl = __PS_BASE_URI__.'modules/billmatecardpay/validation.php';

		$smarty->assign('moduleurl', $moduleurl);
		
		if ($total > $minVal && $total < $maxVal)
		{
			if(version_compare(_PS_VERSION_, '1.6','>='))
				return $this->display(__FILE__, 'billmatecardpay.tpl');
			else
				return $this->display(__FILE__, 'billmatecardpay-legacy.tpl');
		}else
			return false;

	}

	/**
	 * Hook the payment module to display its confirmation template upon
	 * a successful purchase
	 *
	 * @param array $params The Smarty instance params
	 *
	 * @return string
	 */
	public function hookPaymentReturn($params)
	{
		global $cart;

		//customers were to reorder an order done with billmate cardpay.
		$cart->save();
		if (!$this->active)
			return;


		 return $this->display(dirname(__FILE__), 'confirmation.tpl');
	}

}

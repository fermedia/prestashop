<?php
require 'Flags.php';
require 'billmatecalc.php';

class pClasses{
	private $data = null;
	private $config = array();
	private $_eid = null;
	public function __construct($eid='', $secret='', $country='', $language='', $currency='', $mode = 'live' ){

		$this->config['eid'] = $eid;
		$this->config['secret'] = $secret;
		$this->config['country']  = $country;
		$this->config['language'] = $language;
		$this->config['currency'] = $currency;
		$this->config['mode'] = $mode;
		$this->getPClasses($eid);
	}
	public function clear(){
		Db::getInstance()->Execute('truncate `'._DB_PREFIX_.'billmate_payment_pclasses`');
	}
	public function Save($eid, $secret, $country, $language, $currency, $mode = 'live' ){
		$testmode = $mode == 'beta';
        $ssl=true;
        $debug = false;
        $billmate = new Billmate($eid, $secret, $ssl, $debug, $testmode);

		$additionalinfo = array();
		$additionalinfo['PaymentData'] = array(
			"currency" => strtoupper($currency),
			"language" => strtolower($language),
			"country" => strtolower($country),
		);

        $data = $billmate->GetPaymentPlans($additionalinfo);

		$db = Db::getInstance();
		$db->Execute('TRUNCATE TABLE `'._DB_PREFIX_.'billmate_payment_pclasses`');
		if( !is_array($data)){
			throw new Exception(strip_tags($data));
		} else {
			array_walk($data, array($this,'correct_lang_billmate'));
			foreach($data as $key => $_row){
				if( empty($_row['minamount']) ) continue;
				$_row['country'] = 209;
				$_row['eid'] = $eid;
				$_row['id'] = ($key+1);
				if((version_compare(_PS_VERSION_,'1.5','>='))){
					Db::getInstance()->insert('billmate_payment_pclasses',$_row);
				} else {
					$data = $_row;
					array_walk($data,create_function('&$value, $idx','$value = "`".$idx."`=\'$value\'";'));
					$result &= $db->Execute('insert into `'._DB_PREFIX_.'billmate_payment_pclasses` SET '.implode(',',$data));
				}
			}
		}
	}
	
    function correct_lang_billmate(&$item, $index){
		$item['startfee'] /= 100;
		$item['handlingfee'] /= 100;
		$item['minamount'] /= 100;
		$item['maxamount'] /= 100;
        $keys = array('description', 'nbrofmonths', 'startfee','handlingfee', 'minamount', 'maxamount', 'type', 'expirydate', 'interestrate' );
        foreach( $item as $key=>$ele ) 
			if( !in_array($key, $keys) ) unset($item[$key]);
    }
	
	public function getCheapestPClass($sum, $flags){
        $lowest_pp = $lowest = false;

		$pclasses = $this->getPClasses();
        foreach ( $pclasses as $pclass) {
			if( $pclass !== false ){
				$lowest_payment = BillmateCalc::get_lowest_payment_for_account( $pclass['country'] );
				if ($pclass['type'] < 2 && $sum >= $pclass['minamount'] && ($sum <= $pclass['maxamount'] || $pclass['maxamount'] == 0) ) {
					$minpay = BillmateCalc::calc_monthly_cost( $sum, $pclass, $flags );
					
					if ($minpay < $lowest_pp || $lowest_pp === false) {
						if ($minpay >= $lowest_payment ) {
							$lowest_pp = $minpay;
							$lowest = $pclass;
						}
					}
					
				}
			}
        }

        return $lowest;	
	}
	public function getPClasses($eid = ''){
		if(!empty($eid) && $eid != $this->_eid || !is_array($this->data)){
			$this->_eid = $eid;
			if($_SERVER['REMOTE_ADDR'] == '122.173.227.3'){
			//echo 'SELECT * FROM `'._DB_PREFIX_.'billmate_payment_pclasses` where eid="'.$this->_eid.'"';
			}
			$this->data = Db::getInstance()->ExecuteS('SELECT * FROM `'._DB_PREFIX_.'billmate_payment_pclasses` where eid="'.$this->_eid.'"');
		}
		
		if( !is_array($this->data) ) $this->data = array();
		return $this->data;
	}
}
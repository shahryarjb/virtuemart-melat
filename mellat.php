<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_virtuemart
 * @subpackage 	Trangell_mellat
 * @copyright   trangell team => https://trangell.com
 * @copyright   Copyright (C) 20016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined ('_JEXEC') or die('Restricted access');

if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}

if (!class_exists ('checkHack')) {
	require_once( VMPATH_ROOT . '/plugins/vmpayment/mellat/helper/inputcheck.php');
}


class plgVmPaymentMellat extends vmPSPlugin {

	function __construct (& $subject, $config) {

		parent::__construct ($subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array(
			'melatterminalid' => array('', 'varchar'),
			'melatuser' => array('', 'varchar'),
			'melatpass' => array('', 'varchar')
		);
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	public function getVmPluginCreateTableSQL () {
		return $this->createTableSQL ('Payment Mellat Table');
	}

	function getTableSQLFields () {

		$SQLfields = array(
			'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(1) UNSIGNED',
			'order_number'                => 'char(64)',
			'order_pass'                  => 'varchar(50)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'crypt_virtuemart_pid' 	      => 'varchar(255)',
			'salt'                        => 'varchar(255)',
			'payment_name'                => 'varchar(5000)',
			'amount'                      => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
			'email_currency'              => 'char(3)',
			'mobile'                      => 'varchar(12)',
			'tracking_code'               => 'varchar(50)',
			'cardnumber'                  => 'varchar(50)'
		);

		return $SQLfields;
	}


	function plgVmConfirmedOrder ($cart, $order) {
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return null; 
		}
		
		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; 
		}
		

		$session = JFactory::getSession();
		$salt = JUserHelper::genRandomPassword(32);
		$crypt_virtuemartPID = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id, $salt);
		if ($session->isActive('uniq')) {
			$session->clear('uniq');
		}
		$session->set('uniq', $crypt_virtuemartPID);
		$payment_currency = $this->getPaymentCurrency($method,$order['details']['BT']->payment_currency_id);
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$payment_currency);
		$currency_code_3 = shopFunctions::getCurrencyByID($payment_currency, 'currency_code_3');
		$email_currency = $this->getEmailCurrency($method);
		$dbValues['payment_name'] = $this->renderPluginName ($method) . '<br />';
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['order_pass'] = $order['details']['BT']->order_pass;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['crypt_virtuemart_pid'] = $crypt_virtuemartPID;
		$dbValues['salt'] = $salt;
		$dbValues['payment_currency'] = $order['details']['BT']->order_currency;
		$dbValues['email_currency'] = $email_currency;
		$dbValues['amount'] = $totalInPaymentCurrency['value'];
		$dbValues['mobile'] = $order['details']['BT']->phone_2;
		$this->storePSPluginInternalData ($dbValues);
		$id = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id);
		$app	= JFactory::getApplication();
		$dateTime = JFactory::getDate();
			
		$fields = array(
			'terminalId' => $method->melatterminalid,
			'userName' => $method->melatuser,
			'userPassword' => $method->melatpass,
			'orderId' => time(),
			'amount' => $totalInPaymentCurrency['value'],
			'localDate' => $dateTime->format('Ymd'),
			'localTime' => $dateTime->format('His'),
			'additionalData' => '',
			'callBackUrl' => JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived',
			'payerId' => 0,
		);
		
		try {
			$soap = new SoapClient('https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl');
			$response = $soap->bpPayRequest($fields);
			
			$response = explode(',', $response->return);
			if ($response[0] != '0') { // if transaction fail
				$msg = $this->getGateMsg($response[0]); 
				$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
				$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
			}
			else { // if success
				$refId = $response[1];
				echo '
					<script>
						var form = document.createElement("form");
						form.setAttribute("method", "POST");
						form.setAttribute("action", "https://bpm.shaparak.ir/pgwchannel/startpay.mellat");
						form.setAttribute("target", "_self");

						var hiddenField = document.createElement("input");
						hiddenField.setAttribute("name", "RefId");
						hiddenField.setAttribute("value", "'.$refId.'");

						form.appendChild(hiddenField);

						document.body.appendChild(form);
						form.submit();
						document.body.removeChild(form);
					</script>'
				;
			}
		}
		catch(\SoapFault $e)  {
			$msg= $this->getGateMsg('error');
			$this->updateStatus ('P',0,$msg,$id);  
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
	}

public function plgVmOnPaymentResponseReceived(&$html) {	
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		$app	= JFactory::getApplication();		
		$jinput = JFactory::getApplication()->input;

		$session = JFactory::getSession();
		if ($session->isActive('uniq')) {
			$cryptID = $session->get('uniq'); 
			
		}
		else {
			$app	= JFactory::getApplication();
			$msg= $this->getGateMsg('notff'); 
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
		$orderInfo = $this->getOrderInfo ($cryptID);
		if ($orderInfo != null){
			if (!($currentMethod = $this->getVmPluginMethod($orderInfo->virtuemart_paymentmethod_id))) {
				return NULL; 
			}			
		}
		else {
			return NULL; 
		}
		
		$ResCode = $jinput->post->get('ResCode', '1', 'INT'); 
		$SaleOrderId = $jinput->post->get('SaleOrderId', '1', 'INT'); 
		$SaleReferenceId = $jinput->post->get('SaleReferenceId', '1', 'INT'); 
		$RefId = $jinput->post->get('RefId', 'empty', 'STRING'); 
		if (checkHack::strip($RefId) != $RefId )
			$RefId = "illegal";
		$CardNumber = $jinput->post->get('CardHolderPan', 'empty', 'STRING'); 
		if (checkHack::strip($CardNumber) != $CardNumber )
			$CardNumber = "illegal";
	
		
		$salt = $orderInfo->salt;
		$id = $orderInfo->virtuemart_order_id;
		$uId = $cryptID.':'.$salt;
		
		$order_id = $orderInfo->order_number; 
		//$mobile = $orderInfo->mobile; 
		$payment_id = $orderInfo->virtuemart_paymentmethod_id; 
		$pass_id = $orderInfo->order_pass;
		//$price = round($orderInfo->amount,5);
		$method = $this->getVmPluginMethod ($payment_id);
		
		if (JUserHelper::verifyPassword($id , $uId)) {
			if (
				checkHack::checkNum($ResCode) &&
				checkHack::checkNum($SaleOrderId) &&
				checkHack::checkNum($SaleReferenceId) 
			){
				if ($ResCode != '0') {
					$msg= $this->getGateMsg($ResCode); 
					if ($ResCode == '17')
						$this->updateStatus ('X',0,$msg,$id);
					$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
					$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
				}
				else {
					$fields = array(
						'terminalId' => $method->melatterminalid,
						'userName' => $method->melatuser,
						'userPassword' => $method->melatpass,
						'orderId' => $SaleOrderId, 
						'saleOrderId' =>  $SaleOrderId, 
						'saleReferenceId' => $SaleReferenceId
					);
					try {
						$soap = new SoapClient('https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl');
						$response = $soap->bpVerifyRequest($fields);

						if ($response->return != '0') {
							$msg= $this->getGateMsg($response->return); 
							if ($ResCode == '17')
								$this->updateStatus ('X',0,$msg,$id);
							$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
							$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
						}
						else {	
							$response = $soap->bpSettleRequest($fields);
							if ($response->return == '0' || $response->return == '45') {
								$msg= $this->getGateMsg($response->return); 
								$html = $this->renderByLayout('mellat_payment', array(
									'order_number' =>$order_id,
									'order_pass' =>$pass_id,
									'tracking_code' => $SaleReferenceId,
									'status' => $msg
								));
								$this->updateStatus ('C',1,$msg,$id);
								$this->updateOrderInfo ($id,$SaleReferenceId,$CardNumber);
								vRequest::setVar ('html', $html);
								$cart = VirtueMartCart::getCart();
								$cart->emptyCart();
								$session->clear('uniq'); 
							}
							else {
								$msg= $this->getGateMsg($response->return);
								if ($ResCode == '17')
									$this->updateStatus ('X',0,$msg,$id);
								$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
								$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
							}
						}
					}
					catch(\SoapFault $e)  {
						$msg= $this->getGateMsg('error'); 
						$app	= JFactory::getApplication();
						$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
						$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
					}
				}
			}
			else {
				$msg= $this->getGateMsg('hck2'); 
				$app	= JFactory::getApplication();
				$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
				$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
			}
		}
		else {	
			$msg= $this->getGateMsg('notff');
			$app	= JFactory::getApplication();
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
	}


	protected function getOrderInfo ($id){
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from($db->qn('#__virtuemart_payment_plg_mellat'));
		$query->where($db->qn('crypt_virtuemart_pid') . ' = ' . $db->q($id));
		$db->setQuery((string)$query); 
		$result = $db->loadObject();
		return $result;
	}

	protected function updateOrderInfo ($id,$trackingCode,$cardNumber){
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$fields = array($db->qn('tracking_code') . ' = ' . $db->q($trackingCode) , $db->qn('cardnumber') . ' = ' . $db->q($cardNumber));
		$conditions = array($db->qn('virtuemart_order_id') . ' = ' . $db->q($id));
		$query->update($db->qn('#__virtuemart_payment_plg_mellat'));
		$query->set($fields);
		$query->where($conditions);
		
		$db->setQuery($query);
		$result = $db->execute();
	}

	
	protected function checkConditions ($cart, $method, $cart_prices) {
		$amount = $this->getCartAmount($cart_prices);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		if($this->_toConvert){
			$this->convertToVendorCurrency($method);
		}
		
		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array ($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		if (!is_array ($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (count ($countries) == 0 || in_array ($address['virtuemart_country_id'], $countries) ) {
			return TRUE;
		}

		return FALSE;
	}
	
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return false;
			} else {
				return false;
			}
		}
		$method_name = $this->_psType . '_name';

		$htmla = array();
		foreach ($this->methods as $this->_currentMethod) {
			if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {

				$html = '';
				$cartPrices=$cart->cartPrices;
				if (isset($this->_currentMethod->cost_method)) {
					$cost_method=$this->_currentMethod->cost_method;
				} else {
					$cost_method=true;
				}
				$methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod, $cost_method);

				$this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod);
				$this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
				$html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
				$htmla[] = $html;
			}
		}
		$htmlIn[] = $htmla;
		return true;

	}
	
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return null; 
		}
		
		return $this->OnSelectCheck ($cart);
	}
 
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) { 
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) { 
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) { 
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return NULL; 
		}
		
		return true;
	}

	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}
	 
	
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {
		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}

	static function getPaymentCurrency (&$method, $selectedUserCurrency = false) {

		if (empty($method->payment_currency)) {
			$vendor_model = VmModel::getModel('vendor');
			$vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
			$method->payment_currency = $vendor->vendor_currency;
			return $method->payment_currency;
		} else {

			$vendor_model = VmModel::getModel( 'vendor' );
			$vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies( $method->virtuemart_vendor_id );

			if(!$selectedUserCurrency) {
				if($method->payment_currency == -1) {
					$mainframe = JFactory::getApplication();
					$selectedUserCurrency = $mainframe->getUserStateFromRequest( "virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt( 'virtuemart_currency_id', $vendor_currencies['vendor_currency'] ) );
				} else {
					$selectedUserCurrency = $method->payment_currency;
				}
			}

			$vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
			if(in_array($selectedUserCurrency,$vendor_currencies['all_currencies'])){
				$method->payment_currency = $selectedUserCurrency;
			} else {
				$method->payment_currency = $vendor_currencies['vendor_currency'];
			}

			return $method->payment_currency;
		}

	}

	public function getGateMsg ($msgId) {
		switch($msgId){
			case '0': $out =  'تراکنش با موفقیت انجام شد'; break;
			case '11': $out =  'شماره کارت نامعتبر است'; break;
			case '12': $out =  'موجودی کافی نیست'; break;
			case '13': $out =  'رمز نادرست است'; break;
			case '14': $out =  'تعداد دفعات وارد کردن رمز بیش از حد مجاز است'; break;
			case '15': $out =  'کارت نامعتبر است'; break;
			case '16': $out =  'دفعات برداشت وجه بیش از حد مجاز است'; break;
			case '17': $out =  'کاربر از انجام تراکنش منصرف شده است'; break;
			case '18': $out =  'تاریخ انقضای کارت گذشته است'; break;
			case '19': $out =  'مبلغ برداشت وجه بیش از حد مجاز است'; break;
			case '21': $out =  'پذیرنده نامعتبر است'; break;
			case '23': $out =  'خطای امنیتی رخ داده است'; break;
			case '24': $out =  'اطلاعات کاربری پذیرنده نادرست است'; break;
			case '25': $out =  'مبلغ نامتعبر است'; break;
			case '31': $out =  'پاسخ نامتعبر است'; break;
			case '32': $out =  'فرمت اطلاعات وارد شده صحیح نمی باشد'; break;
			case '33': $out =  'حساب نامعتبر است'; break;
			case '34': $out =  'خطای سیستمی'; break;
			case '35': $out =  'تاریخ نامعتبر است'; break;
			case '41': $out =  'شماره درخواست تکراری است'; break;
			case '42': $out =  'تراکنش Sale‌ یافت نشد'; break;
			case '43': $out =  'قبلا درخواست Verify‌ داده شده است'; break;
			case '44': $out =  'درخواست Verify‌ یافت نشد'; break;
			case '45': $out =  'تراکنش Settle‌ شده است'; break;
			case '46': $out =  'تراکنش Settle‌ نشده است'; break;
			case '47': $out =  'تراکنش  Settle یافت نشد'; break;
			case '48': $out =  'تراکنش Reverse شده است'; break;
			case '49': $out =  'تراکنش Refund یافت نشد'; break;
			case '51': $out =  'تراکنش تکراری است'; break;
			case '54': $out =  'تراکنش مرجع موجود نیست'; break;
			case '55': $out =  'تراکنش نامعتبر است'; break;
			case '61': $out =  'خطا در واریز'; break;
			case '111': $out =  'صادر کننده کارت نامعتبر است'; break;
			case '112': $out =  'خطا سوییج صادر کننده کارت'; break;
			case '113': $out =  'پاسخی از صادر کننده کارت دریافت نشد'; break;
			case '114': $out =  'دارنده کارت مجاز به انجام این تراکنش نیست'; break;
			case '412': $out =  'شناسه قبض نادرست است'; break;
			case '413': $out =  'شناسه پرداخت نادرست است'; break;
			case '414': $out =  'سازمان صادر کننده قبض نادرست است'; break;
			case '415': $out =  'زمان جلسه کاری به پایان رسیده است'; break;
			case '416': $out =  'خطا در ثبت اطلاعات'; break;
			case '417': $out =  'شناسه پرداخت کننده نامعتبر است'; break;
			case '418': $out =  'اشکال در تعریف اطلاعات مشتری'; break;
			case '419': $out =  'تعداد دفعات ورود اطلاعات از حد مجاز گذشته است'; break;
			case '421': $out =  'IP‌ نامعتبر است';  break;
			case 'error': $out ='خطا غیر منتظره رخ داده است';break;
			case 'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
			case 'notff': $out = 'سفارش پیدا نشد';break;
		}
		return $out;
	}

	protected function updateStatus ($status,$notified,$comments='',$id) {
		$modelOrder = VmModel::getModel ('orders');	
		$order['order_status'] = $status;
		$order['customer_notified'] = $notified;
		$order['comments'] = $comments;
		$modelOrder->updateStatusForOneOrder ($id, $order, TRUE);
	}

}

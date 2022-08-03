<?php

defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

class plgVmPaymentReepay extends vmPSPlugin {

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush ();
        $this->addVarsToPushCore($varsToPush,1);
        $this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
    }

    /**
     * @return array
     */
    function getTableSQLFields() {

        $SQLfields = array(
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => ' char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_status' => 'char(10)',
            'payment_currency' => 'char(3)',
            'reepay_invoice_handle' => 'varchar(255)',
            'reepay_payment_type' => 'varchar(13)',
            'reepay_response_json' => ' text DEFAULT NULL',
            'reepay_request_json' => ' text DEFAULT NULL'
        );

        return $SQLfields;
    }

    function plgVmConfirmedOrder($cart, $order) {

        $cartHash =  $cart->getCartHash();

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return null;
        }

        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR .'vmpayment/reepay/reepay/helpers/invoice_classes.php';
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR .'vmpayment/reepay/reepay/helpers/reepay_service.php';

        $privateKey = $method->test_mode == 0 ?
                      $method->private_key_live : $method->private_key_test;

        $reepayService = new ReepayService(trim($privateKey));

        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);

        $this->getPaymentCurrency($method);
        $currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');

        $testMode = boolval($method->test_mode);

        $address = ((isset($order['details']['BT'])) ? $order['details']['BT'] : $order['details']['ST']);

        $acceptUrl = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&ordernumber=' . $order['details']['BT']->order_number);
        $cancelUrl = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=vmplg&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);

        $payment_type = $method->reepay_instant_settle == 0 ? 'authorization': 'settle';

        $paymentMethods = new InvoiceOrderPaymentMethods($method->reepay_paytypes);

        $input = ((new InvoiceData())
            ->order((new InvoiceOrderData())
                ->handle( $order['details']['BT']->order_number )
                ->amount($totalInPaymentCurrency)
                ->currency($currency_code_3)
                ->customer((new InvoiceOrderCustomerData())
                    ->handle( $address->email )
                    ->test( $testMode )
                    ->phoneNumber(['customer']['phone_number'] ?? null)
                    ->email($address->email)
                    ->country(ShopFunctions::getCountryByID($address->virtuemart_country_id, 'country_2_code'))
                    ->postalCode($address->zip)
                    ->city($address->city)
                    ->address($address->address1)
                    ->address2($address->address2)
                    ->firstName ($address->firs_name)
                    ->lastName( $address->last_name)
                    ->vat(''))
                ->billingAddress((new InvoiceOrderBillingAddress())
                    ->email($address->email)
                    ->address($address->address1)
                    ->address2($address->address2)
                    ->postalCode($address->zip)
                    ->country(ShopFunctions::getCountryByID($address->virtuemart_country_id, 'country_2_code'))
                    ->city( $address->city )
                    ->lastName( $address->last_name )
                    ->firstName( $address->firs_name )
                    ->vat('')))
            ->settle($method->instant_settle == 1)
            ->acceptUrl($acceptUrl)
            ->cancelUrl($cancelUrl)
            ->paymentMethods($paymentMethods))->toArray();

            vmdebug('Request parameters:', $input);

            $result = $reepayService->createCheckoutSession($input);

            vmdebug('Response parameters:', $result);

        if ('success' == $result['status']) {

            $dbValues['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
            $dbValues['order_number'] = $order['details']['BT']->order_number;
            $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
            $dbValues['payment_name'] = $this->renderPluginName($method);
            $dbValues['payment_order_total'] = $totalInPaymentCurrency;
            $dbValues['payment_status'] = 'pending';
            $dbValues['payment_currency'] = $currency_code_3;
            $dbValues['reepay_invoice_handle'] = $result['body']['id'];
            $dbValues['reepay_payment_type'] = $payment_type;
            $dbValues['reepay_response_json'] = json_encode($result);
            $dbValues['reepay_request_json'] = json_encode($input);

            $this->storePSPluginInternalData($dbValues);

            $returnValue = 2;

            $html = '';

            vmJsApi::addJScript('vm.paymentFormAutoSubmit', '
            jQuery(document).ready(function($){
                    jQuery("body").addClass("vmLoading");
                    var msg="'.vmText::_('VMPAYMENT_REEPAY_REDIRECT_MESSAGE').'";
                    jQuery("body").append("<div class=\"vmLoadingDiv\"><div class=\"vmLoadingDivMsg\"></div></div>");
            window.setTimeout("jQuery(\'.vmLoadingDiv\').hide();",3000);
            window.setTimeout("window.location.replace(\'' . $result['body']['url'] . '\');", 400);
            })
          ');

        } else {

            $returnValue = 0;

            $html = vmText::_ ('VMPAYMENT_REEPAY_TECHNICAL_ERROR') .
                " <br /> - " .  'Some issue with processing your order payment'  .  "<br />";
        }

        return $this->processConfirmedOrderPaymentResponse($returnValue, $cart, $order, $html,'');

    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
        return $this->declarePluginParams('payment', $data);
    }
    function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

        return $this->setOnTablePluginParams ($name, $id, $table);
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    // compatible with Joomla 4
    public function plgVmOnSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

        return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    function plgVmOnPaymentResponseReceived(&$html) {
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber(vRequest::getString('invoice'));
        $payment_data = $this->getDataByOrderId($virtuemart_order_id);

        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        if (!$virtuemart_paymentmethod_id) {
            return;
        }

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }

        $modelOrder = VmModel::getModel('orders');

        $order = $modelOrder->getOrder( $virtuemart_order_id );

        $this->getPaymentCurrency($method);
        $currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');

        // check if order has not already been updated with webhook
        if ('pending' == $payment_data->payment_status) {

            if (!class_exists('VirtueMartModelOrders')) {
                require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
            }

            // the payment itself should send the parameter needed.
            $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
            if (!$virtuemart_paymentmethod_id) {
                return;
            }
            if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
                return NULL; // Another method was selected, do nothing
            }
            if (!$this->selectedThisElement($method->payment_element)) {
                return FALSE;
            }

            if (empty(vRequest::getString('invoice'))) {
                return FALSE;
            }

            require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_service.php';

            $privateKey = (intval($method->test_mode) == 0) ?
            $method->private_key_live : $method->private_key_test;

            $reepayService = new ReepayService(trim($privateKey));

            // check if invoice exists in Reepay
            $invoice = $reepayService->getInvoice(vRequest::getString('invoice'));

            if ($invoice['status'] !== 'success' || !in_array($invoice['body']['state'], ['authorized', 'settled'])) {
                return FALSE;
            }

            $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber(vRequest::getString('invoice'));

            $orderHistory['customer_notified'] = 1;

            if ($method->instant_settle == 1) {
                $orderHistory['order_status'] = $method->status_settled;
                $orderHistory['comments'] = JText::_('VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_SETTLED');

            } else {
                $orderHistory['order_status'] = $method->status_authorized;
                $orderHistory['comments'] = JText::_('VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_AUTHORIZED');
            }

            $modelOrder = VmModel::getModel('orders');

            $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $orderHistory, false);

            $response_fields['virtuemart_order_id'] = $virtuemart_order_id;
            $response_fields['payment_status'] = $invoice['body']['state'];

            $payment_data = $this->getDataByOrderId($virtuemart_order_id);

            $response_fields['id'] = $payment_data->id;

            $this->storePSPluginInternalData($response_fields, 'id', true);

            $cart = VirtueMartCart::getCart();

            $cart->emptyCart();

            $orderlink='';
            $tracking = VmConfig::get('ordertracking','guests');
            if($tracking !='none' and !($tracking =='registered' and empty($order['details']['BT']->virtuemart_user_id) )) {

                $orderlink = 'index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order['details']['BT']->order_number;
                if ($tracking == 'guestlink' or ($tracking == 'guests' and empty($order['details']['BT']->virtuemart_user_id))) {
                    $orderlink .= '&order_pass=' . $order['details']['BT']->order_pass;
                }
            }

        }

        $html = $this->renderByLayout('response', array(
            'order_number' =>$order['details']['BT']->order_number,
            'order_pass' =>$order['details']['BT']->order_pass,
            'payment_name' => $method->payment_name,
            'displayTotalInPaymentCurrency' => $currency_code_3,
            'orderlink' => $orderlink,
            'method' => $method
        ));

        return true;
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

        $this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * @return bool|null
     */
    function plgVmOnUserPaymentCancel() {
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        $order_number = vRequest::getUword('on');

        if (!$order_number) {
            return FALSE;
        }

         if (!$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number)) {
            return NULL;
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return NULL;
        }

        $session = JFactory::getSession();
        $return_context = $session->getId();
        $field = $this->_name . '_custom';
        if (strcmp($paymentTable->$field, $return_context) === 0) {
            $this->handlePaymentUserCancel($virtuemart_order_id);
        }
        return TRUE;
    }

    /**
     * Display stored payment data for an order
     *
     */
    function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $virtuemart_payment_id) {

        if (!$this->selectedThisByMethodId ($virtuemart_payment_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
            return NULL;
        }
        vmLanguage::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE ();
        $html .= $this->getHtmlRowBE ('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE ('VMPAYMENT_REEPAY_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";
        return $html;
    }

    public function plgVmOnUpdateOrderPayment(&$order, $old_order_status) {

          require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR .'vmpayment/reepay/reepay/helpers/reepay_service.php';

      if (! ($method = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
      }

      if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
      }

      $privateKey = $method->test_mode == 0 ? $method->private_key_live : $method->private_key_test;

      $reepayService = new ReepayService($privateKey);
      $invoice = $reepayService->getInvoice($order->order_number);

      vmdebug('plgVmOnUpdateOrderPayment invoice', $invoice);

      // we have this invoice in Reepay system
      if('success' == $invoice['status']) {

          $modelOrder = VmModel::getModel('orders');

          if ($order->order_status == $method->status_refunded && 'settled' == $invoice['body']['state']) {
              vmdebug('attempt to refund on Reepay gateway', ['invoice' => $order->order_number, 'amount' => $order->paid]);
              $result = $reepayService->refund($order->order_number, $order->paid);
              if ($result['status'] == 'success') {
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_REEPAY_ORDER_REFUND_FLASH_MESSAGE_SUCCESS'));
                   $orderHistory['comments'] = vmText::_('VMPAYMENT_REEPAY_ORDER_REFUND_FLASH_MESSAGE_SUCCESS');
                   $orderHistory['order_status'] = $method->status_refunded;
                   $modelOrder->updateStatusForOneOrder($order->virtuemart_order_id, $orderHistory, false);
                  vmdebug('refund was successful on Reepay gateway');
              } else {
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMETN_REEPAY_ORDER_REFUND_FLASH_MESSAGE_FAIL') . $result['error'], 'warning');
                  vmdebug('refund failed on gateway ', $result['error']);
              }
          }

          if ($order->order_status == $method->status_settled && 'authorized' == $invoice['body']['state']) {
              vmdebug('attempt to settle on Reepay gateway', ['invoice' => $order->order_number, 'amount' => $order->order_total]);
              $result = $reepayService->settle($order->order_number, $order->order_total);
              if ($result['status'] == 'success') {
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_REEPAY_ORDER_SETTLE_FLASH_MESSAGE_SUCCESS'));

                  $orderHistory['comments'] = vmText::_('VMPAYMENT_REEPAY_ORDER_SETTLE_FLASH_MESSAGE_SUCCESS');
                  $orderHistory['order_status'] = $method->status_settled;
                  $modelOrder->updateStatusForOneOrder($order->virtuemart_order_id, $orderHistory, false);

                  vmdebug('settle was successful on Reepay gateway');
              } else {
                  vmdebug('settle failed on Reepay gateway ', $result['error']);
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMETN_REEPAY_ORDER_SETTLE_FLASH_MESSAGE_FAIL') . $result['error'], 'warning');
              }
          }

          if ($order->order_status == $method->status_cancelled && 'authorized' == $invoice['body']['state']) {
              vmdebug('attempt to void on Reepay gateway', ['invoice' => $order->order_number]);
              $result = $reepayService->void($order->order_number);
              if ($result['status'] == 'success') {
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_REEPAY_ORDER_CANCEL_FLASH_MESSAGE_SUCCESS'));
                  $orderHistory['comments'] = vmText::_('VMPAYMENT_REEPAY_ORDER_CANCEL_FLASH_MESSAGE_SUCCESS');
                  $orderHistory['order_status'] = $method->status_cancelled;
                  $modelOrder->updateStatusForOneOrder($order->virtuemart_order_id, $orderHistory, false);
                  vmdebug('cancelling was successful on gateway');
              } else {
                  vmdebug('cancelling failed on gateway', $result['error']);
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMETN_REEPAY_ORDER_CANCEL_FLASH_MESSAGE_FAIL') . $result['error'], 'warning');
              }
          }
      }

    }

}


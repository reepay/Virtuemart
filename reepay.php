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
        //$this->addVarsToPushCore($varsToPush,1);
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

        $this->getPaymentCurrency($method);

        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);

        $currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');

        $testMode = boolval($method->test_mode);

        $address = ((isset($order['details']['BT'])) ? $order['details']['BT'] : $order['details']['ST']);

        // The buyer types the email, so it must not decide which Frisbii customer the order belongs to.
        // An order created in the admin has the admin as user, so it is handled like a guest order.
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_customer_handle.php';
        $earlierAttempt = $this->getDataByOrderNumber($order['details']['BT']->order_number);
        $customerHandle = ReepayCustomerHandle::forOrder(
            ReepayCustomerHandle::shopKey(JFactory::getConfig()->get('secret')),
            JFactory::getApplication()->isClient('site') ? $order['details']['BT']->virtuemart_user_id : 0,
            $order['details']['BT']->order_number,
            is_object($earlierAttempt) ? $earlierAttempt->reepay_request_json : null
        );

        $acceptUrl = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&ordernumber=' . $order['details']['BT']->order_number);
        $cancelUrl = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=vmplg&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);

        $payment_type = $method->instant_settle == 0 ? 'authorization': 'settle';

        $selectedPaytypes = is_array($method->reepay_paytypes)
            ? $method->reepay_paytypes
            : [$method->reepay_paytypes];

        if (array_intersect(['card', 'mc', 'visa'], $selectedPaytypes) && !in_array('click_to_pay', $selectedPaytypes)) {
            $selectedPaytypes[] = 'click_to_pay';
        }

        $paymentMethods = new InvoiceOrderPaymentMethods($selectedPaytypes);

        $isKlarna = in_array('klarna', $selectedPaytypes, true);

        $orderLines = [];

        if ($isKlarna) {
            require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR
                . 'vmpayment/reepay/reepay/helpers/reepay_order_lines.php';

            // Converts a shop-currency amount to the payment currency
            // (same conversion as the order total above).
            $paymentCurrencyId = $method->payment_currency;
            $toPaymentCurrency = function ($amount) use ($paymentCurrency, $paymentCurrencyId) {
                return $paymentCurrency->convertCurrencyTo($paymentCurrencyId, $amount, false);
            };

            // Lines in the payment currency with unit prices. Returns [] if they can't match
            // the order total exactly; then a plain amount is sent instead.
            $orderLines = ReepayOrderLines::build(
                $order['items'],
                $order['details']['BT'],
                $toPaymentCurrency,
                $totalInPaymentCurrency
            );
        }

        $orderData = (new InvoiceOrderData())
            ->handle($order['details']['BT']->order_number)
            ->amount($totalInPaymentCurrency)
            ->currency($currency_code_3)
            ->customer((new InvoiceOrderCustomerData())
                ->handle($customerHandle)
                ->test($testMode)
                ->phoneNumber(['customer']['phone_number'] ?? null)
                ->email($address->email)
                ->country(ShopFunctions::getCountryByID($address->virtuemart_country_id, 'country_2_code'))
                ->postalCode($address->zip)
                ->city($address->city)
                ->address($address->address_1)
                ->address2($address->address_2)
                ->firstName($address->first_name)
                ->lastName($address->last_name)
                ->vat(''))
            ->billingAddress((new InvoiceOrderBillingAddress())
                ->email($address->email)
                ->address($address->address_1)
                ->address2($address->address_2)
                ->postalCode($address->zip)
                ->country(ShopFunctions::getCountryByID($address->virtuemart_country_id, 'country_2_code'))
                ->city($address->city)
                ->lastName($address->last_name)
                ->firstName($address->first_name)
                ->vat(''));

        if ($isKlarna && !empty($orderLines)) {
            $orderData->orderLines($orderLines);
        }

        $input = ((new InvoiceData())
            ->order($orderData)
            ->settle($method->instant_settle == 1)
            ->acceptUrl($acceptUrl)
            ->cancelUrl($cancelUrl)
            ->paymentMethods($paymentMethods))->toArray();

            vmdebug('Request parameters:', $input);

            // Non-sensitive summary to administrator/logs/reepay_checkout.php (no customer data)
            require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR
                . 'vmpayment/reepay/reepay/helpers/reepay_checkout_log.php';
            $this->logCheckout(ReepayCheckoutLog::request($input));

            $result = $reepayService->createCheckoutSession($input);

            vmdebug('Response parameters:', $result);

            $this->logCheckout(
                ReepayCheckoutLog::response($input['order']['handle'] ?? null, $result),
                ('success' == ($result['status'] ?? '')) ? 'info' : 'warning'
            );

        if ('success' == $result['status']) {

            $dbValues['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
            $dbValues['order_number'] = $order['details']['BT']->order_number;
            $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
            $dbValues['payment_name'] = $this->renderPluginName($method);
            $dbValues['payment_order_total'] = $totalInPaymentCurrency;
            $dbValues['payment_status'] = 'pending';
            $dbValues['payment_currency'] = $currency_code_3;
            $dbValues['reepay_invoice_handle'] = $result['body']['id'];
            $dbValues['reepay_payment_type'] = $payment_type;
            $dbValues['reepay_response_json'] = json_encode($result);
            $dbValues['reepay_request_json'] = json_encode($input);

            $this->storePSPluginInternalData($dbValues);

            // Remember which session placed the order: only that session may see it on the accept URL
            require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_order_access.php';
            $session = JFactory::getSession();
            $session->set(ReepayOrderAccess::SESSION_KEY, ReepayOrderAccess::remember(
                $session->get(ReepayOrderAccess::SESSION_KEY, []),
                $order['details']['BT']->order_number
            ));

            $returnValue = 2;

            $html = '';

            $checkout_type = current($method->checkout_type);

            if( 'overlay' == $checkout_type ) {
                $reepayJsLib = 'https://checkout.reepay.com/checkout.js';
                JHtml::_('script', $reepayJsLib, array('version' => 'auto', 'relative' => true));

                $invoiceHandle  = $result['body']['id'];
                $concel_url = $input['cancel_url'];;
                $accept_url = $input['accept_url'];

                $pm = $method->virtuemart_paymentmethod_id;
                $invoice =  $order['details']['BT']->order_number;

                $html .= "<script> 
                            var rp = new Reepay.ModalCheckout('$invoiceHandle');
                            rp.addEventHandler(Reepay.Event.Accept, function(data) {
                                 window.location.href = '$accept_url&invoice=$invoice&pm=$pm';
                            });
                
                            rp.addEventHandler(Reepay.Event.Cancel, function(data) {
                                 window.location.href = '$concel_url';
                            });
                            
                            
                          </script>";

            } else {
                    vmJsApi::addJScript('vm.paymentFormAutoSubmit', '
                    jQuery(document).ready(function($){
                            jQuery("body").addClass("vmLoading");
                            var msg="'.vmText::_('VMPAYMENT_REEPAY_REDIRECT_MESSAGE').'";
                            jQuery("body").append("<div class=\"vmLoadingDiv\"><div class=\"vmLoadingDivMsg\"></div></div>");
                    window.setTimeout("jQuery(\'.vmLoadingDiv\').hide();",3000);
                    window.setTimeout("window.location.replace(\'' . $result['body']['url'] . '\');", 400);
                    })
                  ');
            }

        } else {

            $returnValue = 0;

            vmdebug('Reepay API error', ['status' => $result['status'], 'error' => $result['error'] ?? '', 'body' => $result['body'] ?? '']);

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
        JFactory::getDocument()->addStyleDeclaration('.vmCartPaymentLogo img { width: 100px; height: auto; }');
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    function plgVmOnPaymentResponseReceived(&$html) {
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber(vRequest::getString('invoice'));
        $payment_data = $this->getDataByOrderId($virtuemart_order_id);

        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        if (!$virtuemart_paymentmethod_id) {
            return;
        }

        // pm only routes the request to this plugin; the order's own method is used below
        if (!($requestMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($requestMethod->payment_element)) {
            return NULL;
        }

        // Default for everyone who may not see the order, and for unknown orders
        $html = '<div class="post_payment_neutral">' . vmText::_('VMPAYMENT_REEPAY_RESPONSE_NEUTRAL') . '</div>';

        // Key, test mode and statuses come from the payment method stored on the order
        $method = $this->getOrderMethod($virtuemart_paymentmethod_id, $payment_data,
            vRequest::getString('invoice'), 'customer return');
        if (!$method) {
            return NULL;
        }

        $modelOrder = VmModel::getModel('orders');

        $order = $modelOrder->getOrder( $virtuemart_order_id );

        // The order number in the URL can be guessed, so the order is only shown to the session that
        // placed it or to its logged-in owner. The payment is registered either way.
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_order_access.php';
        $mayShowOrder = ReepayOrderAccess::mayShowOrder(
            JFactory::getSession()->get(ReepayOrderAccess::SESSION_KEY, []),
            $order['details']['BT']->order_number,
            (int) JFactory::getUser()->get('id'),
            $order['details']['BT']->virtuemart_user_id
        );
        if (!$mayShowOrder) {
            $this->logWebhook('Note (customer return): order not shown, the session did not place invoice=' . $order['details']['BT']->order_number, 'info');
        }

        $this->getPaymentCurrency($method);
        $currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');

        $orderlink = '';

        // check if order has not already been updated with webhook
        if ('pending' == $payment_data->payment_status) {
            if (empty(vRequest::getString('invoice'))) {
                return FALSE;
            }

            require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_service.php';
            require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_invoice_matcher.php';
            require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_invoice_status.php';

            $privateKey = (intval($method->test_mode) == 0) ?
            $method->private_key_live : $method->private_key_test;

            $reepayService = new ReepayService(trim($privateKey));

            // check if invoice exists in Frisbii Payments
            $invoice = $reepayService->getInvoice(vRequest::getString('invoice'));

            if ($invoice['status'] !== 'success' || !in_array($invoice['body']['state'], ['authorized', 'settled'])) {
                return FALSE;
            }

            $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber(vRequest::getString('invoice'));

            // The invoice must be for this order's amount and currency, not just in a paid state
            $payment_data = $this->getDataByOrderId($virtuemart_order_id);
            $mismatch = ReepayInvoiceMatcher::mismatchReason(
                $invoice['body'],
                is_object($payment_data) ? $payment_data->payment_order_total : null,
                is_object($payment_data) ? $payment_data->payment_currency : null
            );

            if ($mismatch !== null) {
                $this->logWebhook('Stopped (customer return): ' . $mismatch
                    . ' for invoice=' . vRequest::getString('invoice')
                    . ' expected=' . (is_object($payment_data) ? ReepayInvoiceMatcher::toMinorUnits($payment_data->payment_order_total) . ' ' . $payment_data->payment_currency : 'n/a')
                    . ' received=' . ($invoice['body']['amount'] ?? 'n/a') . ' ' . ($invoice['body']['currency'] ?? 'n/a'), 'warning');
                return FALSE;
            }

            // The status follows what Frisbii reports for the invoice, not the instant-settle setting
            $status = ReepayInvoiceStatus::forCustomerReturn($invoice['body'], $method);
            if ($status === null) {
                return FALSE;
            }

            $orderHistory['customer_notified'] = 1;
            $orderHistory['order_status'] = $status['order_status'];
            $orderHistory['comments'] = JText::_($status['comment_key']);

            $modelOrder = VmModel::getModel('orders');

            $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $orderHistory, false);

            $response_fields['virtuemart_order_id'] = $virtuemart_order_id;
            $response_fields['payment_status'] = $status['payment_status'];

            $payment_data = $this->getDataByOrderId($virtuemart_order_id);

            $response_fields['id'] = $payment_data->id;

            $this->storePSPluginInternalData($response_fields, 'id', true);

            if ($mayShowOrder) {
                $cart = VirtueMartCart::getCart();
                $cart->emptyCart();

                $orderlink = ReepayOrderAccess::orderLink(
                    VmConfig::get('ordertracking', 'guests'),
                    $order['details']['BT']->order_number,
                    $order['details']['BT']->order_pass,
                    $order['details']['BT']->virtuemart_user_id
                );
            }

        }

        if (!$mayShowOrder) {
            return true;
        }

        $html = $this->renderByLayout('response', array(
            'order_number' =>$order['details']['BT']->order_number,
            'payment_name' => $method->payment_name,
            'displayTotalInPaymentCurrency' => $currency_code_3,
            'orderlink' => $orderlink,
            'method' => $method
        ));

        return true;
    }

    function plgVmOnPaymentNotification() {
        $input = JFactory::getApplication()->input->json;
        $payload = json_decode($input->getRaw(), true);
        $remoteAddr = JFactory::getApplication()->input->server->getString('REMOTE_ADDR', 'unknown');

        if (!is_array($payload)) {
            $this->logWebhook('Rejected: request body is not valid JSON. remote=' . $remoteAddr, 'warning');
            return NULL;
        }

        $invoiceHandle = isset($payload['invoice']) ? $payload['invoice'] : null;
        $eventType = isset($payload['event_type']) ? $payload['event_type'] : 'unknown';
        $eventId = isset($payload['id']) ? $payload['id'] : 'unknown';

        if( empty( $invoiceHandle ) ) {
            $this->logWebhook('Rejected: missing invoice field. event_type=' . $eventType . ', id=' . $eventId . ', remote=' . $remoteAddr, 'warning');
            return NULL;
        }

        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        if (!$virtuemart_paymentmethod_id) {
            return;
        }

        // pm only routes the request to this plugin; the order's own method is used below
        if (!($requestMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($requestMethod->payment_element)) {
            return NULL;
        }

        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_service.php';
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_webhook_verifier.php';
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_webhook_secret_store.php';
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_webhook_dispatcher.php';
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_invoice_matcher.php';
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_invoice_status.php';

        // Read-only lookup of the order the webhook is about, so its own payment method
        // (key, test mode, webhook secret, statuses) is used. Nothing is changed before
        // the signature is verified.
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($invoiceHandle);
        $payment_data = $virtuemart_order_id ? $this->getDataByOrderId($virtuemart_order_id) : null;

        $method = $this->getOrderMethod($virtuemart_paymentmethod_id, $payment_data, $invoiceHandle,
            'webhook event_type=' . $eventType . ', id=' . $eventId . ', remote=' . $remoteAddr);
        if (!$method) {
            return NULL;
        }

        $testMode = (intval($method->test_mode) != 0);
        $privateKey = (intval($method->test_mode) == 0) ?
        $method->private_key_live : $method->private_key_test;

        $reepayService = new ReepayService(trim($privateKey));

        $secretStore = new ReepayWebhookMethodSecretStore($method, $testMode ? 'test' : 'live');
        $verifier = new ReepayWebhookVerifier($secretStore, function () use ($reepayService) {
            return $reepayService->getWebhookSecret();
        });

        $this->logWebhook('Received: event_type=' . $eventType . ', id=' . $eventId . ', invoice=' . $invoiceHandle . ', pm=' . $virtuemart_paymentmethod_id . ', method=' . $method->virtuemart_paymentmethod_id . ', mode=' . ($testMode ? 'test' : 'live') . ', remote=' . $remoteAddr, 'info');

        if (!$verifier->verify($payload)) {
            // Unverifiable notification: reject before any status change,
            // and don't disclose why (missing fields vs. bad signature vs.
            // secret unavailable all look the same from the outside) to the caller -
            // but it's safe and useful to record the non-sensitive context here.
            $this->logWebhook('Rejected: signature verification failed. event_type=' . $eventType . ', id=' . $eventId . ', invoice=' . $invoiceHandle . ', pm=' . $virtuemart_paymentmethod_id
                . ($verifier->refreshSkipped() ? ' (secret refresh skipped: last refresh less than ' . ReepayWebhookVerifier::REFRESH_MIN_INTERVAL . 's ago)' : ''), 'warning');
            return NULL;
        }

        $this->logWebhook('Verified: signature OK. event_type=' . $eventType . ', id=' . $eventId . ', invoice=' . $invoiceHandle, 'info');

        $dispatchTable = ReepayWebhookDispatcher::buildTable($method);

        // Fast-path: skip API call if event type is unknown
        if (!isset($dispatchTable[$eventType])) {
            $this->logWebhook('Skipped: unrecognised event_type=' . $eventType . ', invoice=' . $invoiceHandle, 'info');
            return NULL;
        }

        // check if invoice exists in Frisbii Payments
        $invoice = $reepayService->getInvoice($invoiceHandle);

        if (empty($invoice)) {
            $this->logWebhook('Stopped: getInvoice() returned no response for invoice=' . $invoiceHandle, 'warning');
            return NULL;
        }

        if ($invoice['status'] !== 'success') {
            $this->logWebhook('Stopped: getInvoice() returned non-success status for invoice=' . $invoiceHandle . ' (status=' . $invoice['status'] . ')', 'warning');
            return NULL;
        }

        $invoiceState = $invoice['body']['state'] ?? 'n/a';

        // Reload: the row may have changed while the signature and invoice were checked
        $payment_data = $this->getDataByOrderId($virtuemart_order_id);
        $currentPaymentStatus = is_object($payment_data) ? $payment_data->payment_status : null;

        // The invoice must be for this order's amount and currency, not just in the expected state
        $mismatch = ReepayInvoiceMatcher::mismatchReason(
            $invoice['body'] ?? [],
            is_object($payment_data) ? $payment_data->payment_order_total : null,
            is_object($payment_data) ? $payment_data->payment_currency : null
        );

        if ($mismatch !== null) {
            $this->logWebhook('Stopped: amount/currency mismatch (' . $mismatch . ') for event_type=' . $eventType
                . ', invoice=' . $invoiceHandle
                . ', expected=' . (is_object($payment_data) ? ReepayInvoiceMatcher::toMinorUnits($payment_data->payment_order_total) . ' ' . $payment_data->payment_currency : 'n/a')
                . ', received=' . ($invoice['body']['amount'] ?? 'n/a') . ' ' . ($invoice['body']['currency'] ?? 'n/a'), 'warning');
            return FALSE;
        }

        $config = ReepayWebhookDispatcher::resolve($eventType, $invoiceState, $currentPaymentStatus, $dispatchTable, $skipReason);

        if ($config === null) {
            if ($skipReason === 'state_mismatch') {
                $this->logWebhook('Skipped: event_type=' . $eventType . ' but invoice state=' . $invoiceState . ' (expected ' . implode('/', $dispatchTable[$eventType]['expectedInvoiceStates']) . '), invoice=' . $invoiceHandle, 'info');
                return FALSE;
            }
            $this->logWebhook('Skipped: event_type=' . $eventType . ' but payment_status=' . ($currentPaymentStatus ?? 'n/a') . ' (no transition needed), invoice=' . $invoiceHandle, 'info');
            return NULL;
        }

        // Follow the real invoice: an already settled invoice is settled, a partial refund is not a refund
        $adjusted = ReepayInvoiceStatus::forWebhook($config, $eventType, $invoice['body'], $method);
        $config = $adjusted['config'];
        $amountMinor = (int) ($invoice['body']['amount'] ?? 0);
        $refundedMinor = (int) ($invoice['body']['refunded_amount'] ?? 0);
        $currency = (string) ($invoice['body']['currency'] ?? '');

        if ($adjusted['partialRefund']) {
            $this->logWebhook('Partial refund: refunded_amount=' . $refundedMinor . ' of amount=' . $amountMinor . ' ' . $currency
                . ', order_id=' . $virtuemart_order_id . ' keeps payment_status=' . $payment_data->payment_status . ', invoice=' . $invoiceHandle, 'info');
        } else {
            $this->logWebhook('Updating order_id=' . $virtuemart_order_id . ' from payment_status=' . $payment_data->payment_status . ' to ' . $config['newPaymentStatus'] . ' via event_type=' . $eventType
                . ' (invoice state=' . $invoiceState . ', refunded_amount=' . $refundedMinor . ' of ' . $amountMinor . '), invoice=' . $invoiceHandle, 'info');
        }

        $orderHistory['customer_notified'] = 1;
        $orderHistory['order_status']      = $config['targetOrderStatus'];
        $orderHistory['comments']          = 'Webhook from Frisbii Payments gateway: ' . ($adjusted['partialRefund']
            ? JText::sprintf($config['orderNoteKey'], number_format($refundedMinor / 100, 2, '.', ''), number_format($amountMinor / 100, 2, '.', ''), $currency)
            : JText::_($config['orderNoteKey']));

        $modelOrder = VmModel::getModel('orders');

        if ($adjusted['partialRefund']) {
            $currentOrder = $modelOrder->getOrder($virtuemart_order_id);
            $orderHistory['order_status'] = $currentOrder['details']['BT']->order_status;
        }

        $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $orderHistory, false);

        $response_fields['virtuemart_order_id'] = $virtuemart_order_id;
        $response_fields['payment_status']      = $config['newPaymentStatus'];

        $payment_data = $this->getDataByOrderId($virtuemart_order_id);
        $response_fields['id'] = $payment_data->id;

        $this->storePSPluginInternalData($response_fields, 'id', true);

        if ($config['clearCart']) {
            $cart = VirtueMartCart::getCart();
            $cart->emptyCart();
        }

    }

    /**
     * Returns the Frisbii payment method stored on the order, or null (logged) when the order
     * has no usable method. The request's pm only routes the request to this plugin and is
     * never used for the API key, test mode, webhook secret or order statuses; when it differs
     * from the order's method a warning is logged and the order's method is used.
     *
     * @param int         $requestPm    pm from the request URL
     * @param object|null $payment_data row from #__virtuemart_payment_plg_reepay for the order
     * @param string|null $orderNumber  invoice handle / order number, for the log
     * @param string      $context      'customer return' or webhook details, for the log
     * @return object|null
     */
    private function getOrderMethod($requestPm, $payment_data, $orderNumber, $context) {
        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR . 'vmpayment/reepay/reepay/helpers/reepay_method_resolver.php';

        $resolved = ReepayMethodResolver::resolve(
            $requestPm,
            is_object($payment_data) ? $payment_data->virtuemart_paymentmethod_id : null
        );

        if ($resolved['id'] === null) {
            $this->logWebhook('Rejected (' . $context . '): no Frisbii payment stored for invoice=' . $orderNumber . ', pm=' . (int) $requestPm, 'warning');
            return null;
        }

        $method = $this->getVmPluginMethod($resolved['id']);
        if (!$method || !$this->selectedThisElement($method->payment_element)) {
            $this->logWebhook('Rejected (' . $context . '): payment method ' . $resolved['id'] . ' of invoice=' . $orderNumber . ' is not an available Frisbii method, pm=' . (int) $requestPm, 'warning');
            return null;
        }

        if ($resolved['pmMismatch']) {
            $this->logWebhook('Note (' . $context . '): pm=' . (int) $requestPm . ' differs from the payment method ' . $resolved['id'] . ' stored on invoice=' . $orderNumber . '; using the stored method', 'warning');
        }

        return $method;
    }

    /**
     * Writes a non-sensitive trace line for the webhook flow to
     * administrator/logs/reepay_webhook.php, to make it possible to follow whether
     * a notification was received, verified, and acted on without needing to
     * reproduce the request. Never pass the private key, webhook secret, raw
     * signature, or full request payload here.
     *
     * @param string $message
     * @param string $level 'info'|'warning'|'error'
     */
    private function logWebhook($message, $level = 'info') {
        $this->writeLog('reepay_webhook', 'Reepay webhook: ' . $message, $level);
    }

    /**
     * Writes a non-sensitive checkout-session trace line (see ReepayCheckoutLog) to
     * administrator/logs/reepay_checkout.php. Never pass customer data, URLs, keys
     * or the raw request/response here.
     *
     * @param string $message
     * @param string $level 'info'|'warning'|'error'
     */
    private function logCheckout($message, $level = 'info') {
        $this->writeLog('reepay_checkout', 'Reepay ' . $message, $level);
    }

    /**
     * Joomla text-file log in the administrator logs folder. Joomla starts these .php
     * files with a die() header, so they can't be downloaded over the web.
     *
     * @param string $category Also used as the file name (<category>.php)
     * @param string $message
     * @param string $level 'info'|'warning'|'error'
     */
    private function writeLog($category, $message, $level = 'info') {
        static $registered = [];

        if (empty($registered[$category])) {
            \Joomla\CMS\Log\Log::addLogger(['text_file' => $category . '.php'], \Joomla\CMS\Log\Log::ALL, [$category]);
            $registered[$category] = true;
        }

        $priority = \Joomla\CMS\Log\Log::INFO;
        if ($level === 'warning') {
            $priority = \Joomla\CMS\Log\Log::WARNING;
        } elseif ($level === 'error') {
            $priority = \Joomla\CMS\Log\Log::ERROR;
        }

        \Joomla\CMS\Log\Log::add($message, $priority, $category);
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
          require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR .'vmpayment/reepay/reepay/helpers/reepay_invoice_status.php';

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

      // we have this invoice in Frisbii Payments system
      if('success' == $invoice['status']) {

          $modelOrder = VmModel::getModel('orders');

          if ($order->order_status == $method->status_refunded && 'settled' == $invoice['body']['state']) {
              // Refund only what is left on the invoice (in the invoice currency), not the order total
              $refundableMinor = ReepayInvoiceStatus::refundableMinor($invoice['body']);
              if ($refundableMinor <= 0) {
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_REEPAY_ORDER_REFUND_NOTHING_LEFT'), 'notice');
                  vmdebug('nothing left to refund on Frisbii Payments gateway', ['invoice' => $order->order_number]);
                  return NULL;
              }
              vmdebug('attempt to refund on Frisbii Payments gateway', ['invoice' => $order->order_number, 'amount_minor' => $refundableMinor]);
              $result = $reepayService->refund($order->order_number, $refundableMinor / 100);
              if ($result['status'] == 'success') {
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_REEPAY_ORDER_REFUND_FLASH_MESSAGE_SUCCESS'));
                   $orderHistory['comments'] = vmText::_('VMPAYMENT_REEPAY_ORDER_REFUND_FLASH_MESSAGE_SUCCESS');
                   $orderHistory['order_status'] = $method->status_refunded;
                   $modelOrder->updateStatusForOneOrder($order->virtuemart_order_id, $orderHistory, false);
                  vmdebug('refund was successful on Frisbii Payments gateway');
              } else {
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMETN_REEPAY_ORDER_REFUND_FLASH_MESSAGE_FAIL') . $result['error'], 'warning');
                  vmdebug('refund failed on gateway ', $result['error']);
              }
          }

          if ($order->order_status == $method->status_settled && 'authorized' == $invoice['body']['state']) {
              vmdebug('attempt to settle on Frisbii Payments gateway', ['invoice' => $order->order_number, 'amount' => $order->order_total]);
              $result = $reepayService->settle($order->order_number, $order->order_total);
              if (ReepayInvoiceStatus::settleSucceeded($result)) {
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_REEPAY_ORDER_SETTLE_FLASH_MESSAGE_SUCCESS'));

                  $orderHistory['comments'] = vmText::_('VMPAYMENT_REEPAY_ORDER_SETTLE_FLASH_MESSAGE_SUCCESS');
                  $orderHistory['order_status'] = $method->status_settled;
                  $modelOrder->updateStatusForOneOrder($order->virtuemart_order_id, $orderHistory, false);

                  vmdebug('settle was successful on Frisbii Payments gateway');
              } else {
                  // HTTP success with another charge state (e.g. an expired authorization -> "failed") is a failure too
                  $reason = $result['error'] ?? (' (state: ' . ($result['body']['state'] ?? 'unknown') . ')');
                  vmdebug('settle failed on Frisbii Payments gateway ', $reason);
                  JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMETN_REEPAY_ORDER_SETTLE_FLASH_MESSAGE_FAIL') . $reason, 'warning');
              }
          }

          if ($order->order_status == $method->status_cancelled && 'authorized' == $invoice['body']['state']) {
              vmdebug('attempt to void on Frisbii Payments gateway', ['invoice' => $order->order_number]);
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

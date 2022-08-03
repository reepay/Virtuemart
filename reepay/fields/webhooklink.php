<?php

defined('JPATH_BASE') or die();

jimport('joomla.form.formfield');

if (!class_exists('vmPSPlugin')) {
    require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

class JFormFieldWebhookLink extends JFormField
{
    var $type = 'webhooklink';

    function getInput()
    {

        require_once VMPATH_PLUGINS . DIRECTORY_SEPARATOR .'vmpayment/reepay/reepay/helpers/reepay_service.php';

        $cid = vRequest::getvar('cid', NULL, 'array');

        if (is_Array($cid)) {
            $virtuemart_paymentmethod_id = $cid[0];
        } else {
            $virtuemart_paymentmethod_id = $cid;
        }

        $query = "SELECT payment_params FROM `#__virtuemart_paymentmethods` WHERE  virtuemart_paymentmethod_id = '" . $virtuemart_paymentmethod_id . "'";
        $db = JFactory::getDBO();
        $db->setQuery($query);
        $params = $db->loadResult();

        $webhook_url = JURI::root() . 'index.php?option=com_virtuemart&format=raw&view=pluginresponse&task=pluginnotification&tmpl=component&pm=' . $virtuemart_paymentmethod_id;

        $payment_params = explode("|", $params);
        foreach ($payment_params as $payment_param) {
            if (empty($payment_param)) {
                continue;
            }
            $param = explode('=', $payment_param);
            $payment_params[$param[0]] = substr($param[1], 1, -1);
        }

        $reepayService = new ReepayService(trim($payment_params['private_key_test']));

        $result = $reepayService->getWebhooks();

        $output = '';

        $webhook_is_set = false;
        if('success' ==  $result['status']) {

            foreach ($result['body']['urls'] as $url) {

                if($webhook_url == trim($url)) {
                    $webhook_is_set = true;
                    break;
                }

            }

            if($webhook_is_set) {
                $output .= "<div style=\"color: #008000\" > Webhook is set for test account </div>";
            } else {
                $output .= "<div style=\"color: #FF0000\" > Webhook for test account is not set. Please use this url to set it up:</div> {$webhook_url}";
            }

        } else {
            $output .= "<div style=\"color: #FF0000\" > Webhook for test account is not set. Please use this url to set it up:</div> {$webhook_url}";
        }

        $reepayService = new ReepayService(trim($payment_params['private_key_live']));

        $result = $reepayService->getWebhooks();

        $webhook_is_set = false;

        if('success' == $result['status']) {

            foreach ($result['body']['urls'] as $url) {

                if($webhook_url == trim($url)) {
                    $webhook_is_set = true;
                    break;
                }

            }

            if($webhook_is_set) {
                $output .= "<div style=\"color: #008000\" > Webhook is set for live account </div>";
            } else {
                $output .= "<div style=\"color: #FF0000\"> Webhook for test account is not set. Please use this url to set it up:</div> {$webhook_url}";
            }

        } else {
            $output .= "<div style=\"color: #FF0000\"> Webhook for test account is not set. Please use this url to set it up:</div> {$webhook_url}";
        }

        return $output;

    }
}

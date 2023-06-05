<?php
/**
 * Digiwallet Payment Module for osCommerce
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license   see LICENSE.TXT
 */
chdir('../../../../');
require 'includes/application_top.php';
require 'lib/common/modules/orderPayment/digiwallet/compatibility.php';

global $navigation, $cart, $breadcrumb;


$trxid = $_REQUEST["trxid"];
if($pay_type == "PYP"){
    if($finished || $cancel) {
        // Return/Cancel URL
        $trxid = $_REQUEST['paypalid'];
    }
    else {
        // Report URL
        $trxid = $_REQUEST['acquirerID'];
    }
}
else if($pay_type == "AFP"){
    // For Afterpay only
    $trxid = $_REQUEST['invoiceID'];
}
if(!isset($trxid) || empty($trxid)){
    echo "No transaction found!";
    die;
}
$platform_id = $_REQUEST['platform_id'] ?? '';
if(!empty($platform_id)) {
    defined('PLATFORM_ID') or define('PLATFORM_ID', $platform_id);
}

$languages_id = \Yii::$app->settings->get('languages_id');
$currencies = \Yii::$container->get('currencies');

$sql = "select * from " . TABLE_DIGIWALLET_TRANSACTIONS . " where `transaction_id` = '" . $trxid . "'";
$transaction_query = tep_db_query($sql);
if (tep_db_num_rows($transaction_query) ==0) {
    echo "No transaction found!";
    die;
}

$transaction_info = tep_db_fetch_array($transaction_query);
$order_id = $transaction_info["order_id"];
// load the selected payment module
$order = new \common\classes\Order($order_id);

// Stock Check
$any_out_of_stock = false;
if (STOCK_CHECK == 'true') {
    for ($i = 0, $n = sizeof($order->products); $i < $n; $i ++) {
        if (\common\helpers\Product::check_stock($order->products[$i]['id'], $order->products[$i]['qty'])) {
            $any_out_of_stock = true;
        }
    }
    // Out of Stock
    if ((STOCK_ALLOW_CHECKOUT != 'true') && ($any_out_of_stock == true)) {
        tep_redirect(tep_href_link(FILENAME_SHOPPING_CART));
    }
}
// require DIR_WS_INCLUDES . 'template_top.php';
$trxid = $_REQUEST["trxid"];
$pay_type = $_REQUEST["type"];
if(!isset($pay_type) || empty($pay_type)){
    echo "No payment method found!";
    die;
}

if(!isset($trxid) || empty($trxid)){
    echo "No transaction found!";
    die;
}

$pay_type = (empty($pay_type)) ? "_" : "_" . $pay_type . "_";
$sql = "select * from " . TABLE_DIGIWALLET_TRANSACTIONS . " where `transaction_id` = '" . $trxid . "'";
$transaction_info = tep_db_query($sql);
$transaction_info = tep_db_fetch_array($transaction_info);

$sql = "select `orders_status_id` from " . TABLE_ORDERS_STATUS_HISTORY . " where
			`orders_status_history_id` = (SELECT MAX(`orders_status_history_id`) FROM " . TABLE_ORDERS_STATUS_HISTORY . " WHERE `orders_id` = '" . $transaction_info["order_id"] . "')";
$stateRow = tep_db_query($sql);
$stateRow = tep_db_fetch_array($stateRow);

// Payment callback was first, so we can say: the payment was successfull
if ($stateRow["orders_status_id"] == constant('MODULE_PAYMENT_DIGIWALLET' . $pay_type . 'PREPARE_ORDER_STATUS_ID')) {
    $message = 'Je betaling is gelukt!<br/><a href="index.php">Klik hier om verder te winkelen.</a>';
    $bgcolor = '#D3FFD2';
    $bordercolor = '#8FFF8C';
    $cart->reset(true);
    $cart->contents = array();
    $cart->total = 0;
    $cart->weight = 0;
    $cart->content_type = false;

    // unregister session variables used during checkout
    tep_session_unregister('sendto');
    tep_session_unregister('billto');
    tep_session_unregister('shipping');
    tep_session_unregister('payment');
    tep_session_unregister('comments');
    $cart->reset(true);
    tep_redirect(tep_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL'));
    die();
} elseif ($stateRow["orders_status_id"] == constant('MODULE_PAYMENT_DIGIWALLET' . $pay_type . 'PAYMENT_CANCELLED')) {
    // $message = 'Je hebt je betaling geannuleerd, <br/><a href="checkout_payment.php">Klik hier om een andere betaalmethode te kiezen.</a>';
    // Remove html due to wrong displaying message on frontend
    $message = 'Je hebt je betaling geannuleerd. Klik hier om een andere betaalmethode te kiezen.';
    $bgcolor = '#FFE5C8';
    $bordercolor = '#FFC78C';
} elseif ($stateRow["orders_status_id"] == constant('MODULE_PAYMENT_DIGIWALLET' . $pay_type . 'PAYMENT_ERROR')) {
    $message = 'Er was een probleem tijdens het controleren van je betaling, contacteer de webshop.';
    $bgcolor = '#FFBDB3';
    $bordercolor = '#FF9B8C';
} else {
    $message = 'Uw transactie is onderbroken.';
    $bgcolor = '#FFBDB3';
    $bordercolor = '#FF9B8C';
}

tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode($message), 'SSL', true, false));
die();

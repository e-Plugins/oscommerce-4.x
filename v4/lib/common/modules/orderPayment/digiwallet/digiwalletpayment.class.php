<?php
/**
 * Digiwallet Payment Module for osCommerce
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license   see LICENSE.TXT
 */

use common\classes\modules\ModulePayment;
use common\classes\modules\ModuleSortOrder;
use common\classes\modules\ModuleStatus;

require_once realpath(dirname(__FILE__) . '/languages/english/digiwallet.php');
require_once realpath(dirname(__FILE__) . '/compatibility.php');
require_once realpath(dirname(__FILE__) . '/digiwallet.class.php');

class digiwalletpayment extends  ModulePayment
{

    const DEFAULT_RTLO = 156187;

    const DEFAULT_DIGIWALLET_TOKEN = 'bf72755a648832f48f0995454';

    public $code = '';

    public $title = '';

    public $public_title = '';

    public $payment_icon = '';

    public $description = '';

    public $enabled = true;

    public $sort_order = 0;

    public $rtlo = '';

    public $passwordKey = '';

    public $merchantReturnURL = '';

    public $expirationPeriod = '';

    public $transactionDescription = '';

    public $transactionDescriptionText = '';

    public $returnURL = '';

    public $reportURL = '';

    public $transactionID = '';

    public $purchaseID = '';

    public $directoryUpdateFrequency = '';

    public $error = '';

    public $bankUrl = '';

    public $config_code = "IDE";

    public function __construct()
    {
        parent::__construct();

        $this->code = 'digiwallet_' . strtolower($this->config_code);
        $this->title = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TEXT_TITLE");
        $this->public_title = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TEXT_PUBLIC_TITLE");
        $this->payment_icon = tep_image('images/icons/' . $this->config_code . '_50.png', '', '', '', 'align=absmiddle');
        $this->description = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TEXT_DESCRIPTION") . $this->getConstant("MODULE_PAYMENT_DIGIWALLET_TESTMODE_WARNING_MESSAGE");
        $sort_order = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_SORT_ORDER");
        if(!empty($sort_order)) {
            $this->sort_order = $sort_order;
        }
        $this->enabled = (($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_STATUS") == 'True') ? true : false);

        $this->rtlo = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_DIGIWALLET_RTLO");

        $this->transactionDescription = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TRANSACTION_DESCRIPTION");
        $this->transactionDescriptionText = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_MERCHANT_TRANSACTION_DESCRIPTION_TEXT");

        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_REPAIR_ORDER") === true) {
            if ($_GET['digiwallet_transaction_id']) {
                $_SESSION['digiwallet_repair_transaction_id'] = tep_db_input($_GET['digiwallet_transaction_id']);
            }
            $this->transactionID = $_SESSION['digiwallet_repair_transaction_id'];
        }
        $this->update_status();
    }

    /**
     * Get the defined constant values
     *
     * @param $key
     * @return mixed|boolean
     */
    public function getConstant($key)
    {
        if (defined($key)) {
            return constant($key);
        }
        return false;
    }


    public function isOnline()
    {
        return true;
    }

    /**
     * update module status
     */
    public function update_status()
    {
        if (($this->enabled == true) && ((int) $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ZONE") > 0)) {
            $check_flag = false;
            $check = tep_db_query("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ZONE") . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");

            while (! $check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $this->billing['zone_id']) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $this->delivery['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }
            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    /**
     * get bank directory
     */
    public function getDirectory()
    {
        $issuerList = array();

        $objDigiCore = new DigiWalletCore($this->config_code, $this->rtlo);

        $bankList = $objDigiCore->getBankList();
        foreach ($bankList as $issuerID => $issuerName) {
            $i = new stdClass();
            $i->issuerID = $issuerID;
            $i->issuerName = $issuerName;
            $i->issuerList = 'short';
            array_push($issuerList, $i);
        }
        return $issuerList;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see digiwallet::selection()
     */
    public function selection()
    {
        return array(
            'id' => $this->code,
            'module' => $this->title
        );
    }

    /**
     * Don't check javascript validation
     *
     * @return boolean
     */
    public function javascript_validation()
    {
        return false;
    }

    /**
     */
    public function pre_confirmation_check()
    {
        global $cartID, $cart;

        if (empty($cart->cartID)) {
            $cartID = $cart->cartID = $cart->generate_cart_id();
        }

        if (! tep_session_is_registered('cartID')) {
            tep_session_register('cartID');
        }
    }

    /**
     * prepare the transaction and send user back on error or forward to bank
     */
    public function prepareTransaction()
    {
        global $order, $currencies, $customer_id, $db, $order_totals, $cart_digiwallet_id;
        $order = $this->manager->getOrderInstance();
        list ($void, $customOrderId) = explode("-", $cart_digiwallet_id);

        $payment_purchaseID = time();
        $payment_issuer = $this->config_code;
        $payment_currency = "EUR"; // future use
        $payment_language = "nl"; // future use
        $payment_amount = round($order->info['total'] * 100, 0);
        $payment_entranceCode = tep_session_id();
        if ((strtolower($this->transactionDescription) == 'automatic') && (count($order->products) == 1)) {
            $product = $order->products[0];
            $payment_description = $product['name'];
        } else {
            $payment_description = 'Order:' . $customOrderId . ' ' . $this->transactionDescriptionText;
        }
        $payment_description = trim(strip_tags($payment_description));
        // This function has been DEPRECATED as of PHP 5.3.0. Relying on this feature is highly discouraged.
        // $payment_description = ereg_replace("[^( ,[:alnum:])]", '*', $payment_description);
        $payment_description = preg_replace("/[^a-zA-Z0-9\s]/", '', $payment_description);
        $payment_description = substr($payment_description, 0, 31); /* Max. 32 characters */
        if (empty($payment_description)) {
            $payment_description = 'nvt';
        }

        $iTest = false;//($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TESTACCOUNT") == "True") ? 1 : 0;
        $objDigiCore = new DigiWalletCore($payment_issuer, $this->rtlo, 'nl', $iTest);
        $objDigiCore->setAmount($payment_amount);
        $objDigiCore->setDescription($payment_description);

        if(!empty($_POST['bankID'])) {  //for ideal
            $objDigiCore->setBankId($_POST['bankID']);
        }
        if(!empty($_POST['countryID'])) {   //for sorfor
            $objDigiCore->setBankId($_POST['countryID']);
        }

        $objDigiCore->setReturnUrl(DigiWalletCore::formatOscommerceUrl(tep_href_link('ext/modules/payment/digiwallet/callback.php?type=' . $this->config_code, '', 'SSL') . "&finished=1"));
        $objDigiCore->setReportUrl(DigiWalletCore::formatOscommerceUrl(tep_href_link('ext/modules/payment/digiwallet/callback.php?type=' . $this->config_code, '', 'SSL')));
        $objDigiCore->setCancelUrl(DigiWalletCore::formatOscommerceUrl(tep_href_link('ext/modules/payment/digiwallet/callback.php?type=' . $this->config_code, '', 'SSL') . "&cancel=1"));

        // Consumer's email address
        if(isset($order->customer['email_address']) && !empty($order->customer['email_address'])) {
            $objDigiCore->bindParam("email", $order->customer['email_address']);
        }

        $result = @$objDigiCore->startPayment();

        if ($result === false) {
            $messageStack = \Yii::$container->get('message_stack');
            $messageStack->add_session('checkout_payment', $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . "<br/>" . $objDigiCore->getErrorMessage());
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . " " . $objDigiCore->getErrorMessage()), 'SSL', true, false));
        }

        $this->transactionID = $objDigiCore->getTransactionId();

        $this->bankUrl = $objDigiCore->getBankUrl();

        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_EMAIL_ORDER_INIT") == 'True') {
            $email_text = 'Er is zojuist een Digiwallet iDeal bestelling opgestart' . "\n\n";
            $email_text .= 'Details:' . "\n";
            $email_text .= 'customer_id: ' . $_SESSION['customer_id'] . "\n";
            $email_text .= 'customer_first_name: ' . $_SESSION['customer_first_name'] . "\n";
            $email_text .= 'Digiwallet transaction_id: ' . $this->transactionID . "\n";
            $email_text .= 'bedrag: ' . $payment_amount . ' (' . $payment_currency . 'x100)' . "\n";
            $max_orders_id = tep_db_query("select max(orders_id) orders_id from " . TABLE_ORDERS);
            $new_order_id = $max_orders_id->fields['orders_id'] + 1;
            $email_text .= 'order_id: ' . $new_order_id . ' (verwacht indien de bestelling wordt voltooid, kan ook hoger zijn)' . "\n";
            $email_text .= "\n\n";
            $email_text .= 'Digiwallet transactions lookup: ' . HTTP_SERVER_DIGIWALLET_ADMIN . FILENAME_DIGIWALLET_TRANSACTIONS . '?action=lookup&transactionID=' . $this->transactionID . "\n";

            \common\helpers\Mail::send('', STORE_OWNER_EMAIL_ADDRESS, '[iDeal bestelling opgestart] #' . $new_order_id . ' (?)', $email_text, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
        }

        tep_db_query("INSERT INTO " . TABLE_DIGIWALLET_TRANSACTIONS . "
		    		(
		    		`transaction_id`,
		    		`rtlo`,
		    		`purchase_id`,
		    		`issuer_id`,
		    		`transaction_status`,
		    		`datetimestamp`,
		    		`customer_id`,
		    		`amount`,
		    		`currency`,
		    		`session_id`,
		    		`ideal_session_data`,
		    		`order_id`
		    		) VALUES (
		    		'" . $this->transactionID . "',
		    		'" . $this->rtlo . "',
		    		'" . $payment_purchaseID . "',
		    		'" . $payment_issuer . "',
		    		'open',
		    		NOW( ),
		    		'" . $_SESSION['customer_id'] . "',
		    		'" . $payment_amount . "',
		    		'" . $payment_currency . "',
		    		'" . tep_db_input(tep_session_id()) . "',
		    		'" . base64_encode(serialize($_SESSION)) . "',
		    		'" . $customOrderId . "'
		    		);");
        tep_redirect(html_entity_decode($this->bankUrl));
    }

    /**
     *
     * @return false
     * @throws Exception
     */
    public function confirmation()
    {
        global $cartID, $cart_digiwallet_id, $order, $order_total_modules;
        $order = $this->manager->getOrderInstance();
        $customer_id = \Yii::$app->session->get('customer_id');
        $customer_id = $order->customer['customer_id'];

        if (tep_session_is_registered('cartID')) {
            $insert_order = false;
            $update_order = false;

            if (tep_session_is_registered('cart_digiwallet_id')) {
                $order_id = substr($cart_digiwallet_id, strpos($cart_digiwallet_id, '-') + 1);

                $curr_check = tep_db_query("select currency, payment_method from " . TABLE_ORDERS . " where orders_id = '" . (int) $order_id . "'");
                $curr = tep_db_fetch_array($curr_check);

                if (($curr['currency'] != $order->info['currency']) || ($curr['payment_method'] != $order->info['payment_method']) || ($cartID != substr($cart_digiwallet_id, 0, strlen($cartID)))) {
                    $check_query = tep_db_query('select orders_id from ' . TABLE_ORDERS_STATUS_HISTORY . ' where orders_id = "' . (int) $order_id . '" limit 1');

                    if (tep_db_num_rows($check_query) < 1) {
                        tep_db_query('delete from ' . TABLE_ORDERS . ' where orders_id = "' . (int) $order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_TOTAL . ' where orders_id = "' . (int) $order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_STATUS_HISTORY . ' where orders_id = "' . (int) $order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_PRODUCTS . ' where orders_id = "' . (int) $order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_PRODUCTS_ATTRIBUTES . ' where orders_id = "' . (int) $order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_PRODUCTS_DOWNLOAD . ' where orders_id = "' . (int) $order_id . '"');
                        $insert_order = true;
                    } else {
                        $update_order = true;
                    }
                }
            } else {
                $insert_order = true;
            }
            if($update_order) {
                $order = $this->manager->getOrderInstance();
                $order->save_order($order_id); //Update existing
            }
            if ($insert_order == true) {
                $order = $this->manager->getOrderInstance();
                $order->order_id = 0;
                $order->status = "new";
                $insert_id = $this->saveOrder("Order");

                /*
                $order_totals = array();
                if (is_array($order_total_modules->modules)) {
                    reset($order_total_modules->modules);
                    while (list (, $value) = each($order_total_modules->modules)) {
                        $class = substr($value, 0, strrpos($value, '.'));
                        if ($GLOBALS[$class]->enabled) {
                            for ($i = 0, $n = sizeof($GLOBALS[$class]->output); $i < $n; $i ++) {
                                if (tep_not_null($GLOBALS[$class]->output[$i]['title']) && tep_not_null($GLOBALS[$class]->output[$i]['text'])) {
                                    $order_totals[] = array(
                                        'code' => $GLOBALS[$class]->code,
                                        'title' => $GLOBALS[$class]->output[$i]['title'],
                                        'text' => $GLOBALS[$class]->output[$i]['text'],
                                        'value' => $GLOBALS[$class]->output[$i]['value'],
                                        'sort_order' => $GLOBALS[$class]->sort_order
                                    );
                                }
                            }
                        }
                    }
                }

                $sql_data_array = array(
                    'customers_id' => $customer_id,
                    'customers_name' => $order->customer['firstname'] . ' ' . $order->customer['lastname'],
                    'customers_company' => $order->customer['company'],
                    'platform_id' => PLATFORM_ID,
                    'customers_street_address' => $order->customer['street_address'],
                    'customers_suburb' => $order->customer['suburb'],
                    'customers_city' => $order->customer['city'],
                    'customers_postcode' => $order->customer['postcode'],
                    'customers_state' => $order->customer['state'],
                    'customers_country' => $order->customer['country']['title'],
                    'customers_telephone' => $order->customer['telephone'],
                    'customers_email_address' => $order->customer['email_address'],
                    'customers_address_format_id' => $order->customer['format_id'],
                    'delivery_name' => $order->delivery['firstname'] . ' ' . $order->delivery['lastname'],
                    'delivery_company' => $order->delivery['company'],
                    'delivery_street_address' => $order->delivery['street_address'],
                    'delivery_suburb' => $order->delivery['suburb'],
                    'delivery_city' => $order->delivery['city'],
                    'delivery_postcode' => $order->delivery['postcode'],
                    'delivery_state' => $order->delivery['state'],
                    'delivery_country' => $order->delivery['country']['title'],
                    'delivery_address_format_id' => $order->delivery['format_id'],
                    'billing_name' => $order->billing['firstname'] . ' ' . $order->billing['lastname'],
                    'billing_company' => $order->billing['company'],
                    'billing_street_address' => $order->billing['street_address'],
                    'billing_suburb' => $order->billing['suburb'],
                    'billing_city' => $order->billing['city'],
                    'billing_postcode' => $order->billing['postcode'],
                    'billing_state' => $order->billing['state'],
                    'billing_country' => $order->billing['country']['title'],
                    'billing_address_format_id' => $order->billing['format_id'],
                    'payment_method' => $order->info['payment_method'],
                    'cc_type' => $order->info['cc_type'],
                    'cc_owner' => $order->info['cc_owner'],
                    'cc_number' => $order->info['cc_number'],
                    'cc_expires' => $order->info['cc_expires'],
                    'date_purchased' => 'now()',
                    'orders_status' => $order->info['order_status'],
                    'currency' => $order->info['currency'],
                    'currency_value' => $order->info['currency_value']
                );

                tep_db_perform(TABLE_ORDERS, $sql_data_array);

                $insert_id = tep_db_insert_id();

                for ($i = 0, $n = sizeof($order_totals); $i < $n; $i ++) {
                    $sql_data_array = array(
                        'orders_id' => $insert_id,
                        'title' => $order_totals[$i]['title'],
                        'text' => $order_totals[$i]['text'],
                        'value' => $order_totals[$i]['value'],
                        'class' => $order_totals[$i]['code'],
                        'sort_order' => $order_totals[$i]['sort_order']
                    );
                    tep_db_perform(TABLE_ORDERS_TOTAL, $sql_data_array);
                }

                for ($i = 0, $n = sizeof($order->products); $i < $n; $i ++) {
                    $sql_data_array = array(
                        'orders_id' => $insert_id,
                        'products_id' => $order->products[$i]['id'],
                        'products_model' => $order->products[$i]['model'],
                        'products_name' => $order->products[$i]['name'],
                        'products_price' => $order->products[$i]['price'],
                        'final_price' => $order->products[$i]['final_price'],
                        'products_tax' => $order->products[$i]['tax'],
                        'products_quantity' => $order->products[$i]['qty']
                    );

                    tep_db_perform(TABLE_ORDERS_PRODUCTS, $sql_data_array);

                    $order_products_id = tep_db_insert_id();
                    $attributes_exist = '0';
                    if (isset($order->products[$i]['attributes'])) {
                        $attributes_exist = '1';
                        for ($j = 0, $n2 = sizeof($order->products[$i]['attributes']); $j < $n2; $j ++) {
                            if (defined('DOWNLOAD_ENABLED') && DOWNLOAD_ENABLED == 'true') {
                                $attributes_query = "select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix, pad.products_attributes_maxdays, pad.products_attributes_maxcount , pad.products_attributes_filename
														from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa
														left join " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
														on pa.products_attributes_id=pad.products_attributes_id
														where pa.products_id = '" . $order->products[$i]['id'] . "'
														and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "'
														and pa.options_id = popt.products_options_id
														and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "'
														and pa.options_values_id = poval.products_options_values_id
														and popt.language_id = '" . $languages_id . "'
														and poval.language_id = '" . $languages_id . "'";
                                $attributes = tep_db_query($attributes_query);
                            } else {
                                $attributes = tep_db_query("select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa where pa.products_id = '" . $order->products[$i]['id'] . "' and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "' and pa.options_id = popt.products_options_id and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "' and pa.options_values_id = poval.products_options_values_id and popt.language_id = '" . $languages_id . "' and poval.language_id = '" . $languages_id . "'");
                            }
                            $attributes_values = tep_db_fetch_array($attributes);

                            $sql_data_array = array(
                                'orders_id' => $insert_id,
                                'orders_products_id' => $order_products_id,
                                'products_options' => $attributes_values['products_options_name'],
                                'products_options_values' => $attributes_values['products_options_values_name'],
                                'options_values_price' => $attributes_values['options_values_price'],
                                'price_prefix' => $attributes_values['price_prefix']
                            );

                            tep_db_perform(TABLE_ORDERS_PRODUCTS_ATTRIBUTES, $sql_data_array);

                            if ((defined('DOWNLOAD_ENABLED') && DOWNLOAD_ENABLED == 'true') && isset($attributes_values['products_attributes_filename']) && tep_not_null($attributes_values['products_attributes_filename'])) {
                                $sql_data_array = array(
                                    'orders_id' => $insert_id,
                                    'orders_products_id' => $order_products_id,
                                    'orders_products_filename' => $attributes_values['products_attributes_filename'],
                                    'download_maxdays' => $attributes_values['products_attributes_maxdays'],
                                    'download_count' => $attributes_values['products_attributes_maxcount']
                                );

                                tep_db_perform(TABLE_ORDERS_PRODUCTS_DOWNLOAD, $sql_data_array);
                            }
                        }
                    }
                }
                */

                $cart_digiwallet_id = $cartID . '-' . $insert_id;
                tep_session_register('cart_digiwallet_id');
            }
        }
        return false;
    }

    /**
     * make hidden value for payment system
     */
    public function process_button()
    {
        $process_button = tep_draw_hidden_field('payment_code', $this->config_code) . $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_EXPRESS_TEXT");
        return $process_button;
    }

    /**
     * before process check status or prepare transaction
     */
    public function before_process()
    {
        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_REPAIR_ORDER") === true) {
            $order = $this->manager->getOrderInstance();
            // when repairing iDeal the transaction status is succes, set order status accordingly
            $order->info['order_status'] = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ORDER_STATUS_ID");
            return false;
        }
        if (isset($_GET['action']) && $_GET['action'] == "process") {
            $this->checkStatus();
        } else {
            $this->prepareTransaction();
        }
    }

    /**
     * check payment status
     */
    public function checkStatus()
    {
        global $db;

        $order = $this->manager->getOrderInstance();
        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_REPAIR_ORDER") === true) {
            return false;
        }
        $this->transactionID = tep_db_input($_GET['trxid']);
        $method = tep_db_input($_GET['method']);

        if ($this->transactionID == "") {
            $messageStack = \Yii::$container->get('message_stack');
            $messageStack->add_session('checkout_payment', $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING"));
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING")), 'SSL', true, false));
        }

        $iTest = false;//($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TESTACCOUNT") == "True") ? 1 : 0;

        $objDigiCore = new DigiWalletCore($method, $this->rtlo, 'nl', $iTest);
        $status = @$objDigiCore->checkPayment($this->transactionID);

        if ($objDigiCore->getPaidStatus()) {
            $realstatus = "success";
        } else {
            $realstatus = "open";
        }

        $customerInfo = $objDigiCore->getConsumerInfo();
        $consumerAccount = (((isset($customerInfo->consumerInfo["bankaccount"]) && ! empty($customerInfo->consumerInfo["bankaccount"])) ? $customerInfo->consumerInfo["bankaccount"] : ""));
        $consumerName = (((isset($customerInfo->consumerInfo["name"]) && ! empty($customerInfo->consumerInfo["name"])) ? $customerInfo->consumerInfo["name"] : ""));
        $consumerCity = (((isset($customerInfo->consumerInfo["city"]) && ! empty($customerInfo->consumerInfo["city"])) ? $customerInfo->consumerInfo["city"] : ""));

        tep_db_query("UPDATE " . TABLE_DIGIWALLET_TRANSACTIONS . " SET `transaction_status` = '" . $realstatus . "',`datetimestamp` = NOW( ) ,`consumer_name` = '" . $consumerName . "',`consumer_account_number` = '" . $consumerAccount . "',`consumer_city` = '" . $consumerCity . "' WHERE `transaction_id` = '" . $this->transactionID . "' LIMIT 1");

        switch ($realstatus) {
            case "success":
                $order->info['order_status'] = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ORDER_STATUS_ID");
                break;
            default:
                $messageStack = \Yii::$container->get('message_stack');
                $messageStack->add_session('checkout_payment', $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_TRANSACTION_OPEN"));
                tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_TRANSACTION_OPEN")), 'SSL', true, false));
                break;
        }
    }

    /**
     * after order create set value in database
     *
     * @param
     *            $zf_order_id
     */
    public function after_order_create($zf_order_id)
    {
        tep_db_query("UPDATE " . TABLE_DIGIWALLET_TRANSACTIONS . " SET `order_id` = '" . $zf_order_id . "', `ideal_session_data` = '' WHERE `transaction_id` = '" . $this->transactionID . "' LIMIT 1 ;");
        if (isset($_SESSION['digiwallet_repair_transaction_id'])) {
            unset($_SESSION['digiwallet_repair_transaction_id']);
        }
    }

    /**
     * after process function
     *
     * @return false
     */
    public function after_process()
    {
        echo 'after process komt hier';
        return false;
    }

    /**
     * @return \common\classes\modules\ModuleStatus
     */
    public function describe_status_key()
    {
        return new ModuleStatus('MODULE_PAYMENT_DIGIWALLET_' . $this->config_code . '_STATUS', 'True', 'False');
    }

    /**
     * @return \common\classes\modules\ModuleSortOrder
     */
    public function describe_sort_key()
    {
        return new ModuleSortOrder('MODULE_PAYMENT_DIGIWALLET_' . $this->config_code . '_SORT_ORDER');
    }

    /**
     * @return array
     */
    public function configure_keys()
    {
        tep_db_query("CREATE TABLE IF NOT EXISTS " . TABLE_DIGIWALLET_DIRECTORY . " (`issuer_id` VARCHAR( 4 ) NOT NULL ,`issuer_name` VARCHAR( 30 ) NOT NULL ,`issuer_issuerlist` VARCHAR( 5 ) NOT NULL ,`timestamp` DATETIME NOT NULL ,PRIMARY KEY ( `issuer_id` ) );");

        tep_db_query("CREATE TABLE IF NOT EXISTS " . TABLE_DIGIWALLET_TRANSACTIONS . " (`transaction_id` VARCHAR( 30 ) NOT NULL ,`rtlo` VARCHAR( 7 ) NOT NULL ,`purchase_id` VARCHAR( 30 ) NOT NULL , `issuer_id` VARCHAR( 25 ) NOT NULL, `more` TEXT NULL, `session_id` VARCHAR( 128 ) NOT NULL ,`ideal_session_data`  MEDIUMBLOB NOT NULL ,`order_id` INT( 11 ),`transaction_status` VARCHAR( 10 ) ,`datetimestamp` DATETIME, `last_modified` TIMESTAMP NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP, `consumer_name` VARCHAR( 50 ) ,`consumer_account_number` VARCHAR( 20 ) ,`consumer_city` VARCHAR( 50 ), `customer_id` INT( 11 ), `amount` DECIMAL( 15, 4 ), `currency` CHAR( 3 ), `batch_id` VARCHAR( 30 ), PRIMARY KEY ( `transaction_id` ));");


        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS;
        $status_query = tep_db_query($sql);

        $status = tep_db_fetch_array($status_query);
        $status_id = $status['status_id'] + 1;
        $cancel = $status['status_id'] + 2;
        $error = $status['status_id'] + 3;
        $review = $status['status_id'] + 4;

        $languages = \common\helpers\Language::get_languages();
        $orderStatusIds = array(
            $status_id => [
                'name' => 'Payment Paid [digiwallet]',
                'group' => 2
            ],
            $cancel => [
                'name' => 'Payment canceled [digiwallet]',
                'group' => 5
            ],
            $error => [
                'name' => 'Payment error [digiwallet]',
                'group' => 5
            ],
            $review => [
                'name' => 'Payment Review [digiwallet]',
                'group'=> 2
            ]
        );
        foreach ($languages as $language) {
            foreach ($orderStatusIds as $orderStatusId => $orderStatusObj) {
                $query = sprintf(
                    'SELECT 1 FROM %s WHERE `language_id` = %d AND `orders_status_name` = "%s"',
                    TABLE_ORDERS_STATUS,
                    $language['id'],
                    $orderStatusObj['name']
                );
                if (tep_db_num_rows(tep_db_query($query)) == 0) {
                    $query = sprintf(
                        'INSERT INTO %s SET `orders_status_groups_id`  = %d, `orders_status_id` = %d, `language_id` = %d, `orders_status_name` = "%s"',
                        TABLE_ORDERS_STATUS,
                        $orderStatusObj['group'],
                        $orderStatusId,
                        $language['id'],
                        $orderStatusObj['name']
                    );
                    tep_db_query($query);
                }
            }
        }

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Paid [digiwallet]'";
        $status = tep_db_fetch_array(tep_db_query($sql));
        $status_id = $status['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment canceled [digiwallet]'";
        $status = tep_db_fetch_array(tep_db_query($sql));
        $cancel = $status['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment error [digiwallet]'";
        $status = tep_db_fetch_array(tep_db_query($sql));
        $error = $status['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Review [digiwallet]'";
        $status = tep_db_fetch_array(tep_db_query($sql));
        $review = $status['status_id'];

        return [
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_STATUS" => [
                'title' => 'Enable Digiwallet payment module',
                'value' => 'True',
                'description' => 'Do you want to accept Digiwallet payments?',
                'sort_order' => '0',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_SORT_ORDER" => [
                'title' => 'Sortorder',
                'value' => "{$this->sort_order}",
                'description' => 'Sort order of payment methods in list. Lowest is displayed first.',
                'sort_order' => '2',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ZONE" => [
                'title' => 'Payment zone',
                'value' => "0",
                'description' => 'If a zone is selected, enable this payment method for that zone only.',
                'sort_order' => '3',
                'use_function' => '\\common\\helpers\\Zones::get_zone_class_title',
                'set_function' => 'tep_cfg_pull_down_zone_classes(',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TRANSACTION_DESCRIPTION" => [
                'title' => 'Transaction description',
                'value' => "Automatic",
                'description' => 'Select automatic for product name as description, or manual to use the text you supply below.',
                'sort_order' => '8',
                'set_function' => 'tep_cfg_select_option(array(\'Automatic\', \'Manual\'), ',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_MERCHANT_TRANS_DESC" => [
                'title' => 'Transaction description text',
                'value' => "{$this->title}",
                'description' => 'Description of transactions from this webshop. <strong>Should not be empty!</strong>.',
                'sort_order' => '8',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_DIGIWALLET_RTLO" => [
                'title' => 'Digiwallet Outlet Identifier',
                'value' => self::DEFAULT_RTLO,
                'description' => 'The Digiwallet layout code.',
                'sort_order' => '1',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_DIGIWALLET_API_TOKEN" => [
                'title' => 'Digiwallet API Token',
                'value' => self::DEFAULT_DIGIWALLET_TOKEN,
                'description' => 'The Digiwallet API Token.',
                'sort_order' => '2',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_EMAIL_ORDER_INIT" => [
                'title' => 'Enable pre order emails',
                'value' => 'False',
                'description' => 'Do you want emails to be sent to the store owner whenever an Digiwallet order is being initiated? The default is <strong>False</strong>',
                'sort_order' => '20',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ],


            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PREPARE_ORDER_STATUS_ID" => [
                'title' => 'Set Paid Order Status',
                'value' => "$status_id",
                'description' => 'Set the status of prepared orders to success.',
                'sort_order' => '0',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_CANCELLED" => [
                'title' => 'Set Cancelled Order Status',
                'value' => "$cancel",
                'description' => 'The payment is cancelled by the end user.',
                'sort_order' => '0',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_ERROR" => [
                'title' => 'Set Order Error Status',
                'value' => "$error",
                'description' => 'The payment is error.',
                'sort_order' => '0',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ],
            "MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_REVIEW" => [
                'title' => 'Set Order Review Status',
                'value' => "$review",
                'description' => 'The payment is being reviewed when paid by Bankwire.',
                'sort_order' => '0',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ]
        ];
    }
}

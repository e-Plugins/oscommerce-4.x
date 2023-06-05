<?php

/**
 * Digiwallet Payment Module for osCommerce
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license   see LICENSE.TXT
 */

namespace frontend\controllers;

use common\classes\platform;
use common\models\repositories\OrderRepository;
use frontend\design\Info;
use Yii;
use yii\helpers\ArrayHelper;
use yii\web\NotFoundHttpException;
use yii\web\Session;
use common\components\Customer;
use common\components\Socials;

/**
 * Site controller
 * @property \common\services\OrderManager $manager
 */
class DigiwalletController extends \frontend\classes\AbstractCheckoutController
{

    private $orderRepository;

    public function actionIndex()
    {
        tep_redirect(tep_href_link(FILENAME_DEFAULT));
    }

    public function actionBankwire()
    {
        require_once 'lib/common/modules/orderPayment/digiwallet/compatibility.php';
        require_once 'lib/common/modules/orderPayment/digiwallet/digiwallet.class.php';

        $trxid = $_REQUEST["trxid"];
        if (empty($trxid)) {
            $trxid = $_REQUEST['invoiceID'];
        }
        if (!$trxid) {
            tep_redirect(tep_href_link(FILENAME_DEFAULT, '', 'SSL', true, false));
            exit(0);
        }
        $languages_id = \Yii::$app->settings->get('languages_id');
        $currencies = \Yii::$container->get('currencies');

        $query = tep_db_query("select * from " . TABLE_LANGUAGES . " where `languages_id` = '" . $languages_id . "'");
        if (tep_db_num_rows($query) > 0) {
            $language_name = strtolower(tep_db_fetch_array($query)['name']);
        } else {
            $language_name = 'dutch';
        }

        $availableLanguages = array("dutch","english");
        $langDir = (in_array($language_name, $availableLanguages)) ? $language_name : "dutch";
        require_once 'lib/common/modules/orderPayment/digiwallet/languages/'.$langDir.'/digiwallet.php';
        // Check transaction in digiwallet sale table
        $sql = "select * from " . TABLE_DIGIWALLET_TRANSACTIONS . " where `transaction_id` = '" . tep_db_input($trxid) . "'";
        $sale_obj = tep_db_query($sql);
        if (tep_db_num_rows($sale_obj) > 0) {
            $sale = tep_db_fetch_array($sale_obj);
        } else {
            tep_redirect(tep_href_link(FILENAME_DEFAULT, '', 'SSL', true, false));
            exit(0);
        }
        // Check customer's order information
        $customer_info = null;
        $query = tep_db_query("select * from " . TABLE_ORDERS . " where `orders_id` = '" . $sale['order_id'] . "'");
        if (tep_db_num_rows($query) > 0) {
            $customer_info = tep_db_fetch_array($query);
        } else {
            tep_redirect(tep_href_link(FILENAME_DEFAULT, '', 'SSL', true, false));
            exit(0);
        }
        $template = "";
        if ($sale['transaction_status'] == "success") {
            $template .= "<h1>" . MODULE_PAYMENT_DIGIWALLET_BANKWIRE_THANKYOU_FINISHED . "</h1>";
        } else {
            list($trxid, $accountNumber, $iban, $bic, $beneficiary, $bank) = explode("|", $sale['more']);
            // Encode email address
            $emails = str_split($customer_info['customers_email_address']);
            $counter = 0;
            $cus_email = "";
            foreach ($emails as $char) {
                if ($counter == 0) {
                    $cus_email .= $char;
                    $counter++;
                } else if ($char == "@") {
                    $cus_email .= $char;
                    $counter++;
                } else if ($char == "." && $counter > 1) {
                    $cus_email .= $char;
                    $counter++;
                } else if ($counter > 2) {
                    $cus_email .= $char;
                } else {
                    $cus_email .= "*";
                }
            }
            $template .= sprintf(MODULE_PAYMENT_DIGIWALLET_BANKWIRE_THANKYOU_PAGE,
                $currencies->display_price(((float)$sale['amount']) / 100, 0),
                $iban,
                $beneficiary,
                $trxid,
                $cus_email,
                $bic,
                $bank
            );
        }
        if(tep_session_is_registered('cart_digiwallet_id')) {
            tep_session_unregister('cart_digiwallet_id');
        }
        return $this->render('success.tpl', [
            'template' => $template
        ]);
    }
}
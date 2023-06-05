<?php
/**
 * Digiwallet Payment Module for osCommerce
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license   see LICENSE.TXT
 */

namespace common\modules\orderPayment;

require_once realpath(dirname(__FILE__) . '/digiwallet/digiwallet.client.php');


class digiwallet_eps extends \digiwalletClient
{
    public function __construct()
    {
        $this->sort_order = 5;
        $this->config_code = "EPS";
        parent::__construct();
    }
}

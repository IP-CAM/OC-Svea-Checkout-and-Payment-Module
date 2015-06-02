<?php
include_once(dirname(__FILE__).'/svea_common.php');

class ControllerPaymentsveapartpayment extends SveaCommon {

    /**
     * Returns the currency used for an invoice country.
     */
    protected function getPartpaymentCurrency( $countryCode ) {
        $country_currencies = array(
            'SE' => 'SEK',
            'NO' => 'NOK',
            'FI' => 'EUR',
            'DK' => 'DKK',
            'NL' => 'EUR',
            'DE' => 'EUR'
        );
        return $country_currencies[$countryCode];
    }

    protected function index() {
        // populate data array for use in template
        $this->load->language('payment/svea_partpayment');
        $this->load->model('checkout/order');
        $this->data['button_confirm'] = $this->language->get('button_confirm');
        $this->data['button_back'] = $this->language->get('button_back');

        $this->data['continue'] = 'index.php?route=checkout/success';

        if ($this->request->get['route'] != 'checkout/guest_step_3') {
            $this->data['back'] = 'index.php?route=checkout/payment';
        } else {
            $this->data['back'] = 'index.php?rout=checkout/guest_step_2';
        }

        $this->id = 'payment';

        //Get the country from the order
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $this->data['countryCode'] = $order_info['payment_iso_code_2'];
        if($this->data['countryCode'] == "NO" || $this->data['countryCode'] == "DK" || $this->data['countryCode'] == "NL"){
            $logoImg = "http://cdn.svea.com/sveafinans/rgb_svea-finans_small.png";
        } else {
            $logoImg = "http://cdn.svea.com/sveaekonomi/rgb_ekonomi_small.png";
        }
        $this->data['logo'] = "<img src='$logoImg' alt='Svea Ekonomi'>";

        // we show the available payment plans w/monthly amounts as radiobuttons under the logo
        $this->data['paymentOptions'] = $this->getPaymentOptions();



        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/svea_partpayment.tpl')) {
            $this->template = $this->config->get('config_template') . '/template/payment/svea_partpayment.tpl';
        } else {
            $this->template = 'default/template/payment/svea_partpayment.tpl';
            $this->data['partpayment_fail'] = $this->language->get('text_partpayment_fail');
        }
        $this->render();
    }

    private function responseCodes($err, $msg = "") {
        $this->load->language('payment/svea_partpayment');

        $definition = $this->language->get("response_$err");

        if (preg_match("/^response/", $definition))
            $definition = $this->language->get("response_error") . " $msg";

        return $definition;
    }

    public function confirm() {
        $this->load->language('payment/svea_partpayment');
        //Load models
        $this->load->model('payment/svea_invoice');
        $this->load->model('checkout/order');
        $this->load->model('payment/svea_partpayment');
        $this->load->model('checkout/coupon');
        $this->load->model('account/address');

        floatval(VERSION) >= 1.5 ? $this->load->model('checkout/voucher') : $this->load->model('checkout/extension');

        //Load SVEA includes
        include(DIR_APPLICATION . '../svea/Includes.php');
        $response = array();
        //Get order information
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $countryCode = $order['payment_iso_code_2'];
        //Testmode
        if ($this->config->get('svea_partpayment_testmode_' . $countryCode) !== NULL) {
            $conf = $this->config->get('svea_partpayment_testmode_' . $countryCode) == "1" ? new OpencartSveaConfigTest($this->config) : new OpencartSveaConfig($this->config);
        } else {
            $response = array("error" => $this->responseCodes(40001, "The country is not supported for this paymentmethod"));
            echo json_encode($response);
            exit();
        }

        $svea = WebPay::createOrder($conf);

        // Get the products in the cart
        $products = $this->cart->getProducts();

        // make sure we use the currency matching the clientno
        $this->load->model('localisation/currency');
        $currency_info = $this->model_localisation_currency->getCurrencyByCode( $this->getPartpaymentCurrency($countryCode) );
        $currencyValue = $currency_info['value'];

        //products
        $svea = $this->formatOrderRows($svea, $products, $currencyValue);
        //get all addons
        $addons = $this->addTaxRateToAddons();
        //extra charge addons like shipping and invoice fee
        foreach ($addons as $addon) {
            if ($addon['value'] >= 0) {
                $vat = floatval($addon['value'] * $currencyValue) * (intval($addon['tax_rate']) / 100 );
                $svea = $svea
                        ->addOrderRow(Item::orderRow()
                        ->setQuantity(1)
                        ->setAmountIncVat(floatval($addon['value'] * $currencyValue) + $vat)
                        ->setVatPercent(intval($addon['tax_rate']))
                        ->setName(isset($addon['title']) ? $addon['title'] : "")
                        ->setUnit($this->language->get('unit'))
                        ->setArticleNumber($addon['code'])
                        ->setDescription(isset($addon['text']) ? $addon['text'] : "")
                );
            }
                 //voucher(-)
            elseif ($addon['value'] < 0 && $addon['code'] == 'voucher') {
                $svea = $svea
                    ->addDiscount(WebPayItem::fixedDiscount()
                        ->setDiscountId($addon['code'])
                        ->setAmountIncVat(floatval(abs($addon['value']) * $currencyValue))
                        ->setVatPercent(0)//no vat when using a voucher
                        ->setName(isset($addon['title']) ? $addon['title'] : "")
                        ->setUnit($this->language->get('unit'))
                        ->setDescription(isset($addon['text']) ? $addon['text'] : "")
                );
            }
                //discounts
            else {
                $taxRates = Svea\Helper::getTaxRatesInOrder($svea);
                $discountRows = Svea\Helper::splitMeanToTwoTaxRates(abs($addon['value']), $addon['tax_rate'], $addon['title'], $addon['text'], $taxRates);
                foreach ($discountRows as $row) {
                    $svea = $svea->addDiscount($row);
                }
            }
        }


         if($order["payment_iso_code_2"] == "DE" || $order["payment_iso_code_2"] == "NL") {
           $addressArr = Svea\Helper::splitStreetAddress( $order['payment_address_1'] );
        }  else {
            $addressArr[1] =  $order['payment_address_1'];
            $addressArr[2] =  "";
        }
        $ssn = (isset($_GET['ssn'])) ? $_GET['ssn'] : 0;

        $item = Item::individualCustomer();
        $item = $item->setNationalIdNumber($ssn)
                ->setEmail($order['email'])
                ->setName($order['payment_firstname'], $order['payment_lastname'])
                ->setStreetAddress($addressArr[1], $addressArr[2])
                ->setZipCode($order['payment_postcode'])
                ->setLocality($order['payment_city'])
                ->setIpAddress($order['ip'])
                ->setPhoneNumber($order['telephone']);

        if ($order["payment_iso_code_2"] == "DE" || $order["payment_iso_code_2"] == "NL") {

            $item = $item->setInitials($_GET['initials'])
                    ->setBirthDate($_GET['birthYear'], $_GET['birthMonth'], $_GET['birthDay']);
        }

        $svea = $svea->addCustomerDetails($item);
        try {
            $svea = $svea
                    ->setCountryCode($countryCode)
                    ->setCurrency($this->session->data['currency'])
                    ->setClientOrderNumber($this->session->data['order_id'])
                    ->setOrderDate(date('c'))
                    ->usePaymentPlanPayment($_GET["paySel"])
                    ->doRequest();
        } catch (Exception $e) {
            $this->log->write($e->getMessage());
            $response = array("error" => $this->responseCodes(0, $e->getMessage()));
            echo json_encode($response);
            exit();
        }


        //If response accepted redirect to thankyou page
        if ($svea->accepted == 1) {

             $sveaOrderAddress = $this->buildPaymentAddressQuery($svea,$countryCode,$order['comment']);

            if($this->config->get('svea_partpayment_shipping_billing') == '1')
                $sveaOrderAddress = $this->buildShippingAddressQuery($svea,$sveaOrderAddress,$countryCode);

            $this->model_payment_svea_invoice->updateAddressField($this->session->data['order_id'],$sveaOrderAddress);
            //If Auto deliver order is set, DeliverOrder

            if ($this->config->get('svea_partpayment_auto_deliver') === '1') {
                $deliverObj = WebPay::deliverOrder($conf);
                //Product rows
                try {
                    $deliverObj = $deliverObj
                            ->setCountryCode($countryCode)
                            ->setOrderId($svea->sveaOrderId)
                            ->deliverPaymentPlanOrder()
                            ->doRequest();
                } catch (Exception $e) {
                    $this->log->write($e->getMessage());
                    $response = array("error" => $this->responseCodes(0, $e->getMessage()));
                    echo json_encode($response);
                    exit();
                }

                //If DeliverOrder returns true, send true to veiw
                if ($deliverObj->accepted == 1) {
                    $response = array("success" => true);
                    //update order status for delivered
                    $this->model_checkout_order->confirm($this->session->data['order_id'], $this->config->get('svea_partpayment_deliver_status_id'), 'Svea contractNumber '.$deliverObj->contractNumber);
                    $this->db->query("UPDATE `" . DB_PREFIX . "order` SET date_modified = NOW(), comment = '".$sveaOrderAddress['comment']." | Order delivered. Svea contractNumber: ".$deliverObj->contractNumber."' WHERE order_id = '" . (int)$this->session->data['order_id'] . "'");
                    $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$this->session->data['order_id'] . "', order_status_id = '" . (int)$this->config->get('svea_partpayment_deliver_status_id') . "', notify = '" . 1 . "', comment = 'Order delivered. Svea contractNumber: " . $deliverObj->contractNumber . "', date_added = NOW()");
                    //I not, send error codes
                } else {
                    $response = array("error" => $this->responseCodes($deliverObj->resultcode, $deliverObj->errormessage));
                }
                //if auto deliver not set, send true to view
            } else {
                $response = array("success" => true);
                //update order status for created
                $this->model_checkout_order->confirm($this->session->data['order_id'], $this->config->get('svea_partpayment_order_status_id'),'Svea order id: '. $svea->sveaOrderId);
            }

            //else send errors to view
        } else {
            $response = array("error" => $this->responseCodes($svea->resultcode, $svea->errormessage));
        }
        echo json_encode($response);
    }

    private function getAddress($ssn) {

        include(DIR_APPLICATION . '../svea/Includes.php');

        $this->load->model('payment/svea_partpayment');
        $this->load->model('checkout/order');

        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $countryCode = $order['payment_iso_code_2'];
        //Testmode
        $conf = $this->config->get('svea_partpayment_testmode_' . $countryCode) == "1" ? new OpencartSveaConfigTest($this->config) : new OpencartSveaConfig($this->config);

        $svea = WebPay::getAddresses($conf)
                ->setOrderTypePaymentPlan()
                ->setCountryCode($countryCode);

        $svea = $svea->setIndividual($ssn);
        $result = array();
        try {
            $svea = $svea->doRequest();
        } catch (Exception $e) {
            $this->log->write($e->getMessage());
            $result = array("error" => $this->responseCodes(0, $e->getMessage()));
        }

        if ($svea->accepted != TRUE) {
            $result = array("error" => $svea->errormessage);
        } else {
            foreach ($svea->customerIdentity as $ci) {

                $name = ($ci->fullName) ? $ci->fullName : $ci->legalName;

                $result[] = array("fullName" => $name,
                    "street" => $ci->street,
                    "address_2" => $ci->coAddress,
                    "zipCode" => $ci->zipCode,
                    "locality" => $ci->locality);
            }
        }
        return $result;
        // echo json_encode($result);
    }


    /**
     * getPaymentOptions gets the available paymentmethods for this country and the order value and returns campaigns w/monthly cost
     *
     * @return array of array("campaignCode" => same, "description" => same , "price_per_month" => (string) price/month in selected currency)
     */

    private function getPaymentOptions() {
        include(DIR_APPLICATION . '../svea/Includes.php');
        $this->load->language('payment/svea_partpayment');
        $this->load->model('payment/svea_partpayment');
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $countryCode = $order['payment_iso_code_2'];

        $result = array();
        if ($this->config->get('svea_partpayment_testmode_' . $countryCode) !== NULL) {
            $svea = $this->model_payment_svea_partpayment->getPaymentPlanParams($countryCode);
        } else {
            $result = array("error" => $this->responseCodes(40001, "The country is not supported for this paymentmethod"));
            return $result;
        }

       if (sizeof($svea) < 1) {
            $result = array("error" => 'Svea error: '.$this->language->get('response_27000'));
        } else {
            $currency = floatval(VERSION) >= 1.5 ? $order['currency_code'] : $order['currency'];
            $this->load->model('localisation/currency');
            $currencies = $this->model_localisation_currency->getCurrencies();
            $decimals = "";
            foreach ($currencies as $key => $val) {
                if ($key == $currency) {
                    if($key == 'EUR'){
                        $decimals = 2;
                    }  else {
                        $decimals = 0;
                    }
                }
            }
            $formattedPrice = round($this->currency->format(($order['total']), '', false, false), 2);
            $campaigns = WebPay::paymentPlanPricePerMonth($formattedPrice, $svea);
            foreach ($campaigns->values as $cc)
                $result[] = array("campaignCode" => $cc['campaignCode'],
                    "description" => $cc['description'],
                    "price_per_month" => (string) round($cc['pricePerMonth'], $decimals) . " " . $currency . "/" . $this->language->get('month'));
        }

        return $result;
    }

    public function getAddressAndPaymentOptions() {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $countryCode = $order['payment_iso_code_2'];
        $paymentOptions = $this->getPaymentOptions();

        if ($countryCode == "SE" || $countryCode == "DK") { //|| $countryCode == "NO") {    // getAddresses() turned off for Norway oct'13
            $addresses = $this->getAddress($this->request->post['ssn']);
        } elseif ($countryCode != "SE" && $countryCode != "NO" && $countryCode != "DK" && $countryCode != "FI" && $countryCode != "NL" && $countryCode != "DE") {
            $addresses = array("error" => $this->responseCodes(40001, "The country is not supported for this paymentmethod"));
        } else {
            $addresses = array();
        }
        $result = array("addresses" => $addresses, "paymentOptions" => $paymentOptions);

        echo json_encode($result);
    }

    private function ShowErrorMessage($response = null) {
        $message = ($response !== null && isset($response->ErrorMessage)) ? $response->ErrorMessage : "Could not get any partpayment alternatives.";
        echo '$("#svea_partpayment_div").hide();
              $("#svea_partpayment_alt").hide();
              $("#svea_partpayment_err").show();
              $("#svea_partpayment_err").append("' . $message . '");
              $("a#checkout").hide();';
    }

    private function formatOrderRows($svea,$products,$currencyValue){
        $this->load->language('payment/svea_invoice');

        //Product rows
        foreach ($products as $product) {
          $item = Item::orderRow()
                ->setQuantity($product['quantity'])
                ->setName($product['name'])
                ->setUnit($this->language->get('unit'))
                ->setArticleNumber($product['model']);
//                ->setDescription($product['model'])//should be used for $product['option'] wich is array, but to risky because limit is String(40)


            //Get the tax, difference in version 1.4.x
            if (floatval(VERSION) >= 1.5) {
                $tax = $this->tax->getRates($product['price'], $product['tax_class_id']);
                $taxPercent = 0;
                $taxAmount = 0;
                foreach ($tax as $key => $value) {
                    $taxPercent = $value['rate'];
                    $taxAmount = $value['amount'];
                }
                $item = $item->setAmountIncVat(($product['price'] + $taxAmount) * $currencyValue)
                        ->setVatPercent(intval($taxPercent));//set amount inc vat is used for precision
            } else {
                $taxPercent = $this->tax->getRate($product['tax_class_id']);
                $item = $item->setAmountExVat($product['price'] * $currencyValue)
                        ->setVatPercent(intval($taxPercent));
            }

             $svea = $svea->addOrderRow($item);
        }
        return $svea;
    }

    public function addTaxRateToAddons() {
        //Get all addons
        $this->load->model('setting/extension');
        $total_data = array();
        $total = 0;
        $svea_tax = array();
        $cartTax = $this->cart->getTaxes();
        $results = $this->model_setting_extension->getExtensions('total');
        foreach ($results as $result) {
            //if this result is activated
            if ($this->config->get($result['code'] . '_status')) {
                $amount = 0;
                $taxes = array();
                foreach ($cartTax as $key => $value) {
                    $taxes[$key] = 0;
                }
                $this->load->model('total/' . $result['code']);

                $this->{'model_total_' . $result['code']}->getTotal($total_data, $total, $taxes);

                foreach ($taxes as $tax_id => $value) {
                    $amount += $value;
                }

                $svea_tax[$result['code']] = $amount;
            }
        }
        foreach ($total_data as $key => $value) {

            if (isset($svea_tax[$value['code']])) {
                if ($svea_tax[$value['code']]) {
                    $total_data[$key]['tax_rate'] = (int) round($svea_tax[$value['code']] / $value['value'] * 100); // round and cast, or may get i.e. 24.9999, which shows up as 25f in debugger & written to screen, but converts to 24i
                } else {
                    $total_data[$key]['tax_rate'] = 0;
                }
            } else {
                $total_data[$key]['tax_rate'] = '0';
            }
        }
        $ignoredTotals = 'sub_total, total, taxes';
        $ignoredOrderTotals = array_map('trim', explode(',', $ignoredTotals));
        foreach ($total_data as $key => $orderTotal) {
            if (in_array($orderTotal['code'], $ignoredOrderTotals)) {
                unset($total_data[$key]);
            }
        }
        return $total_data;
    }

    private function getLogo($countryCode) {

        switch ($countryCode) {
            case "SE": $country = "swedish";
                break;
            case "NO": $country = "norwegian";
                break;
            case "DK": $country = "danish";
                break;
            case "FI": $country = "finnish";
                break;
            case "NL": $country = "dutch";
                break;
            case "DE": $country = "german";
                break;
            default: $country = "english";
                break;
        }

        return $country;
    }

    //update order billingaddress
     private function buildPaymentAddressQuery($svea,$countryCode,$order_comment) {
         $countryId = $this->model_payment_svea_invoice->getCountryIdFromCountryCode(strtoupper($countryCode));
         $paymentAddress = array();

        if ($svea->customerIdentity->customerType == 'Company'){
                // no companies for partpayment
        }
        else {  // private customer

            isset($svea->customerIdentity->firstName) ? $paymentAddress["payment_firstname"] = $svea->customerIdentity->firstName : " ";
            isset($svea->customerIdentity->lastName) ? $paymentAddress["payment_lastname"] = $svea->customerIdentity->lastName : " ";

            // for private individuals, if firstName, lastName is not set in GetAddresses response, we put the entire getAddress LegalName in lastName
            if( (isset($svea->customerIdentity->firstName) == false) &&
                (isset($svea->customerIdentity->lastName) == false) &&
                (isset($svea->customerIdentity->fullName) == true) )
            {
                $paymentAddress["payment_firstname"] = " "; // using "" will cause form validation in admin to scream
                $paymentAddress["payment_lastname"] = $svea->customerIdentity->fullName;
            }

            $paymentAddress["payment_company"] = " ";

            isset($svea->customerIdentity->street) ? $paymentAddress["payment_address_1"] = $svea->customerIdentity->street : "";
            isset($svea->customerIdentity->coAddress) ? $paymentAddress["payment_address_2"] = $svea->customerIdentity->coAddress : "";
            isset($svea->customerIdentity->locality) ? $paymentAddress["payment_city"] = $svea->customerIdentity->locality : "";
            isset($svea->customerIdentity->zipCode) ? $paymentAddress["payment_postcode"] = $svea->customerIdentity->zipCode : "";
            $paymentAddress["payment_country_id"] = $countryId['country_id'];
            $paymentAddress["payment_country"] = $countryId['country_name'];
            $paymentAddress["payment_method"] = $this->language->get('text_title');
        }

        $paymentAddress["comment"] = $order_comment . "\nSvea order id: ".$svea->sveaOrderId;

        return $paymentAddress;
    }

    // update shipping address
    private function buildShippingAddressQuery($svea,$shippingAddress,$countryCode) {
        $countryId = $this->model_payment_svea_invoice->getCountryIdFromCountryCode(strtoupper($countryCode));

        if ($svea->customerIdentity->customerType == 'Company'){
                // no companies for partpayment
        }
        else {  // private customer
            isset($svea->customerIdentity->firstName) ? $shippingAddress["shipping_firstname"] = $svea->customerIdentity->firstName : "";
            isset($svea->customerIdentity->lastName) ? $shippingAddress["shipping_lastname"] = $svea->customerIdentity->lastName : "";

            // for private individuals, if firstName, lastName is not set in GetAddresses response, we put the entire getAddress LegalName in lastName
            if( (isset($svea->customerIdentity->firstName) == false) &&
                (isset($svea->customerIdentity->lastName) == false) &&
                (isset($svea->customerIdentity->fullName) == true) )
            {
                $shippingAddress["shipping_firstname"] = " "; // using "" will cause form validation in admin to scream
                $shippingAddress["shipping_lastname"] = $svea->customerIdentity->fullName;
            }

            $shippingAddress["shipping_company"] = " ";

            isset($svea->customerIdentity->street) ? $shippingAddress["shipping_address_1"] = $svea->customerIdentity->street : "";
            isset($svea->customerIdentity->coAddress) ? $shippingAddress["shipping_address_2"] = $svea->customerIdentity->coAddress : "";
            isset($svea->customerIdentity->locality) ? $shippingAddress["shipping_city"] = $svea->customerIdentity->locality : "";
            isset($svea->customerIdentity->zipCode) ? $shippingAddress["shipping_postcode"] = $svea->customerIdentity->zipCode : "";
            $shippingAddress["shipping_country_id"] = $countryId['country_id'];
            $shippingAddress["shipping_country"] = $countryId['country_name'];
        }

        return $shippingAddress;
    }
}
?>
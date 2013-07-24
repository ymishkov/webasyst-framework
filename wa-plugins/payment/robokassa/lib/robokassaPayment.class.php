<?php

/**
 * @property-read string $merchant_login
 * @property-read string $merchant_pass1
 * @property-read string $merchant_pass2
 * @property-read string $locale
 * @property-read string $testmode
 * @property-read string $gateway_currency
 * @property-read string $merchant_currency
 *
 *
 */
class robokassaPayment extends waPayment implements waIPayment
{
    private $url = 'https://auth.robokassa.ru/Merchant/Index.aspx';
    private $test_url = 'http://test.robokassa.ru/Index.aspx';

    private $order_id;

    protected function initControls()
    {
        $this->registerControl('GatewayCurrency');
    }

    public function allowedCurrency()
    {

        return $this->merchant_currency ? $this->merchant_currency : 'RUB';
    }

    public static function settingGatewayCurrency($name, $params = array())
    {
        $default = array(
            'title_wrapper'       => false,
            'description_wrapper' => false,
            'control_wrapper'     => '%s%s%s',
        );
        $params = array_merge($params, $default);
        $options = array();
        if (extension_loaded('curl') && ($ch = curl_init())) {

            $data = array();
            $data['Language'] = 'ru';

            if (!empty($params['instance']) && ($params['instance'] instanceof self)) {
                $data['MerchantLogin'] = $params['instance']->merchant_login;
                if ($params['instance']->testmode) {
                    $url = 'http://test.robokassa.ru/Webservice/Service.asmx/GetCurrencies?';
                } else {
                    $url = 'http://merchant.roboxchange.com/WebService/Service.asmx/GetCurrencies?';
                }
            }
            if (empty($data['MerchantLogin'])) {
                $data['MerchantLogin'] = 'demo';
                $url = 'http://test.robokassa.ru/Webservice/Service.asmx/GetCurrencies?';
            }

            $url .= 'MerchantLogin='.$data['MerchantLogin'];
            $url .= '&Language='.$data['Language'];

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

            if ((version_compare(PHP_VERSION, '5.4', '>=') || !ini_get('safe_mode')) && !ini_get('open_basedir')) {
                //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                ;
            }

            $http_response = curl_exec($ch);

            if (!$http_response) {
                self::log(preg_replace('/payment$/', '', strtolower(__CLASS__)), array(
                    'error' => curl_errno($ch).':'.curl_error($ch),
                ));
                curl_close($ch);
                return $default;
            } else {

                if ($content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE)) {
                    if (preg_match('/charset=[\'"]?([a-z\-0-9]+)[\'"]?/i', $content_type, $matches)) {
                        $charset = strtolower($matches[1]);
                        if (!in_array($charset, array('utf-8', 'utf8'))) {
                            $http_response = iconv($charset, 'utf-8', $http_response);
                        }
                    }
                }
                curl_close($ch);

                if ($xml = @simplexml_load_string($http_response)) {
                    if ($code = (int) $xml->Result->Code) {
                        self::log(preg_replace('/payment$/', '', strtolower(__CLASS__)), array(
                            'url'   => $url,
                            'error' => $code.': '.(string) $xml->Result->Description,
                            'xml'   => $http_response,
                        ));
                        $options[] = array(
                            'title' => (string) $xml->Result->Description,
                            'value' => null,
                            'group' => 'Ошибка получения списка валют',
                        );
                    } else {
                        foreach ($xml->Groups as $xml_group) {
                            foreach ($xml_group->Group as $xml_group_item) {
                                foreach ($xml_group_item->Items as $xml_items) {
                                    foreach ($xml_items as $xml_item) {
                                        $options[] = array(
                                            'title' => (string) $xml_group_item['Description'].' — '.(string) $xml_item['Name'],
                                            'value' => (string) $xml_item['Label'],
                                            'group' => (string) $xml_group_item['Code'],
                                        );
                                    }
                                }
                            }
                        }
                    }
                } else {
                    self::log(preg_replace('/payment$/', '', strtolower(__CLASS__)), array(
                        'error' => 'Invalid service response',
                        'xml'   => $http_response,
                    ));
                }
            }

        } else {
            //TODO
            ;
        }
        $params['options'] = $options;
        return waHtmlControl::getControl(waHtmlControl::SELECT, $name, $params);
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $order = waOrder::factory($order_data);
        $description = preg_replace('/[^\.\?,\[]\(\):;"@\\%\s\w\d]+/', ' ', $order->description);
        $description = preg_replace('/[\s]{2,}/', ' ', $description);
        $form_fields = array();
        $form_fields['MrchLogin'] = $this->merchant_login;
        $form_fields['OutSum'] = number_format($order->total, 2, '.', '');
        $form_fields['InvId'] = $order->id;
        $hash_string = implode(':', $form_fields).':'.$this->merchant_pass1;
        $hash_string .= ':shp_wa_app_id='.$this->app_id;
        $hash_string .= ':shp_wa_merchant_id='.$this->merchant_id;

        $form_fields['SignatureValue'] = md5($hash_string);
        $form_fields['Desc'] = mb_substr($description, 0, 100, "UTF-8");
        $form_fields['IncCurrLabel'] = $this->gateway_currency;
        $form_fields['Culture'] = $this->locale;

        $form_fields['shp_wa_app_id'] = $this->app_id;
        $form_fields['shp_wa_merchant_id'] = $this->merchant_id;

        $view = wa()->getView();

        $view->assign('form_fields', $form_fields);
        $view->assign('form_url', $this->getEndpointUrl());
        $view->assign('auto_submit', $auto_submit);

        return $view->fetch($this->path.'/templates/payment.html');
    }

    protected function callbackInit($request)
    {
        $pattern = '/^([a-z]+)_(.+)$/';
        if (!empty($request['InvId']) && intval($request['InvId'])) {
            $this->app_id = ifempty($request['shp_wa_app_id']);
            $this->merchant_id = ifempty($request['shp_wa_merchant_id']);
            $this->order_id = intval($request['InvId']);
        } elseif (!empty($request['app_id'])) {
            $this->app_id = $request['app_id'];
        }
        return parent::callbackInit($request);
    }

    public function callbackHandler($request)
    {
        $transaction_data = $this->formalizeData($request);
        $transaction_result = ifempty($request['transaction_result'], 'success');

        $url = null;
        $app_payment_method = null;

        switch ($transaction_result) {
            case 'result':
                $this->verifySign($request);
                $app_payment_method = self::CALLBACK_PAYMENT;
                $transaction_data['state'] = self::STATE_CAPTURED;
                break;
            case 'success':
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
                break;
            case 'failure':
                if ($this->order_id && $this->app_id) {
                    $app_payment_method = self::CALLBACK_CANCEL;
                    $transaction_data['state'] = self::STATE_CANCELED;
                }
            default:
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                break;
        }

        $transaction_data = $this->saveTransaction($transaction_data, $request);
        if ($app_payment_method) {
            $result = $this->execAppCallback($app_payment_method, $transaction_data);
            self::addTransactionData($transaction_data['id'], $result);

        }
        if ($transaction_result == 'result') {
            echo 'OK'.$this->order_id;
            return array(
                'template' => false,
            );
        } else {
            if ($url) {
                return array(
                    'redirect' => $url,
                );
            } else {
                return array(
                    'template' => $this->path.'/templates/callback.html',
                    'back_url' => $url,
                    'message'  => $message,
                );
            }
        }
    }

    protected function formalizeData($transaction_raw_data)
    {
        $transaction_data = parent::formalizeData($transaction_raw_data);
        $transaction_data['native_id'] = $this->order_id;
        $transaction_data['order_id'] = $this->order_id;
        $transaction_data['amount'] = ifempty($transaction_raw_data['OutSum'], '');
        $transaction_data['currency_id'] = $this->merchant_currency;
        return $transaction_data;
    }

    private function getEndpointUrl()
    {
        return $this->testmode ? $this->test_url : $this->url;
    }

    private function verifySign($request)
    {
        if ($this->merchant_pass2) {
            $hash_string = ifempty($request['OutSum'], '').':'.ifempty($request['InvId'], '').':'.$this->merchant_pass2;
            $hash_string .= ':shp_wa_app_id='.$this->app_id;
            $hash_string .= ':shp_wa_merchant_id='.$this->merchant_id;
            $sign = strtolower(md5($hash_string));
            $server_sign = strtolower(ifempty($request['SignatureValue'], ''));
            if (empty($server_sign) || ($server_sign != $sign)) {
                throw new waPaymentException('Invalid sign');
            }
        }
    }
}

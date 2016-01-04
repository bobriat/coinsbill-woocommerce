<?php
/*
Plugin Name: WooCommerce CoinsBill Payment Gateway
Plugin URI: https://www.coinsbill.com/
Description: CoinsBill Payment gateway for woocommerce
Version: 1.0
Author: CoinsBill
Author URI: https://www.coinsbill.com/
*/
add_action('plugins_loaded', 'woocommerce_coinsbill_payment_init', 0);
function woocommerce_coinsbill_payment_init(){
  if(!class_exists('WC_Payment_Gateway')) return;

  class WC_CoinsBill_Payment extends WC_Payment_Gateway{
    public function __construct(){
      $this -> id = 'coinsbill';
      $this -> medthod_title = 'CoinsBill';
      $this -> has_fields = false;

      $this -> init_form_fields();
      $this -> init_settings();

      $this -> title = $this -> settings['title'];
      $this -> description = $this -> settings['description'];
     
      $this -> redirect_page_id = $this -> settings['redirect_page_id'];
      $this -> liveurl = 'https://www.coinsbill.com/api/invoice/';
      $this -> icon_path = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/coinsbill.png';
      $this -> icon_enable = $this->settings['icon'];
      $this -> apikey = $this->settings['apikey'];
      $this -> callback = $this->settings['callback'];
      $this -> email = $this->settings['email'];

      $this -> msg['message'] = "";
      $this -> msg['class'] = "";

      add_action('woocommerce_thankyou', function()
            {
                $returnStatus = $_GET["coinsbill-status"];
                $doit         = true;
                if (strcmp($returnStatus, "true") == 0) {
                    $doit = false;
                } elseif (strcmp($returnStatus, "received") == 0) {
                    $coinsbill_thanks_title = "Your Order Has Not Been Processed Yet!";
                    $coinsbill_thanks_msg   = "Your order has not been successfully processed yet! We received your payment, but we are waiting for confirmation. You will be notified by email.";
                } elseif (strcmp($returnStatus, "cancel") == 0) {
                    $coinsbill_thanks_title = "Your Order Has Been Cancelled!";
                    $coinsbill_thanks_msg   = "Your order has been cancelled at CoinsBill payment gate! You may place a new one.";
                } else {
                    $coinsbill_thanks_title = "Your Order Has Not Been Processed!";
                    $coinsbill_thanks_msg   = "Your order has not been successfully processed!";
                }
                if ($doit) {
                    echo "<script>
            var eltitle = document.querySelector(\".entry-header .entry-title\");
            eltitle.innerHTML = \"$coinsbill_thanks_title\";
            var eltext = document.querySelector(\".entry-content .woocommerce p:first-child\");
            eltext.innerHTML = \"$coinsbill_thanks_msg\";
            </script>";
                }
            });
            add_action('woocommerce_api_' . strtolower(get_class($this)), array(
                &$this,
                'handle_callback'
            ));

      if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
             } else {
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
            }

       if (strlen($this->apikey) == 0 ) {
                static $count = 0;
                $count++;
                $this->enabled             = 'no';
                $this->settings['enabled'] = 'no';
                if ($count > 1)
                    $this->errors[] = "Payment gateway has been disabled!";
            }
      # add_action('woocommerce_receipt_coinsbill', array(&$this, 'receipt_page'));
   }

   // custom link and icons
    public function get_icon()
        {
            if (strcmp($this->icon_enable, 'yes') == 0)
                $icon_html = "<img src=\"{$this->icon_path}\" width=150 alt=\"CoinsBill\">";
            else
                $icon_html = '';
            $icon_html .= '<a href="#" onclick="window.open(\'https://coinsbill.com\')" style="float: right;line-height: 52px;font-size: .83em;" title="What is CoinsBill" target="_blank">What is CoinsBill?</a>';
            return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
        }
        // validation functions
    function validate_apikey_field($key)
        {
            static $count = 0;
            $count++;
            // get the posted value
            $value = $_POST[$this->plugin_id . $this->id . '_' . $key];
            if (isset($value) && 30 != strlen($value)) {
                if ($count > 1)
                    return "";
                else
                    $this->errors[] = "Your API key is not VALID!";
            }
            return $value;
        }

    public function display_errors()
        {
            // loop through each error and display it
            foreach ($this->errors as $key => $value) {
                echo '<div class="error"><p>';
                _e($value, 'coinsbill-error');
                echo '</p></div>';
            }
            unset($this->errors);
        }

    // callback function
        function handle_callback()
        {
            $inputData   = file_get_contents('php://input');
            $payResponse = json_decode($inputData);
            
            $security = 1;
            
            // payment status
            $paymentStatus = $payResponse->status;
            // order id
            $preOrderId = json_decode($payResponse->reference);
            $orderId    = $preOrderId->order_number;
            // confirmation process
            $order = new WC_Order($orderId);
            if ($security) {
                if ($paymentStatus != NULL) {
                    error_log($paymentStatus);
                    switch ($paymentStatus) {
                        case 'confirmed':
                            $order->update_status('processing', __('CoinsBill Payment processing', 'coinsbill'));
                            break;
                        case 'unpaid':
                            $order->update_status('pending', __('CoinsBill  Payment pending', 'coinsbill'));
                            break;
                        case 'paid':
                            $order->update_status('pending', __('CoinsBill  Payment received but still pending', 'coinsbill'));
                            break;
                        case 'insufficient_amount':
                            $order->update_status('failed', __('CoinsBill  Payment failed. Insufficient amount', 'coinsbill'));
                            break;
                        case 'invalid':
                            $order->update_status('cancelled', __('CoinsBill  Payment failed. Invalid', 'coinsbill'));
                            break;
                        case 'expired':
                            $order->update_status('cancelled', __('CoinsBill  Payment failed. Timeout', 'coinsbill'));
                            break;
                        case 'refund':
                            $order->update_status('refunded', __('CoinsBill  Payment refunded', 'coinsbill'));
                            break;
                        case 'paid_after_timeout':
                            $order->update_status('failed', __('CoinsBill  Payment failed. Paid after timeout', 'coinsbill'));
                            break;
                    }
                }
            }
        }

    function init_form_fields(){

       $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'coinsbill'),
                    'type' => 'checkbox',
                    'label' => __('Enable CoinsBill Payment Module.', 'coinsbill'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'coinsbill'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'coinsbill'),
                    'default' => __('CoinsBill', 'coinsbill'),
                    'desc_tip' => true
                    ),

                'description' => array(
                    'title' => __('Description:', 'coinsbill'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'coinsbill'),
                    'default' => __('Pay securely by Bitcoin  through CoinsBill Secure Servers.', 'coinsbill')),
               

                'icon' => array(
                    'title' => __('Frontend icon', 'coinsbill'),
                    'type' => 'checkbox',
                    'label' => __('Display Bitcoin icon in frontend.', 'coinsbill'),
                    'default' => 'no'
                ),
                'apikey' => array(
                    'title' => __(' API key:', 'coinsbill'),
                    'type' => 'text',
                    'description' => __('API key is used for backed authentication and you should keep it private. You will find your API key in your account under settings > API', 'coinsbill'),
                    'desc_tip' => true
                ),

                'redirect_page_id' => array(
                    'title' => __('Return Page'),
                    'type' => 'select',
                    'options' => $this -> get_pages('Select Page'),
                    'description' => "URL of success page"
                )
            );
    }

       public function admin_options(){
        echo '<h3>'.__('CoinsBill Payment Gateway', 'coinsbill').'</h3>';
        echo '<p>'.__('CoinsBill is most popular Bitcoin Payment Gateway').'</p>';
        echo '<table class="form-table">';
        // Generate the HTML For the settings form.
        $this -> generate_settings_html();
        echo '</table>';

    }

    /**
     *  There are no payment fields for coinsbill but we want to show the description if set.
     **/
    function payment_fields()
        {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }
        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            // gate logic start
            // Getting API-ID from config
            $apiID = $this->settings['apikey'];
            // test mode check
            $testMode = 0; // if set to 1, test mode will be set
            if (!$testMode) {
                $payurl = 'https://www.coinsbill.com/api/invoice/';
            } else {
                $payurl = 'https://www.coinsbill.com/api/invoice/';
            }
            // data preparation
            $order_id = $order_id;
            $price    = $order->get_total();
            $order_items = $order->get_items();
            $fname    = $order->billing_first_name;
            $lname    = $order->billing_last_name;
            $name     = "{$bcp_fname} {$bcp_lname}";
            $email    = $order->billing_email;
            // $currency = get_woocommerce_currency();
            $currency = "USD";

            // data finalize
            $customData  = array(
                'billing_first_name' => $fname,
                'billing_first_name' => $fname,
                # 'order_number' => intval($order_id),
                'email' => $email
            );
            $jCustomData = json_encode($customData);
            $notiEmail = $this->settings['email'];
            $lang      = "";
            $settCurr  = $this->settings['payout'];
            if (strlen($settCurr) != 3) {
                $settCurr = "BTC";
            }
            $callback_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'wc_coinsbill_payment', home_url('/')));
            $return_url   = $this->get_return_url($order);
            $postData = array(
                # 'settled_currency' => $settCurr,
                'callback_url' => $return_url,
                'server_ipn_url' => $callback_url,
                # 'price' => floatval($bcp_price),
                'currency' => $currency,
                'billing_first_name' => $fname,
                'billing_first_name' => $fname,
                # 'order_number' => intval($order_id),
                'email' => $email,
                # 'reference' => json_decode($jCustomData)
            );

            foreach($order->get_items() as $item) 
              {
                $line_sub_total = $item['line_subtotal'];
                $line_sub_tax = $item['line_tax'];
                $item_value = round($line_sub_total / $qty, 2);
                $item_tax = round($line_sub_tax / $qty, 2);
                $item_total = $item_value + $item_tax;
                $line_total = $line_sub_total + $line_sub_tax;


                 $postData['items'][] = array (
                      'unit_price' => $line_total, // Your SKU
                      'name' => $item['name'], // Here appear the name of the product
                      'quantity' => $item['qty'], // here the quantity of the product
                 );
              }


            if (($notiEmail !== NULL) && (strlen($notiEmail) > 5)) {
                $postData['notify_email'] = $notiEmail;
            }
            if ((strcmp($lang, "cs") !== 0) || (strcmp($lang, "en") !== 0) || (strcmp($lang, "de") !== 0)) {
                $postData['lang'] = "en";
            } else {
                $postData['lang'] = $lang;
            }
            $content = json_encode($postData);
            // sending data via cURL
            $curlheaders = array(
                "Content-type: application/json",
                "Authorization: Bearer {$apiID}"
            );
            $curl        = curl_init($payurl);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_VERBOSE, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $curlheaders);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // bypassing ssl verification, because of bad compatibility
            curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
            // sending to server, and waiting for response
            $response = curl_exec($curl);
            $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $jHeader     = substr($response, 0, $header_size);
            $jBody       = substr($response, $header_size);
            $jHeaderArr = $this->get_headers_from_curl_response($jHeader);
            // http response code
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            // callback password check

            define('WP_DEBUG', false);
            error_reporting(0);
            @ini_set('display_errors', 0);
            
            $security = 1;
            if ($status != 200) {
                die("Error: call to URL {$payurl} failed with status {$status}, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl) . "<br /> Please contact shop administrator...");
                curl_close($curl);
            } elseif (!$security) {
                die("Error: Callback password does not match! <br />Please contact shop administrator...");
                curl_close($curl);
            } else {
                curl_close($curl);
                $response         = json_decode($jBody);
                // adding paymentID to payment method
                $BCPPaymentId     = $response->data->orderId;
                $bcp_pre_inv      = "https://www.coinsbill.com/api/invoice/" . $BCPPaymentId;
                $BCPInvoiceUrl    = "<br><strong>BitcoinPay Invoice: </strong><a href=\"" . $bcp_pre_inv . "\" target=\"_blnak\">" . $bcp_pre_inv . "</a>";
                // $prePaymentMethod = html_entity_decode($order_info['payment_method'], ENT_QUOTES, 'UTF-8');
                $finPaymentMethod = "<strong>PaymentID: </strong>" . $BCPPaymentId . $BCPInvoiceUrl;
                // redirect to pay gate
                $paymentUrl = $response->data->invoice_url;
                $order->add_order_note(__($finPaymentMethod, 'coinsbill'));
                // Mark as on-hold (we're awaiting the cheque)
                $order->update_status('pending', __('BCP Payment pending', 'coinsbill'));
                // Reduce stock levels
                $order->reduce_order_stock();
                // Remove cart
                $woocommerce->cart->empty_cart();
                // Return thankyou redirect
                return array(
                    'result' => 'success',
                    'redirect' => $paymentUrl
                );
            }
        }
        private function get_headers_from_curl_response($headerContent)
        {
            $headers = array();
            // Split the string on every "double" new line.
            $arrRequests = explode("\r\n\r\n", $headerContent);
            // Loop of response headers. The "count() -1" is to
            // avoid an empty row for the extra line break before the body of the response.
            for ($index = 0; $index < count($arrRequests) - 1; $index++) {
                foreach (explode("\r\n", $arrRequests[$index]) as $i => $line) {
                    if ($i === 0)
                        $headers[$index]['http_code'] = $line;
                    else {
                        list($key, $value) = explode(': ', $line);
                        $headers[$index][$key] = $value;
                    }
                }
            }
            return $headers;
        }
        function showMessage($content)
        {
            return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
        }
     // get all pages
    function get_pages($title = false, $indent = true) {
        $wp_pages = get_pages('sort_column=menu_order');
        $page_list = array();
        if ($title) $page_list[] = $title;
        foreach ($wp_pages as $page) {
            $prefix = '';
            // show indented child pages?
            if ($indent) {
                $has_parent = $page->post_parent;
                while($has_parent) {
                    $prefix .=  ' - ';
                    $next_page = get_page($has_parent);
                    $has_parent = $next_page->post_parent;
                }
            }
            // add to page list array array
            $page_list[$page->ID] = $prefix . $page->post_title;
        }
        return $page_list;
    }
}

  
  class coinsbill_handle_callback
    {
        public function __construct()
        {
        }
    }

   /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_coinsbill_payment_gateway($methods) {
        $methods[] = 'WC_CoinsBill_Payment';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_coinsbill_payment_gateway' );
}

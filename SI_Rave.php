<?php

class SI_Rave extends SI_Offsite_Processors
{
    const MODE_TEST = 'test';
    const MODE_LIVE = 'live';
    
    const COUNTRY_NIGERIA = 'NG';
    const COUNTRY_KENYA = 'KE';
    const COUNTRY_GHANA = 'GH';
    const COUNTRY_SOUTHAFRICA = 'ZA';

    const MODAL_JS_OPTION = 'si_use_rave_js_modal';
    const DISABLE_JS_OPTION = 'si_use_rave_js';
    const API_SECRET_KEY_OPTION = 'si_rave_secret_key';
    const API_SECRET_KEY_TEST_OPTION = 'si_rave_secret_key_test';
    const LOGO_URL = 'si_rave_logo';
    const API_PUB_KEY_OPTION = 'si_rave_pub_key';
    const API_PUB_KEY_TEST_OPTION = 'si_rave_pub_key_test';

    const API_MODE_OPTION = 'si_rave_mode';
    const CURRENCY_CODE_OPTION = 'si_rave_currency';
    const COUNTRY_OPTION = 'si_rave_country';
    const PAYMENT_METHOD = 'Rave (Secured by Flutterwave)';
    const PAYMENT_SLUG = 'rave';
    const TOKEN_KEY = 'si_token_key'; // Combine with $blog_id to get the actual meta key
    const PAYER_ID = 'si_payer_id'; // Combine with $blog_id to get the actual meta key


    const UPDATE = 'rave_version_upgrade_v1';

    protected static $instance;
    protected static $api_mode = self::MODE_TEST;
    private static $payment_modal;
    private static $disable_rave_js;
    private static $api_secret_key_test;
    private static $logo_url;
    private static $api_pub_key_test;
    private static $api_secret_key;
    private static $api_pub_key;
    private static $currency_code = 'NGN';
    private static $country_code = 'NG';
    private static $stagingUrl = 'https://ravesandboxapi.flutterwave.com';
    private static $liveUrl = 'https://api.ravepay.co';
    private static $requeryCount = 0;

    public static function get_instance() 
    {
        if (! (isset(self::$instance) && is_a(self::$instance, __CLASS__))) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function is_test() 
    {
        return self::MODE_TEST === self::$api_mode;
    }

    public function get_payment_method() 
    {
        return self::PAYMENT_METHOD;
    }

    public function get_slug() 
    {
        return self::PAYMENT_SLUG;
    }

    public static function register() 
    {

        // Register processor
        self::add_payment_processor(__CLASS__, __('Rave (Secured By Flutterwave)', 'sprout-invoices'));

        // Enqueue Scripts
        if (apply_filters('si_remove_scripts_styles_on_doc_pages', '__return_true')) {
            // enqueue after enqueue is filtered
            add_action('si_doc_enqueue_filtered', array( __CLASS__, 'enqueue'));
        }
        else { // enqueue normal
            add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
        }

        // add_action( 'si_head', array( __CLASS__, 'si_add_stylesheet' ), 100 );

        // Add Recurring button
        add_action('recurring_payments_profile_info', array( __CLASS__, 'rave_profile_link'));
    }

    public static function public_name() 
    {
        return __('Rave (Secured By Flutterwave)', 'sprout-invoices');
    }

    public static function checkout_options() 
    {
        $option = array(
			'icons' => array( SI_URL . '/resources/front-end/img/paypal.png' ),
			'label' => __( 'Pay with Rave (Secured By Flutterwave)', 'sprout-invoices' ),
			'cc' => array(),
			);
		return apply_filters( 'si_paypal_ec_checkout_options', $option );
    }

    protected function __construct() 
    {
        parent::__construct();
        self::$api_mode = get_option(self::API_MODE_OPTION, self::MODE_TEST);
        self::$payment_modal = get_option(self::MODAL_JS_OPTION, true);
        self::$disable_rave_js = get_option(self::DISABLE_JS_OPTION, false);
        self::$currency_code = get_option(self::CURRENCY_CODE_OPTION, 'NGN');
        self::$country_code = get_option(self::COUNTRY_OPTION, 'NG');

        self::$api_secret_key = get_option(self::API_SECRET_KEY_OPTION, '');
        self::$api_pub_key = get_option(self::API_PUB_KEY_OPTION, '');
        self::$api_secret_key_test = get_option(self::API_SECRET_KEY_TEST_OPTION, '');
        self::$logo_url = get_option(self::LOGO_URL, '');
        self::$api_pub_key_test = get_option(self::API_PUB_KEY_TEST_OPTION, '');

		add_action( 'si_checkout_action_'.SI_Checkouts::PAYMENT_PAGE, array( $this, 'send_offsite' ), 0, 1 );
		add_action( 'si_checkout_action_'.SI_Checkouts::REVIEW_PAGE, array( $this, 'back_from_rave' ), 0, 1 );

        // Remove pages
        add_filter('si_checkout_pages', array( $this, 'remove_checkout_pages' ));

        if (! self::$disable_rave_js ) {
            add_filter('si_valid_process_payment_page_fields', '__return_false');
        }
    }

    /**
     * The review page is unnecessary
     *
     * @param  array $pages
     * @return array
     */
    public function remove_checkout_pages( $pages ) 
    {
        unset($pages[ SI_Checkouts::REVIEW_PAGE ]);
        return $pages;
    }

    /**
     * Hooked on init add the settings page and options.
     */
    public static function register_settings($settings = array()) 
    {
        // Settings
        $settings['payments'] = array(
            'si_rave_settings' => array(
                'title' => __('Rave Settings', 'sprout-invoices'),
                'weight' => 200,
                'tab' => self::get_settings_page(false),
                'settings' => array(
                    self::API_MODE_OPTION => array(
                        'label' => __('Mode', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'radios',
                            'options' => array(
                                self::MODE_LIVE => __('Live', 'sprout-invoices'),
                                self::MODE_TEST => __('Test', 'sprout-invoices'),
                            ),
                        'default' => self::$api_mode,
                        )
                    ),
                    self::API_PUB_KEY_OPTION => array(
                        'label' => __('Live Public Key - Get <a target="_blank" href="https://rave.flutterwave.com/dashboard/settings/apis">here</a>', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$api_pub_key,
                        )
                    ),
                    self::API_SECRET_KEY_OPTION => array(
                        'label' => __('Live Secret Key - Get <a target="_blank" href="https://rave.flutterwave.com/dashboard/settings/apis">here</a>', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$api_secret_key,
                        )
                    ),
                    self::API_PUB_KEY_TEST_OPTION => array(
                        'label' => __('Test Public Key -  Get <a target="_blank" href=https://ravesandbox.flutterwave.com/dashboard/settings/apis">here</a>', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$api_pub_key_test,
                        )
                    ),
                    self::API_SECRET_KEY_TEST_OPTION => array(
                        'label' => __('Test Secret Key -  Get <a target="_blank" href=https://ravesandbox.flutterwave.com/dashboard/settings/apis">here</a>', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$api_secret_key_test,
                        )
                    ),
                    self::LOGO_URL => array(
                        'label' => __('Logo URL (Square preferably)', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$logo_url,
                        )
                    ),
                    self::CURRENCY_CODE_OPTION => array(
                        'label' => __('Currency Code', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$currency_code,
                            'attributes' => array( 'class' => 'small-text' )
                        )
                    ),
                    self::COUNTRY_OPTION => array(
                        'label' => __('Country Code', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'select',
                            'options' => array(
                                self::COUNTRY_NIGERIA => __('Nigeria', 'sprout-invoices'),
                                self::COUNTRY_KENYA => __('Kenya', 'sprout-invoices'),
                                self::COUNTRY_GHANA => __('Ghana', 'sprout-invoices'),
                                self::COUNTRY_SOUTHAFRICA => __('South Africa', 'sprout-invoices')
                                ),
                            'default' => self::$country_code,
                        ),
                    )
                )
            )
        );
        // do_action('sprout_settings', $settings, self::SETTINGS_PAGE);
        return $settings;
    }

    ///////////////////
    // Payment Modal //
    ///////////////////

    /**
	 * Instead of redirecting to the SIcheckout page,
	 * set up Rave and redirect there
	 *
	 * @param SI_Carts $cart
	 * @return void
	 */
	public function send_offsite( SI_Checkouts $checkout ) {
        $invoice = $checkout->get_invoice();
        $invoice_id = $invoice->get_id();
        $user = si_who_is_paying($invoice);
        $user_email = ( $user ) ? $user->user_email : '' ;

        $PBFPubKey = ( self::$api_mode === self::MODE_TEST ) ? self::$api_pub_key_test : self::$api_pub_key ;
        $secretKey = ( self::$api_mode === self::MODE_TEST ) ? self::$api_secret_key_test : self::$api_secret_key ;
        $baseUrl = ( self::$api_mode === self::MODE_TEST ) ? self::$stagingUrl : self::$liveUrl ;
        $payment_amount = ( si_has_invoice_deposit($invoice->get_id()) ) ? $invoice->get_deposit() : $invoice->get_balance();

        $strippedAmount = $payment_amount + 0;
        $currencyCode = self::get_currency_code($invoice_id);

        $postfields = array();
        $postfields['PBFPubKey'] = $PBFPubKey;
        $postfields['customer_email'] = $user_email;
        $postfields['custom_logo'] = self::$logo_url;
        $postfields['customer_phone'] = $phone;
        $postfields['txref'] = $invoice_id . '_' . time();
        $paymentMethod = "card";
        $postfields['redirect_url'] = $checkout->checkout_complete_url( $this->get_slug() );
        $postfields['hosted_payment'] = 1;
        if ($currencyCode == "NGN") {
            $paymentMethod = "card,account,qr";
        } elseif($currencyCode == "GHS") {
            $paymentMethod = "card,mobilemoneyghana";
        } elseif($currencyCode == "USD") {
            $paymentMethod = "card,account";
        } elseif($currencyCode == "EUR") {
            $paymentMethod = "card";
        } elseif($currencyCode == "KES") {
            $paymentMethod = "card,mpesa";
        } else {
            $paymentMethod = "card";
        }
        $postfields['payment_options'] = $paymentMethod;
        $postfields['amount'] = $strippedAmount;
        $postfields['currency'] = $currencyCode;

        ksort($postfields);
        $stringToHash = "";
        foreach ($postfields as $key => $val) {
            $stringToHash .= $val;
        }

        $stringToHash .= $secretKey;
        $hashedValue = hash('sha256', $stringToHash);

        $transactionData = array_merge($postfields, array('integrity_hash' => $hashedValue));
        $json = json_encode($transactionData);
        $datas = "";
        foreach ($transactionData as $key => $value) {
            $datas.= $key.": '". $value."',";
        }
        
        $htmlOutput = "
        <script type='text/javascript' src='" . $baseUrl . "/flwv3-pug/getpaidx/api/flwpbf-inline.js'></script>
        <script>
            document.addEventListener('DOMContentLoaded', function pay() {
            var data = JSON.parse('" . json_encode($transactionData = array_merge($postfields, array('integrity_hash' => $hashedValue))) . "');
            getpaidSetup(data);}, false);
            console.log('wole');
        </script>
        ";
        echo $htmlOutput;
        die();
    }

    /**
	 * Process a payment
	 *
	 * @param SI_Checkouts $checkout
	 * @param SI_Invoice $invoice
	 * @return SI_Payment|bool false if the payment failed, otherwise a Payment object
	 */
	public function process_payment( SI_Checkouts $checkout, SI_Invoice $invoice ) {
        $invoiceId = explode('_', $_GET["txref"]);
        $invoiceId = $invoiceId[0];
        $transactionId = $_GET["txref"];


        print_r($request);
        die();
        if (isset($_GET['txref'])) {
            return self::requery($checkout, $_GET['txref']);
        }

    }

    /**
	 * We're on the checkout page, just back from PayPal.
	 * Store the token and payer ID that PayPal gives us
	 *
	 * @return void
	 */
	public function back_from_rave( SI_Checkouts $checkout ) {
		$invoiceId = explode('_', $_GET["txref"]);
        $invoiceId = $invoiceId[0];
        $transactionId = $_GET["txref"];
        if (isset($_GET['txref'])) {
            return self::requery($checkout, $_GET['txref']);
        }
	}


    

    private static function get_currency_code( $invoice_id ) 
    {
        return apply_filters('si_currency_code', self::$currency_code, $invoice_id, self::PAYMENT_METHOD);
    }


    //////////////
    // Utility //
    //////////////

    /**
     * Grabs error messages from a Rave response and displays them to the user
     *
     * @param  array $response
     * @param  bool  $display
     * @return void
     */
    private function set_error_messages( $message, $display = true ) 
    {
        if ($display ) {
            self::set_message($message, self::MESSAGE_STATUS_ERROR);
        } else {
            do_action('si_error', __CLASS__ . '::' . __FUNCTION__ . ' - error message from rave', $message);
        }
    }

    function requery(SI_Checkouts $checkout, $txref)
    {

        $url = ( self::$api_mode === self::MODE_TEST ) ? self::$stagingUrl : self::$liveUrl ;
        $secretKey = ( self::$api_mode === self::MODE_TEST ) ? self::$api_secret_key_test : self::$api_secret_key ;

        self::$requeryCount++;
        $data = array(
            'txref' => $txref,
            'SECKEY' => $secretKey,
            'last_attempt' => '1'
            // 'only_successful' => '1'
        );

        // make request to endpoint.
        $request = wp_remote_post( $url . '/flwv3-pug/getpaidx/api/v2/verify', array(
            'method' => 'POST',
            'body' => $data
        ) );

        $resp = json_decode(wp_remote_retrieve_body($request));
        if ($resp && $resp->status === "success") {
            if ($resp && $resp->data && $resp->data->status === "successful") {
                self::verifyTransaction($checkout, $resp->data);
            } elseif ($resp && $resp->data && $resp->data->status === "failed") {

            } else {
                    // I will requery again here. Just incase we have some devs that cannot setup a queue for requery. I don't like this.
                if (self::$requeryCount > 4) {
                    
                } else {
                    sleep(3);
                    return self::requery($checkout, $txref);
                }
            }
        } else {
            if (self::$requeryCount > 4) {
                
            } else {
                sleep(3);
                return self::requery($checkout, $txref);
            }
        }
    }

    /**
     * Requeries a previous transaction from the Rave payment gateway
     * @param string $referenceNumber This should be the reference number of the transaction you want to requery
     * @return object
     * */
    function verifyTransaction(SI_Checkouts $checkout, $data)
    {
        $invoice = $checkout->get_invoice();
		$amountToBePaid = ( si_has_invoice_deposit( $invoice->get_id() ) ) ? $invoice->get_deposit() : $invoice->get_balance();
	    $currency = self::get_currency_code( $invoice->get_id() );
        $amount = $amountToBePaid + 0;
        // create new payment
        
        if (($data->chargecode == "00" || $data->chargecode == "0") && ($data->amount == $amount) && ($data->currency == $currency)) {
			$payment_id = SI_Payment::new_payment( array(
                'payment_method' => self::get_payment_method(),
                'invoice' => $invoice->get_id(),
                'amount' => $data->amount,
                'data' => array(
                    'live' => ( self::$api_mode == self::MODE_LIVE ),
                    'api_response' => $data,
                ),
            ), SI_Payment::STATUS_AUTHORIZED );
            if ( ! $payment_id ) {
                return false;
            }
            $payment = SI_Payment::get_instance( $payment_id );
            do_action( 'payment_completed', $payment );

            return $payment;

	    }
    }

    
}
SI_Rave::register();
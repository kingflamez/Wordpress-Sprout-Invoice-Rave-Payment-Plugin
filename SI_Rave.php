<?php

/**
 * Rave payment processor.
 *
 * These actions are fired for each checkout page.
 *
 * Payment page - 'si_checkout_action_'.SI_Checkouts::PAYMENT_PAGE
 * Review page - 'si_checkout_action_'.SI_Checkouts::REVIEW_PAGE
 * Confirmation page - 'si_checkout_action_'.SI_Checkouts::CONFIRMATION_PAGE
 *
 * Necessary methods:
 * get_instance -- duh
 * get_slug -- slug for the payment process
 * get_options -- used on the invoice payment dropdown
 * process_payment -- called when the checkout is complete before the confirmation page is shown. If a
 * payment fails then the user will be redirected back to the invoice.
 *
 * @package SI
 * @subpackage Payment Processing_Processor
 */
class SI_Rave extends SI_Offsite_Processors {
	const MODE_TEST = 'staging';
	const MODE_LIVE = 'live';
	const API_LIVE_SECRET_KEY_OPTION = 'si_rave_live_secret_key';
	const API_LIVE_PUB_KEY_OPTION = 'si_rave_live_pub_key';
	const API_TEST_SECRET_KEY_OPTION = 'si_rave_test_secret_key';
	const API_TEST_PUB_KEY_OPTION = 'si_rave_test_pub_key';
	const API_ENDPOINT_STAGING = 'https://ravesandboxapi.flutterwave.com';
	const API_ENDPOINT_LIVE = 'https://api.ravepay.co';

	const PAYMENT_ALL = "both";
	const PAYMENT_USSD = "ussd";
	const PAYMENT_ACCOUNT = "account";
	const PAYMENT_CARD = "card";

	const COUNTRY_NIGERIA = "NG";
	const COUNTRY_GHANA = "GH";
	const COUNTRY_KENYA = "KE";
	const COUNTRY_SOUTH_AFRICA = "ZA";

	const CURRENCY_NGN = "NGN";
	const CURRENCY_GHS = "GHS";
	const CURRENCY_KES = "KES";
	const CURRENCY_ZAR = "ZAR";
	const CURRENCY_USD = "USD";
	const CURRENCY_GBP = "GBP";

	const REQUERY_COUNT = 0;

	const LOGO = 'si_rave_logo';
	const API_PAYMENT_METHOD = 'si_rave_payment_method';
	const COUNTRY = 'si_rave_merchant_country';
	const PAYMENT_SLUG = 'rave';
	const CANCEL_URL_OPTION = 'si_rave_cancel_url';
	const API_MODE_OPTION = 'si_rave_mode';
	const PAYMENT_METHOD = 'Debit & Credit Card, Account and USSD (Rave)';
	const CURRENCY_CODE_OPTION = 'si_rave_currency';
	const TOKEN_KEY = 'si_token_key'; // Combine with $blog_id to get the actual meta key
	const PAYER_ID = 'si_payer_id'; // Combine with $blog_id to get the actual meta key

	protected static $instance;
	private static $token;
	protected static $api_mode = self::MODE_TEST;
	private static $logo;
	private static $livesecretkey;
	private static $livepublickey;
	private static $testsecretkey;
	private static $testpublickey;
	private static $cancel_url = '';
	private static $apiPaymentMethod;
	private static $country;
	private static $currency_code = 'NGN';

	public static function get_instance() {
		if ( ! ( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_api_url() {
		if ( self::$api_mode == self::MODE_LIVE ) {
			return self::API_ENDPOINT_LIVE;
		} else {
			return self::API_ENDPOINT_STAGING;
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	public function get_api_payment_method() {
		return self::API_PAYMENT_METHOD;
	}

	public function get_logo() {
		return self::LOGO;
	}

	public function get_slug() {
		return self::PAYMENT_SLUG;
	}

	public static function returned_from_offsite() {
		return ( isset( $_GET['txref'] ) );
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, __( 'Rave by Flutterwave', 'sprout-invoices' ) );
	}

	public static function public_name() {
		return __( 'Rave', 'sprout-invoices' );
	}

	public static function checkout_options() {
		$option = array(
			'icons' => array( 'http://res.cloudinary.com/datablock123452018/image/upload/v1523744197/rave.png' ),
			'label' => __( 'Rave', 'sprout-invoices' ),
			'cc' => array(),
			);
		return apply_filters( 'si_rave_checkout_options', $option );
	}

	protected function __construct() {
		parent::__construct();
		$this->requeryCount = 0;
		$this->raveResponse=null;
		self::$livepublickey = get_option(self::API_LIVE_PUB_KEY_OPTION);
		self::$livesecretkey = get_option(self::API_LIVE_SECRET_KEY_OPTION);
		self::$testpublickey = get_option(self::API_TEST_PUB_KEY_OPTION);
		self::$testsecretkey = get_option(self::API_TEST_SECRET_KEY_OPTION);
		self::$logo = get_option( self::LOGO );
		self::$apiPaymentMethod = get_option( self::API_PAYMENT_METHOD );
		self::$country = get_option( self::COUNTRY );
		self::$api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );
		self::$currency_code = get_option( self::CURRENCY_CODE_OPTION, 'NGN' );
		self::$cancel_url = get_option( self::CANCEL_URL_OPTION, add_query_arg( array( 'cancelled_rave_payment' => 1 ), home_url( '/' ) ) );
		if ( self::$cancel_url === '' ) {
			$url = add_query_arg( array( 'cancelled_rave_payment' => 1 ), home_url( '/' ) );
			update_option( self::CANCEL_URL_OPTION, $url );
			self::$cancel_url = esc_url_raw( $url );
		}

		if ( is_admin() ) {
			add_action( 'init', array( get_class(), 'register_options' ) );
		}

		add_action( 'si_checkout_action_'.SI_Checkouts::PAYMENT_PAGE, array( $this, 'send_offsite' ), 0, 1 );
		add_action( 'si_checkout_action_'.SI_Checkouts::REVIEW_PAGE, array( $this, 'back_from_rave' ), 0, 1 );
		add_action( 'checkout_completed', array( $this, 'post_checkout_redirect' ), 10, 2 );

		add_action( 'processed_payment', array( $this, 'capture_payment_after_auth' ), 10 );
		add_action( 'si_manually_capture_purchase', array( $this, 'manually_capture_purchase' ), 10 );

		// Add Recurring button
		//add_action( 'recurring_payments_profile_info', array( __CLASS__, 'rave_profile_link' ) );
	}

	/**
	 * Hooked on init add the settings page and options.
	 *
	 */
	public static function register_options() {
		// Settings
		$settings = array(
			'si_rave_settings' => array(
				'title' => __( 'Rave Settings', 'sprout-invoices' ),
				'weight' => 200,
				'tab' => self::get_settings_page( false ),
				'settings' => array(
					self::API_MODE_OPTION => array(
						'label' => __( 'Mode', 'sprout-invoices' ),
						'option' => array(
							'type' => 'radios',
							'options' => array(
								self::MODE_LIVE => __( 'Live', 'sprout-invoices' ),
								self::MODE_TEST => __( 'Staging', 'sprout-invoices' ),
								),
							'default' => self::$api_mode,
							),
						),
					self::API_LIVE_PUB_KEY_OPTION => array(
						'label' => __('Rave Live Public Key', 'sprout-invoices'),
						'option' => array(
							'type' => 'text',
							'default' => self::$livepublickey,
						),
					),
					self::API_LIVE_SECRET_KEY_OPTION => array(
						'label' => __('Rave Live Secret Key', 'sprout-invoices'),
						'option' => array(
							'type' => 'text',
							'default' => self::$livesecretkey,
						),
					),
					self::API_TEST_PUB_KEY_OPTION => array(
						'label' => __('Rave Test Public Key', 'sprout-invoices'),
						'option' => array(
							'type' => 'text',
							'default' => self::$testpublickey,
						),
					),
					self::API_TEST_SECRET_KEY_OPTION => array(
						'label' => __('Rave Test Secret Key', 'sprout-invoices'),
						'option' => array(
							'type' => 'text',
							'default' => self::$testsecretkey,
						),
					),
					self::API_PAYMENT_METHOD => array(
						'label' => __( 'Payment Method' , 'sprout-invoices' ),
						'option' => array(
							'type' => 'select',
							'options' => array(
								self::PAYMENT_ALL => __( 'All' , 'sprout-invoices' ),
								self::PAYMENT_ACCOUNT => __( 'Account Only' , 'sprout-invoices' ),
								self::PAYMENT_CARD => __( 'Card Only' , 'sprout-invoices' ),
								self::PAYMENT_USSD => __( 'USSD Only' , 'sprout-invoices' ),
								),
							'default' => self::$apiPaymentMethod,
							)
						),
					self::COUNTRY => array(
						'label' => __( 'Payment Method' , 'sprout-invoices' ),
						'option' => array(
							'type' => 'select',
							'options' => array(
								self::COUNTRY_NIGERIA => __( 'Nigeria' , 'sprout-invoices' ),
								self::COUNTRY_KENYA => __( 'Kenya' , 'sprout-invoices' ), 
								self::COUNTRY_GHANA => __('Ghana', 'sprout-invoices'),
								self::COUNTRY_SOUTH_AFRICA => __('South Africa', 'sprout-invoices'),
								),
							'default' => self::$country,
							)
						),
					self::CURRENCY_CODE_OPTION => array(
						'label' => __( 'Currency Code', 'sprout-invoices' ),
						'option' => array(
							'type' => 'select',
							'options' => array(
								self::CURRENCY_NGN => __( 'NGN' , 'sprout-invoices' ),
								self::CURRENCY_KES => __( 'KES' , 'sprout-invoices' ),
								self::CURRENCY_GHS => __( 'GHS' , 'sprout-invoices' ),
								self::CURRENCY_USD => __( 'USD' , 'sprout-invoices' ),
								self::CURRENCY_GBP => __( 'GBP' , 'sprout-invoices' ),
								self::CURRENCY_ZAR => __( 'ZAR' , 'sprout-invoices' ),
							),
							'default' => self::$currency_code,
							'attributes' => array( 'class' => 'small-text' ),
							),
						),
					),
				),
			);
		do_action( 'sprout_settings', $settings, self::SETTINGS_PAGE );
	}

	/**
	 * Instead of redirecting to the SIcheckout page,
	 * set up the Express Checkout transaction and redirect there
	 *
	 * @param SI_Carts $cart
	 * @return void
	 */
	public function send_offsite( SI_Checkouts $checkout ) {
		// Check to see if the payment processor being used is for this payment processor
		if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) { // FUTURE have parent class handle this smarter'r
			return;
		}

		// No form to validate
		remove_action( 'si_checkout_action_'.SI_Checkouts::PAYMENT_PAGE, array( $checkout, 'process_payment_page' ) );

		if ( ! isset( $_GET['txref'] ) && $_REQUEST[ SI_Checkouts::CHECKOUT_ACTION ] == SI_Checkouts::PAYMENT_PAGE ) {			
			
			$invoice = $checkout->get_invoice();
			$client = $invoice->get_client();

			$user = si_who_is_paying( $invoice );

			$user_email = ( $user ) ? $user->user_email : '' ;
      
			$redirectURL = $checkout->checkout_complete_url( $this->get_slug() );
			if (self::$api_mode == 'staging') {
				$publicKey = self::$testpublickey;
				$secretKey = self::$testsecretkey;
			} else {
				$publicKey = self::$livepublickey;
				$secretKey = self::$livesecretkey;
			}
			$baseUrl = self::get_api_url();

			$invoice_id = get_the_id();

			$country = self::$country;
			$ref = $invoice->get_id().'_'.time();
			$overrideRef = true;
		    $name = $user->display_name;

			$amountToBePaid = ( si_has_invoice_deposit( $invoice->get_id() ) ) ? $invoice->get_deposit() : $invoice->get_balance();

			$postfields = array();
		    $postfields['PBFPubKey'] = $publicKey;
		    $postfields['customer_email'] = $user_email;
		    $postfields['customer_firstname'] = $name;
		    if (self::$logo) {
		    	$postfields['custom_logo'] = self::$logo;
		    }
		    $postfields['custom_description'] = "Payment for Invoice: ". $invoice->get_id()." on ".get_bloginfo('name');
		    $postfields['custom_title'] = get_bloginfo('name');
		    $postfields['country'] = $country;
		    $postfields['redirect_url'] = $redirectURL;
		    $postfields['txref'] = $ref;
		    $postfields['payment_method'] = self::$apiPaymentMethod;
		    $postfields['amount'] = $amountToBePaid + 0;
		    $postfields['currency'] = self::get_currency_code( $invoice->get_id() );
		    $postfields['hosted_payment'] = 1;
		    ksort($postfields);
		    $stringToHash ="";
		    foreach ($postfields as $key => $val) {
		        $stringToHash .= $val;
		    }

		    $stringToHash .= $secretKey;
		    $hashedValue = hash('sha256', $stringToHash);
		    $transactionData = array_merge($postfields, array('integrity_hash' => $hashedValue));
		    $json = json_encode($transactionData);
		    $htmlOutput = "
		    <script type='text/javascript' src='" . $baseUrl . "/flwv3-pug/getpaidx/api/flwpbf-inline.js'></script>
		    <script>
		    document.addEventListener('DOMContentLoaded', function(event) {
			    var data = JSON.parse('" . json_encode($transactionData = array_merge($postfields, array('integrity_hash' => $hashedValue))) . "');
			    getpaidSetup(data);
			});
		    </script>
		    ";
		    echo $htmlOutput;
			exit;

		}
	}

	/**
	 * We're on the checkout page, just back from Rave.
	 * Store the Reference that Rave gives us
	 *
	 * @return void
	 */
	public function back_from_rave( SI_Checkouts $checkout ) {
		// Check to see if the payment processor being used is for this payment processor
		if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) {
		 // FUTURE have parent class handle this smarter'r
			return;
		}
		if ( isset($_GET['txref']) ) {
			self::set_token( urldecode( $_GET['txref'] ) );
			self::set_payerid( urldecode( $_GET['flwref'] ) );

			$this->requery($checkout);
		}
		// Starting over.
		self::unset_token();
	}

	function requery(SI_Checkouts $checkout)
	{	
		
		if (self::$api_mode == 'staging') {
			$apiLink = "https://ravesandboxapi.flutterwave.com/";
			$secretKey = self::$testsecretkey;
		}else{
			$apiLink = "https://api.ravepay.co/";
			$secretKey = self::$livesecretkey;
		}
	    $txref = $_REQUEST['txref'];
	    $this->requeryCount++;
	    $data = array(
	        'txref' => $txref,
	        'SECKEY' => $secretKey,
	        'last_attempt' => '1'
	        // 'only_successful' => '1'
	    );
	    // make request to endpoint.
	    $data_string = json_encode($data);
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $apiLink . 'flwv3-pug/getpaidx/api/v2/verify');
	    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	    $response = curl_exec($ch);
	    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	    $header = substr($response, 0, $header_size);
	    $body = substr($response, $header_size);
	    curl_close($ch);
	    $resp = json_decode($response, false);

	    if ($resp && $resp->status === "success") {
	        if ($resp && $resp->data && $resp->data->status === "successful") {
	            $this->verifyTransaction($checkout, $resp->data);
	        } elseif ($resp && $resp->data && $resp->data->status === "failed") {
	    			$this->failed('The transaction Failed');
	        } else {
	            if ($this->requeryCount > 4) {
	    			$this->failed('The transaction Failed');
	            } else {
	                sleep(3);
	                $this->requery($checkout);
	            }
	        }
	    } else {
	        if ($this->requeryCount > 4) {
	    			$this->failed('The transaction Failed');
	        } else {
	            sleep(3);
	            $this->requery($checkout);
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

	    if (($data->chargecode == "00" || $data->chargecode == "0") && ($data->amount == $amount) && ($data->currency == $currency)) {

			$this->setRaveResponse($data);
			// Payment is complete
			$checkout->mark_page_complete( SI_Checkouts::PAYMENT_PAGE );
			// Skip the review page since that's already done at rave.
			$checkout->mark_page_complete( SI_Checkouts::REVIEW_PAGE );

	    } else {
	    	$this->failed('The transaction completed but failed, contact the site owner');
	    }
	}
	function failed( $message)
	{
		if ($_GET['cancelled']) {
			$message = "You cancelled the transaction";
		}
		self::set_message( $message, self::MESSAGE_STATUS_ERROR );
	}

	public function post_checkout_redirect( SI_Checkouts $checkout, SI_Payment $payment ) {
		if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) {
			return;
		}
		wp_redirect( $checkout->checkout_confirmation_url( self::PAYMENT_SLUG ) );
		exit();
	}

	public static function set_token( $token ) {
		global $blog_id;
		update_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, $token );
	}

	public static function unset_token() {
		global $blog_id;
		delete_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY );
	}

	public static function get_token() {
		if ( isset( $_REQUEST['token'] ) && $_REQUEST['token'] ) {
			return $_REQUEST['token'];
		}
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, true );
	}

	public static function set_payerid( $get_payerid ) {
		global $blog_id;
		update_user_meta( get_current_user_id(), $blog_id.'_'.self::PAYER_ID, $get_payerid );
	}

	public static function get_payerid() {
		if ( isset( $_REQUEST['PayerID'] ) && $_REQUEST['PayerID'] ) {
			return $_REQUEST['PayerID'];
		}
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::PAYER_ID, true );
	}

	public function offsite_payment_complete() {
		if ( self::get_token() && self::get_payerid() ) {
			return true;
		}
		return false;
	}

	/**
	 * Process a payment
	 *
	 * @param SI_Checkouts $checkout
	 * @param SI_Invoice $invoice
	 * @return SI_Payment|bool false if the payment failed, otherwise a Payment object
	 */
	public function process_payment( SI_Checkouts $checkout, SI_Invoice $invoice ) {

		// create new payment
		$payment_id = SI_Payment::new_payment( array(
			'payment_method' => self::get_payment_method(),
			'invoice' => $invoice->get_id(),
			'amount' => $this->getRaveResponse()->amount,
			'data' => array(
			'live' => ( self::$api_mode == self::MODE_LIVE ),
			'api_response' => $this->getRaveResponse(),
			'payment_token' => self::get_token(),
			),
		), SI_Payment::STATUS_COMPLETE );
		if ( ! $payment_id ) {
			return false;
		}
		$payment = SI_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );

		self::unset_token();
		return $payment;
	}

	

	//////////////
	// Utility //
	//////////////

	private function get_currency_code( $invoice_id ) {
		return apply_filters( 'si_currency_code', self::$currency_code, $invoice_id, self::PAYMENT_METHOD );
	}


	public function setRaveResponse($data)
	{
		$this->raveResponse = $data;
	}

	public function getRaveResponse()
	{
		return $this->raveResponse;
	}

}
SI_Rave::register();

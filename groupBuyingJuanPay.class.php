<?php

class Group_Buying_Juanpay extends Group_Buying_Offsite_Processors {
	const API_ENDPOINT_SANDBOX = 'https://sandbox.juanpay.ph';
	const API_ENDPOINT_LIVE = 'https://www.juanpay.ph';

	const API_JUANPAY_KEY = 'gb_juanpay_api_key';
	const API_JUANPAY_EMAIL = 'gb_juanpay_api_email';
	const API_MODE_OPTION = 'gb_juanpay_mode';

	const TOKEN_KEY = 'gb_token_key'; // Combine with $blog_id to get the actual meta key
	const TRANSACTION_TYPE = 'gb_mpay24_transaction'; // Combine with $blog_id to get the actual meta key

	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';
	const RETURN_URL_OPTION = 'gb_juanpay_return_url';

	const PAYMENT_METHOD = 'Juanpay';
	const USE_PROXY = FALSE;
	const DEBUG = TRUE;

	protected static $instance;
	protected static $api_mode = self::MODE_TEST;
	private static $api_key = '';
	private static $api_email = '';
	private static $return_url = '';

	public static function get_instance() {
		if ( !(isset(self::$instance) && is_a(self::$instance, __CLASS__)) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private static function get_api_url() {
		$api_mode = get_option(self::API_MODE_OPTION, self::MODE_TEST);
		if ( $api_mode == self::MODE_LIVE ) {
			return self::API_ENDPOINT_LIVE;
		} else {
			return self::API_ENDPOINT_SANDBOX;
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	public static function returned_from_offsite() {
		return ( isset( $_GET['back_from_juanpay'] ) && $_GET['back_from_juanpay'] != '' );
	}

	public static function register() {
		self::add_payment_processor(__CLASS__, self::__('Juanpay (beta)'));
	}

	/**
	* This is for the checkout icon
	*
	*/
	public static function checkout_icon() {
		return '<img src="https://juanpay.ph//assets/landinglogo.png" title="JuanPay Payments" id="juanpay_logo"/>';
	}

	protected function __construct() {
		parent::__construct();
		self::$api_key = get_option(self::API_JUANPAY_KEY, 'b48e4b21ef289502a64efd3c65c09f71' );
		self::$api_email = get_option(self::API_JUANPAY_EMAIL, '' );
		self::$api_mode = get_option(self::API_MODE_OPTION, self::MODE_TEST);
		self::$return_url = Group_Buying_Checkouts::get_url();
		
		if ( is_admin() ) {
			add_action( 'init', array( get_class(), 'register_options') );
		}

		// Change button
		add_filter('gb_checkout_payment_controls', array($this, 'payment_controls'), 20, 2);

		// Send offsite
		add_action('gb_send_offsite_for_payment', array($this,'send_offsite'), 10, 1);

		// Handle the return of user from juanpay
		add_action('gb_load_cart', array($this,'back_from_juanpay'), 10, 0);

		// Remove the review page since it's at juanpay
		add_filter('gb_checkout_pages', array($this, 'remove_review_page'));

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array($this, 'display_exp_meta_box'), 10);
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array($this, 'display_price_meta_box'), 10);
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array($this, 'display_limits_meta_box'), 10);
	}

	/**
	 * Hooked on init add the settings page and options.
	 *
	 */
	public static function register_options() {
		// Settings
		$settings = array(
			'gb_juanpay_settings' => array(
				'title' => self::__( 'JuanPay Settings' ),
				'weight' => 200,
				'settings' => array(
					self::API_MODE_OPTION => array(
						'label' => self::__( 'Mode' ),
						'option' => array(
							'type' => 'radios',
							'options' => array(
								self::MODE_LIVE => self::__( 'Live' ),
								self::MODE_TEST => self::__( 'Sandbox' ),
								),
							'default' => self::$api_mode
							)
						),
					self::API_JUANPAY_KEY => array(
						'label' => self::__( 'API Key' ),
						'option' => array(
							'type' => 'text',
							'default' => self::$api_key
							)
						),
					self::API_JUANPAY_EMAIL => array(
						'label' => self::__( 'API Email' ),
						'option' => array(
							'type' => 'text',
							'default' => self::$api_email
							)
						)
					)
				)
			);
		do_action( 'gb_settings', $settings, Group_Buying_Payment_Processors::SETTINGS_PAGE );
	}

	/**
	 * Instead of redirecting to the GBS checkout page,
	 * set up the JuanPay Request and redirect
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @return void
	 */
	public function send_offsite( Group_Buying_Checkouts $checkout ) {

		$cart = $checkout->get_cart();

		if ( $cart->get_total( self::get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $cart->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		if ( $_REQUEST['gb_checkout_action'] == Group_Buying_Checkouts::PAYMENT_PAGE ) {

			$filtered_total = $this->get_payment_request_total($checkout);
			if ( $filtered_total < 0.01 ) {
				return array();
			}

			$user = get_userdata( get_current_user_id() );
			$account = Group_Buying_Account::get_instance( get_current_user_id() );

			$transaction_id = Group_Buying_Records::new_record( null, self::TRANSACTION_TYPE, 'jaunpay transaction', get_current_user_id() );
			self::set_token( $transaction_id ); // Set the transaction id

			$juanpay_args = array(
				'email' => self::$api_email,
				'order_number' => $transaction_id,
				'confirm_form_option' => 'NONE',
				'buyer_first_name' => $checkout->cache['billing']['first_name'],
				'buyer_last_name' => $checkout->cache['billing']['first_name'],
				'buyer_email' => $user->user_email,
				'buyer_cell_number' => '+639155533277',
				'return_url' => add_query_arg( array( 'back_from_juanpay' => $transaction_id ), self::$return_url )
			);

			$i = 1;
			foreach ( $cart->get_items() as $key => $item ) {
				$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
				$item_name = preg_replace( '/\r|\n/m','', $deal->get_title($item['data']) );
				$juanpay_args[ 'item_name_' . $i ] = $item_name;
				$juanpay_args[ 'qty_' . $i ] = $item['quantity'];
				$juanpay_args[ 'price_' . $i ] = sprintf('%0.2f', $deal->get_price());
				$juanpay_args[ 'item_number_' . $i ] = $item['deal_id'];
				$i++;
			}

			// Build hash
			ksort($juanpay_args);
			$hash = self::juanpay_hash($juanpay_args);
			$juanpay_args['hash'] = $hash;

			return self::redirect( $juanpay_args, $hash );
		}
	}

	/**
	  * Create form before redirecting to Juanpay
	*/
	public function redirect($juanpay_args, $hash) {
		$_input = '  <input type="hidden" name="%s" value="%s"  />';
		$_html = array();
		$_html[] = "<html>";
		$_html[] = "<head><title>Processing Payment...</title></head>";
		//$_html[] = "<body onLoad=\"document.forms['juanpay_form'].submit();\">";
		$_html[] = "<body>";
		$_html[] = '<center><img src="'. gb_get_header_logo() .'"></center>';
		$_html[] =  "<center><h2>";
		$_html[] = self::__("Please wait, your order is being processed and you will be redirected to the Juanpay website.");
		$_html[] =  "</h2></center>";

		$_html[] = '<form name="juanpay_form" action="'.self::get_api_url().'/checkout" method="post">';
		foreach ($juanpay_args as $key => $value) {
			$_html[] = sprintf ($_input, $key, $value);
		}
		$_html[] =  "<center><br/><br/>";
		$_html[] =  self::__("If you are not automatically redirected to ");
		$_html[] =  self::__("Juanpay within 5 seconds...");
		$_html[] =  "<br/><br/>\n";
		$_html[] =  '<input type="submit" value="'.self::__('Click Here').'"></center>';

		$_html[] = '</form>';
		$_html[] = '</body>';
		$return = implode("\n", $_html);
		print $return;
		exit();
	}

	private static function juanpay_hash($params) {
		$md5HashData = self::$api_key;
		$hashedvalue = '';
		foreach($params as $key => $value) {
			if ($key<>'hash' && strlen($value) > 0) {
				$md5HashData .= $value;
			}
		}
		if (strlen(self::$api_key) > 0) {
			$hashedvalue .= strtoupper(md5($md5HashData));
		}
		return $hashedvalue;
	}

	/**
	* Handle a received IPN.
	* 
	* @param array $data_array Array containing the data received from pxpay.
	* @return 
	*	If VERIFIED: true
	*	If UNVERIFIED: false
	*/
	public function back_from_juanpay() {
		if ( self::returned_from_offsite() ) {
			// Remove that review page since we're now returned.
			add_filter('gb_checkout_pages', array($this, 'remove_checkout_page'));
			$_REQUEST['gb_checkout_action'] = 'back_from_juanpay';
			do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - BACK FROM JUANPAY (REQUEST)', $_REQUEST );
			do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - BACK FROM JUANPAY (POST)', $_POST );
		}
		if ( !empty($_POST) && !isset($_POST['gb_checkout_action']) ) {
			self::listener( $_POST );
		}
	}

	public function remove_checkout_page( $pages ) {
		unset($pages[Group_Buying_Checkouts::PAYMENT_PAGE]);
		unset($pages[Group_Buying_Checkouts::REVIEW_PAGE]);
		return $pages;
	}


	/**
	 * Process a payment
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {

		if ( $purchase->get_total($this->get_payment_method()) < 0.01 ) {
			$payments = Group_Buying_Payment::get_payments_for_purchase($purchase->get_id());
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance($payment_id);
				return $payment;
			}
		}

		// create loop of deals for the payment post
		$deal_info = array();
		foreach ( $purchase->get_products() as $item ) {
			if ( isset($item['payment_method'][self::get_payment_method()]) ) {
				if ( !isset($deal_info[$item['deal_id']]) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset($checkout->cache['shipping']) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}

		// Transaction id
		$transaction_id = ( isset( $_GET['back_from_juanpay'] ) && $_GET['back_from_juanpay'] != '' ) ? $_GET['back_from_juanpay'] : self::get_token() ;
		self::unset_token();

		$payment_id = Group_Buying_Payment::new_payment( array(
			'payment_method' => $this->get_payment_method(),
			'purchase' => $purchase->get_id(),
			'amount' => gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ),
			'data' => array(
				'tid' => $transaction_id,
				'api_response' => $_POST,
				'uncaptured_deals' => $deal_info
			),
			'deals' => $deal_info,
			'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_PENDING);
		if ( !$payment_id ) {
			return FALSE;
		}

		$record = Group_Buying_Record::get_instance( $transaction_id );
		$record->set_data( array( 'payment_id' => $payment_id, 'purchase_id' => $purchase->get_id() ) );

		// send data back to complete_checkout
		$payment = Group_Buying_Payment::get_instance($payment_id);
		do_action( 'payment_authorized', $payment );

		// finalize
		return $payment;

	}


	/**
	 * This is our listener.  If the proper query var is set correctly it will
	 * attempt to handle the response.
	 */
	public function listener() {
		// Try to validate the response to make sure it's from JuanPay
		if ( self::check_ipn_request_is_valid() )
			successful_ipn();

		$error = gb__( 'JuanPay Purchase Error. Contact the Store Owner.' );
		self::set_message( $error, self::MESSAGE_STATUS_ERROR );
		wp_redirect( Group_Buying_Carts::get_url() );
		exit();
	}

	/**
	 * Validate the message by checking with JuanPay to make sure they really
	 * sent it
	 */
	private static function check_ipn_request_is_valid() {
		// Get recieved values from post data
		$received_values = array();
		$received_values += stripslashes_deep( $_POST );

		// Send back post vars to juanpay
		$params = array(
			'body' 			=> $received_values,
			'sslverify' 	=> false,
			'timeout' 		=> 60,
			'httpversion'   => '1.1',
			'headers'       => array( 'host' => 'www.juanpay.com' ),
			'user-agent'	=> Group_Buying_Update_Check::PLUGIN_NAME . '/' . Group_Buying::GB_VERSION
		);

		// Post back to get a response
		$response = wp_remote_post( self::get_api_url()."/dpn/validate", $params );

		do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - DPN Response:', $response );

		// check to see if the request was valid
		if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && ( strcmp( $response['body'], "VERIFIED" ) == 0 ) ) {
			do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - Received valid response from JuanPay:', $response );
			return true;
		}

		do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - Error response:', $response->get_error_message() );
		return false;
	}


	/**
	 * Successful Payment!
	 *
	 * @access public
	 * @param array $stripped_post
	 * @return void
	 */
	public static function successful_ipn() {
		$complete = FALSE;
		$stripped_post = stripslashes_deep( $_POST );
		$payment_id = self::get_payment_id( $stripped_post );
		$order_number = (int) $stripped_post['order_number'];

		do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - Successfull Request:', $stripped_post );

		if ($stripped_post['status'] == 'Confirmed' || $stripped_post['status'] == 'Underpaid') {
			// Order confirmed but unpaid.
		}
		if ($stripped_post['status'] == 'Paid' || $stripped_post['status'] == 'Overpaid') {

			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$items_to_capture = $this->items_to_capture( $payment );

			// Check order not already completed
			if ( $items_to_capture ) {
				// Change payment data
				foreach ( $items_to_capture as $deal_id => $amount ) {
					unset( $data['uncaptured_deals'][$deal_id] );
				}
				if ( !isset( $data['capture_response'] ) ) {
					$data['capture_response'] = array();
				}
				$data['capture_response'][] = $status;
				$payment->set_data( $data );

				// Payment completed
				do_action( 'payment_captured', $payment, array_keys( $items_to_capture )  );
				$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
				do_action( 'payment_complete', $payment );

				$complete = TRUE;
			}
		}
		return $complete;
	}

	public static function get_payment_id( $stripped_post ) {
		$order_number = (int) $stripped_post['order_number'];

		$record = Group_Buying_Record::get_instance( $order_number );
		$data = $record->get_data();

		do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - Get Order ID:', $data );

		return $data['payment_id'];
	}


	/**
	 * The review page is unnecessary (or, rather, it's offsite)
	 * @param array $pages
	 * @return array
	 */
	public function remove_review_page( $pages ) {
		unset($pages[Group_Buying_Checkouts::REVIEW_PAGE]);
		return $pages;
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
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, TRUE );
	}

	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {
		if ( isset($controls['review']) ) {
			$controls['review'] = str_replace( 'value="'.self::__('Review').'"', $style . ' value="'.self::__('Juanpay').'"', $controls['review']);
		}
		return $controls;
	}

	/**
	 * Grabs error messages from a Juanpay response and displays them to the user
	 * @param array $response
	 * @param bool $display
	 * @return void
	 */
	private function set_error_messages( $message, $display = TRUE ) {
		if ( $display ) {
			self::set_message($message, self::MESSAGE_STATUS_ERROR);
		} else {
			error_log($message);
		}
	}

	public function display_exp_meta_box() {
		return GB_PATH . '/controllers/payment-processing/payment-processors/views/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box() {
		return GB_PATH . '/controllers/payment-processing/payment-processors/views/meta-boxes/no-dyn-price.php';
	}

	public function display_limits_meta_box() {
		return GB_PATH . '/controllers/payment-processing/payment-processors/views/meta-boxes/no-tipping.php';
	}
}

Group_Buying_Juanpay::register();

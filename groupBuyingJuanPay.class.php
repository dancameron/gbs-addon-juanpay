<?php
class Group_Buying_Juanpay extends Group_Buying_Offsite_Processors {
	const API_ENDPOINT_SANDBOX = 'https://sandbox.juanpay.ph';
	const API_ENDPOINT_LIVE = 'https://www.juanpay.ph';
	const API_JUANPAY_EMAIL = 'gb_juanpay_api_email';
	const API_KEY = 'b48e4b21ef289502a64efd3c65c09f71';
	const API_EMAIL = 'rmsdesignlab@gmail.com';
	const API_JUANPAY_KEY = 'gb_juanpay_api_key';
	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';
	const API_MODE_OPTION = 'gb_juanpay_mode';
	const CANCEL_URL_OPTION = 'gb_juanpay_cancel_url';
	const RETURN_URL_OPTION = 'gb_juanpay_return_url';
	const CURRENCY_CODE_OPTION = 'gb_juanpay_currency';
	const PAYMENT_METHOD = 'Juanpay';

	const USE_PROXY = FALSE;
	const DEBUG = TRUE;
	protected static $instance;
	private static $response_data;

	private $cancel_url = '';
	private $return_url = '';
	private $api_email = '';
	private $api_key = '';
	private $currency_code = '';

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

	private function get_redirect_url() {
		if ( $this->api_mode == self::MODE_LIVE ) {
			return self::API_REDIRECT_LIVE;
		} else {
			return self::API_REDIRECT_SANDBOX;
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	public static function juanpay_hash($params) {
            $API_Key = self::API_KEY;
            $md5HashData = $API_Key;
            $hashedvalue = '';
            foreach($params as $key => $value) {
                if ($key<>'hash' && strlen($value) > 0) {
                    $md5HashData .= $value;
                }
            }
            if (strlen($API_Key) > 0) {
                $hashedvalue .= strtoupper(md5($md5HashData));
            }
            return $hashedvalue;
        }

	protected function __construct() {
		parent::__construct();
		$this->api_key = get_option(self::API_JUANPAY_KEY, self::API_KEY);
		$this->api_email = get_option(self::API_JUANPAY_EMAIL, self::API_EMAIL);
		$this->api_mode = get_option(self::API_MODE_OPTION, self::MODE_TEST);
		$this->cancel_url = get_option(self::CANCEL_URL_OPTION, Group_Buying_Accounts::get_url());
		$this->return_url = Group_Buying_Checkouts::get_url();
		$this->currency_code = get_option(self::CURRENCY_CODE_OPTION, 'PHP');
		
		add_action('admin_init', array($this, 'register_settings'), 10, 0);

		// Remove the review page since it's at juanpay
		add_filter('gb_checkout_pages', array($this, 'remove_review_page'));

		// Change button
		add_filter('gb_checkout_payment_controls', array($this, 'payment_controls'), 20, 2);

		// Send offsite
		add_action('gb_send_offsite_for_payment', array($this,'send_offsite'), 10, 1);
		// Handle the return of user from juanpay
		add_action('gb_load_cart', array($this,'back_from_juanpay'), 10, 0);

		// Complete Purchase
		add_action('purchase_completed', array($this, 'complete_purchase'), 10, 1);

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array($this, 'display_exp_meta_box'), 10);
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array($this, 'display_price_meta_box'), 10);
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array($this, 'display_limits_meta_box'), 10);
	}

	public static function register() {
		self::add_payment_processor(__CLASS__, self::__('Juanpay (beta)'));
	}

	/**
	* This is for the checkout icon
	*
	*/
	//public static function checkout_icon() {
	//	return '<img src="https://www.paypal.com/en_US/i/btn/btn_xpressCheckout.gif" title="Paypal Payments" id="paypal_icon"/>';
	//}

	/**
	 * The review page is unnecessary (or, rather, it's offsite)
	 * @param array $pages
	 * @return array
	 */
	public function remove_review_page( $pages ) {
		unset($pages[Group_Buying_Checkouts::REVIEW_PAGE]);
		return $pages;
	}

	/**
	 * Instead of redirecting to the GBS checkout page,
	 * set up the JuanPay Request and redirect
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @return void
	 */
	public function send_offsite( Group_Buying_Checkouts $checkout ) {
                do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - Start function with Checkout value', $checkout );
		$cart = $checkout->get_cart();
		if ( $cart->get_total() < 0.01 ) { // for free deals.
			return;
		}

		if ( $_REQUEST['gb_checkout_action'] == Group_Buying_Checkouts::PAYMENT_PAGE ) {
			$user = get_userdata(get_current_user_id());
			$account = Group_Buying_Account::get_instance(get_current_user_id());

			$filtered_total = $this->get_payment_request_total($checkout);
			if ( $filtered_total < 0.01 ) {
				return array();
			}
			$userId = get_current_user_id();
			$transaction_id = time() . mt_rand() . $userId;
                        //$transaction_id = $checkout->cache['purchase_id'];
			//global $gb_purchase_confirmation_id; // Used for addons that can't access the $order_number
			//$transaction_id = $gb_purchase_confirmation_id;
			$config_args = array (
				'email' => self::API_EMAIL,
				'order_number' => $transaction_id,
				'confirm_form_option' => 'NONE',
				);

			$item_loop = 1;
			foreach ( $cart->get_items() as $key => $item ) {
				$deal = Group_Buying_Deal::get_instance($item['deal_id']);
				$item_args = array();
				// $line_items[] .= 'Item: '.$deal->get_title($item['data']).' Quantity: '.$item['quantity'].' Price: '.sprintf('%0.2f', $deal->get_price()).' Item #: '.$item['deal_id'];
				$item_args[ 'item_name_' . $item_loop ] = $deal->get_title($item['data']);
				$item_args[ 'qty_' . $item_loop ] = $item['quantity'];
				$item_args[ 'price_' . $item_loop ] = sprintf('%0.2f', $deal->get_price());
				//$item_args[ 'item_number_' . $item_loop ] = $item['deal_id'];
				$item_loop++;
			}

			$client_args = array (
				'buyer_email' => $user->user_email,
				'buyer_first_name' => urlencode($account->get_name( 'first' )),
                                'buyer_last_name' => urlencode($account->get_name( 'last' )),
                                'buyer_cell_number' => '+639155533277',
                                'return_url' => 'http://dealversify.com/checkout/?back_from_juanpay=1',
                        );

			$juanpay_args = $config_args + $item_args + $client_args;
			ksort($juanpay_args);
			$hash = $this -> juanpay_hash($juanpay_args);

			$juanpay_hash = array (
				'hash' => $hash
				);

			$this->set_error_messages('redirect config: '.print_r($config_args,TRUE),FALSE);	
			if ( self::DEBUG ) {
				$this->set_error_messages('redirect config: '.print_r($config_args,TRUE),FALSE);
				$this->set_error_messages('redirect client: '.print_r($client_args,TRUE),FALSE);
				$this->set_error_messages('redirect items: '.print_r($item_args,TRUE),FALSE);
			}

			return self::redirect($juanpay_args, $hash);
			exit();
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
		$_html[] = "<body onLoad=\"document.forms['juanpay_form'].submit();\">";
		//$_html[] = "<body>";
		$_html[] = '<center><img src="'. gb_get_header_logo() .'"></center>';
		$_html[] =  "<center><h2>";
		$_html[] = self::__("Please wait, your order is being processed and you will be redirected to the Juanpay website.");
		$_html[] =  "</h2></center>";

		$_html[] = '<form name="juanpay_form" action="'.self::get_api_url().'/checkout" method="post">';
		foreach ($juanpay_args as $key => $value) {
			$_html[] = sprintf ($_input, $key, $value);
		}
		$_html[] = sprintf ($_input, 'hash', $hash);
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

	public static function returned_from_offsite() {
		return ( isset( $_GET['back_from_juanpay'] ) && $_GET['back_from_juanpay'] == 1 );
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
        do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - BACK FROM JUANPAY (REQUEST)', $_REQUEST );
        do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - BACK FROM JUANPAY (POST)', $_POST );


		if ( self::returned_from_offsite() ) {
			$_REQUEST['gb_checkout_action'] = 'back_from_juanpay';
			if ( self::DEBUG ) {
				$this->set_error_messages('back_from_juanpay: '.print_r($_REQUEST,TRUE),FALSE);
			}
		}
		if ( isset($_POST) && !isset($_POST['gb_checkout_action']) ) {
			self::listener();
		}

	}


	/**
	 * This is our listener.  If the proper query var is set correctly it will
	 * attempt to handle the response.
	 */
	public function listener() {
		$_POST = stripslashes_deep($_POST);
                //log_me($_POST);
		// Try to validate the response to make sure it's from JuanPay
		if ($this->_validateMessage())
			$this->_processMessage();

		// Stop WordPress entirely
		exit;
	}


	public function _fixDebugEmails() {
		$this->_settings['debugging_email'] = preg_split('/\s*,\s*/', $this->_settings['debugging_email']);
		$this->_settings['debugging_email'] = array_filter($this->_settings['debugging_email'], 'is_email');
		$this->_settings['debugging_email'] = implode(',', $this->_settings['debugging_email']);
	}

	private function _debug_mail( $subject, $message ) {
		// Used for debugging.
		if ( $this->_settings['debugging'] == 'on' && !empty($this->_settings['debugging_email']) )
			wp_mail( $this->_settings['debugging_email'], $subject, $message );
	}

	/**
	 * Validate the message by checking with JuanPay to make sure they really
	 * sent it
	 */
	private function _validateMessage() {
		// We need to send the message back to JuanPay just as we received it
		$params = array(
			'body' => $_POST,
			'sslverify' => apply_filters( 'juanpay_dpn_sslverify', false ),
			'timeout' 	=> 30,
		);

		// Send the request 
		$resp = wp_remote_post( self::get_api_url()."/dpn/validate", $params );
        log_me($resp);

		// Put the $_POST data back to how it was so we can pass it to the action
		$message = __('URL:', 'juanpay-dpn' );
		$message .= "\r\n".print_r(self::get_api_url(), true)."\r\n\r\n";
		$message .= __('Response:', 'juanpay-dpn' );
		$message .= "\r\n".print_r($resp, true)."\r\n\r\n";
		$message .= __('Post:', 'juanpay-dpn' );
		$message .= "\r\n".print_r($_POST, true);

		// If the response was valid, check to see if the request was valid
		if ( !is_wp_error($resp) && $resp['response']['code'] >= 200 && $resp['response']['code'] < 300 && (strcmp( $resp['body'], "VERIFIED") == 0)) {
                        log_me('DPN Listener Test - Validation Succeeded');
			log_me($message);

			$this->_debug_mail( __( 'DPN Listener Test - Validation Succeeded', 'juanpay-dpn' ), $message );
			return true;
		} else {
			// If we can't validate the message, assume it's bad
            log_me('DPN Listener Test - Validation Failed');
			log_me($message);

			$this->_debug_mail( __( 'DPN Listener Test - Validation Failed', 'juanpay-dpn' ), $message );
			return false;
		}
	}

	/**
	 * Throw an action based off the transaction type of the message
	 */
	private function _processMessage() {
		do_action( 'juanpay-ipn', $_POST );
		$actions = array( 'juanpay-ipn' );
		$subject = sprintf( __( 'DPN Listener Test - %s', 'juanpay-dpn' ), '_processMessage()' );
		if ( !empty($_POST['txn_type']) ) {
			do_action("juanpay-{$_POST['txn_type']}", $_POST);
			$actions[] = "juanpay-{$_POST['txn_type']}";
		}
		$message = sprintf( __( 'Actions thrown: %s', 'juanpay-dpn' ), implode( ', ', $actions ) );
		$message .= "\r\n\r\n";
		$message .= sprintf( __( 'Passed to actions: %s', 'juanpay-dpn' ), "\r\n" . print_r($_POST, true) );
		$this->_debug_mail( $subject, $message );
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

		do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - JuanPay Authorization Response (Raw)', $response );

		$payment_id = Group_Buying_Payment::new_payment( array(
			'payment_method' => self::get_payment_method(),
			'purchase' => $purchase->get_id(),
			'amount' => $purchase->get_total(self::get_payment_method()),
				'data' => array(
				'api_response' => $_POST,
					'uncaptured_deals' => $deal_info
					),
			'deals' => $deal_info,
			'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_PENDING);
		if ( !$payment_id ) {
			return FALSE;
		}

		// send data back to complete_checkout
		$payment = Group_Buying_Payment::get_instance($payment_id);
		do_action('payment_pending', $payment);
		if ( self::DEBUG ) {
			$this->set_error_messages('process_payment: '.print_r($payment,TRUE),FALSE);
		}

		return $payment;

	}

	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		if ( self::DEBUG ) {
			$this->set_error_messages('complete purchase: '.print_r($purchase,TRUE),FALSE);
		}
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		$payments = Group_Buying_Payment::get_payments_for_purchase($purchase->get_id());
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance($payment_id);
			do_action('payment_captured', $payment, $items_captured);
			do_action('payment_complete', $payment);
			$payment->set_status(Group_Buying_Payment::STATUS_COMPLETE);
		}
	}

	

	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {

		if ( isset($controls['review']) ) {
			$controls['review'] = str_replace( 'value="'.self::__('Review').'"', $style . ' value="'.self::__('Juanpay').'"', $controls['review']);
		}
		return $controls;
	}


	/**
	 * get the currency code, which is filtered
	 *
	 */

	private function get_currency_code() {
		return apply_filters('gb_juanpay_currency_code', $this->currency_code);
	}


	/////////////
	// Options //
	/////////////


	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_paypalwpp_settings';
		add_settings_section($section, self::__('Juanpay Adaptive Payments'), array($this, 'display_settings_section'), $page);
		register_setting($page, self::API_JUANPAY_KEY);
		register_setting($page, self::API_JUANPAY_EMAIL);
		register_setting($page, self::API_MODE_OPTION);
		register_setting($page, self::CURRENCY_CODE_OPTION);
		register_setting($page, self::RETURN_URL_OPTION);
		register_setting($page, self::CANCEL_URL_OPTION);
		add_settings_field(self::API_JUANPAY_KEY, self::__('API Key'), array($this, 'display_api_key_field'), $page, $section);
		add_settings_field(self::API_JUANPAY_EMAIL, self::__('API Email'), array($this, 'display_api_email_field'), $page, $section);
		add_settings_field(self::API_MODE_OPTION, self::__('Mode'), array($this, 'display_api_mode_field'), $page, $section);
		add_settings_field(self::CURRENCY_CODE_OPTION, self::__('Currency Code'), array($this, 'display_currency_code_field'), $page, $section);
		add_settings_field(self::RETURN_URL_OPTION, self::__('Return URL'), array($this, 'display_return_field'), $page, $section);
		add_settings_field(self::CANCEL_URL_OPTION, self::__('Cancel URL'), array($this, 'display_cancel_field'), $page, $section);
	}

	public function display_api_key_field() {
		echo '<input type="text" name="'.self::API_JUANPAY_KEY.'" value="'.$this->api_key.'" size="80" />';
	}

	public function display_api_email_field() {
		echo '<input type="text" name="'.self::API_JUANPAY_EMAIL.'" value="'.$this->api_email.'" size="80" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked(self::MODE_LIVE, $this->api_mode, FALSE).'/> '.self::__('Live').'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked(self::MODE_TEST, $this->api_mode, FALSE).'/> '.self::__('Sandbox').'</label>';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::CURRENCY_CODE_OPTION.'" value="'.$this->currency_code.'" size="5" />';
	}

	public function display_return_field() {
		echo '<input type="text" disabled="disabled" name="'.self::RETURN_URL_OPTION.'" value="'.$this->return_url.'" size="80" class="disabled"/>';
	}

	public function display_cancel_field() {
		echo '<input type="text" name="'.self::CANCEL_URL_OPTION.'" value="'.$this->cancel_url.'" size="80" />';
	}

	public function review_controls()
	{
		echo '<div class="checkout-controls">
				<input type="hidden" name="" value="'.self::CHECKOUT_ACTION.'">
				<input class="form-submit submit checkout_next_step" type="submit" value="'.self::__('Juanpay').'" name="gb_checkout_button" />
			</div>';
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

	public function display_exp_meta_box()
	{
		return GB_PATH . '/controllers/payment_processors/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box()
	{
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-dyn-price.php';
	}

	public function display_limits_meta_box()
	{
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-tipping.php';
	}


}

/**
 * Helper functions
 */

function log_me($message) {
    if (WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log(print_r($message, true));
        } else {
            error_log($message);
        }
    }
}

Group_Buying_Juanpay::register();

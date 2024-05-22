<?php
/*
 * Plugin Name: WooCommerce Custom Amberpay Payment Gateway
 * Description: Order using Amberpay Payment Gateway.
 * Author: Amber Pay
 * Author URI: https://myamberpay.com/
 * Version: 1.0.0
 */
 
 /*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'amberpay_add_gateway_class' );
function amberpay_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Amberpay_Gateway'; // your class name is here
	return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'amberpay_init_gateway_class' );
function amberpay_init_gateway_class() {

	class WC_Amberpay_Gateway extends WC_Payment_Gateway {

 		/**
 		 * Class constructor, more about it in Step 3
 		 */
		public function __construct() {
			$this->id = 'amberpay'; // payment gateway plugin ID
			$this->version = '1.0.0'; // payment gateway Version ID
			$this->icon = WP_PLUGIN_URL . '/' . plugin_basename(  dirname( __FILE__ ) ) . '/images/amber-logo.png'; // URL of the icon that will be displayed on checkout page near your gateway name
			$this->has_fields = false; // in case you need a custom credit card form
			$this->method_title = 'Amberpay Payment Gateway';
			$this->method_description = 'Order using Amberpay Payment Gateway'; // will be displayed on the options page
			$this->supports = array(
				'products'
			);

			// Method with all the options fields
			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();
			
			if ( ! is_admin() ) {
				$this->setup_constants();
			}
			$this->title = $this->get_option( 'title' );
			
			$this->description = $this->get_option( 'description' );
			$this->testmode = 'yes' === $this->get_option( 'testmode' );
			$this->test_client_id = $this->get_option( 'test_client_id' );
			$this->test_signature_url = $this->get_option( 'test_signature_url' );
			$this->test_payment_url = $this->get_option( 'test_payment_url' );
			$this->test_api_key = $this->get_option( 'test_api_key' );
			$this->live_api_key = $this->get_option( 'live_api_key' );
			$this->live_client_id = $this->get_option( 'live_client_id' );
			$this->live_signature_url = $this->get_option( 'live_signature_url' );
			$this->live_payment_url = $this->get_option( 'live_payment_url' );
			$this->enabled	= $this->is_valid_for_use() ? 'yes': 'no';

			//$this->private_key = $this->testmode ? $this->get_option( 'test_private_key' ) : $this->get_option( 'private_key' );
			//$this->publishable_key = $this->testmode ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'publishable_key' );
			add_action( 'woocommerce_api_wc_gateway_amberpay', array( $this, 'check_payment_notification_response' ) );
			// This action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// We need custom JavaScript to obtain a token
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
			add_action( 'woocommerce_receipt_amberpay', array( $this, 'receipt_page' ) );
		}

		/**
 		 * Plugin options, we deal with it in Step 3 too
 		 */
		public function init_form_fields(){

			$this->form_fields = array(
				'enabled' => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable Amberpay Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'yes'
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'Amber Pay',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'Payment method description that the customer will see on your website.',
					'default'     => 'Amber Pay',
				),
				'testmode' => array(
					'title'       => 'Sandbox mode',
					'label'       => 'Enable Sandbox Mode',
					'type'        => 'checkbox',
					'description' => 'Place the payment gateway in sandbox mode using sandbox API keys.',
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'test_client_id' => array(
					'title'       => 'Sandbox Client Id',
					'type'        => 'text'
				),
				'test_signature_url' => array(
					'title'       => 'Sandbox Signature Url',
					'type'        => 'text',
				),
				'test_payment_url' => array(
					'title'       => 'Sandbox Payment Url',
					'type'        => 'text',
				),
				'test_api_key' => array(
					'title'       => 'Sandbox Api Key',
					'type'        => 'text',
				),
				'live_client_id' => array(
					'title'       => 'Production Client Id',
					'type'        => 'text'
				),'live_signature_url' => array(
					'title'       => 'Production Signature Url',
					'type'        => 'text',
				),
				'live_payment_url' => array(
					'title'       => 'Production Payment Url',
					'type'        => 'text',
				),
				'live_api_key' => array(
					'title'       => 'Production Api Key',
					'type'        => 'text',
				)
			);
		}
		
		/**
		* This function checks if: 
		*  - the Client Id is specified
		*  - the Signature Url is specified
		*  - the Payment Url is specified
		*  - the Api Key is specified
		*
		* @since 1.0.0
		* @return bool
		*/
		public function is_valid_for_use() {
			$is_available          = false;
			
			if ( 'yes' === $this->get_option( 'testmode' ) ) {
				if (!isset($this->test_client_id) || ($this->test_client_id=='')) {
					echo '<div class="error amberpay-admin-message"><p>'
					. __( 'AmberPay WebPay requires the Sandbox Client Id in order to function.', 'amberpay-payments-woo' )
					. '</p></div>';	
				}
				
				if (!isset($this->test_signature_url) || ($this->test_signature_url=='')) {
					echo '<div class="error amberpay-admin-message"><p>'
					. __( 'AmberPay WebPay requires the Sandbox Signature URL in order to function.', 'amberpay-payments-woo' )
					. '</p></div>';	
				}
				
				if (!isset($this->test_payment_url) || ($this->test_payment_url=='')) {
					echo '<div class="error amberpay-admin-message"><p>'
					. __( 'AmberPay WebPay requires the Sandbox Payment Url in order to function.', 'amberpay-payments-woo' )
					. '</p></div>';	
				}
				
				if (!isset($this->test_api_key) || ($this->test_api_key=='')) {
					echo '<div class="error amberpay-admin-message"><p>'
					. __( 'AmberPay WebPay requires the Sandbox Api Key in order to function.', 'amberpay-payments-woo' )
					. '</p></div>';	
				}

				if ( isset($this->test_client_id) && isset($this->test_signature_url) && isset($this->test_payment_url) && isset($this->test_api_key)) {
					$is_available = true;
				}
			}else{
				if (!isset($this->live_client_id) || ($this->live_client_id=='')) {
					echo '<div class="error amberpay-admin-message"><p>'
					. __( 'AmberPay WebPay requires the Production Client Id in order to function.', 'amberpay-payments-woo' )
					. '</p></div>';	
				}
				
				if (!isset($this->live_signature_url) || ($this->live_signature_url=='')) {
					echo '<div class="error amberpay-admin-message"><p>'
					. __( 'AmberPay WebPay requires the Production Signature URL in order to function.', 'amberpay-payments-woo' )
					. '</p></div>';	
				}
				
				if (!isset($this->live_payment_url) || ($this->live_payment_url=='')) {
					echo '<div class="error amberpay-admin-message"><p>'
					. __( 'AmberPay WebPay requires the Production Payment Url in order to function.', 'amberpay-payments-woo' )
					. '</p></div>';	
				}
				
				if (!isset($this->live_api_key) || ($this->live_api_key=='')) {
					echo '<div class="error amberpay-admin-message"><p>'
					. __( 'AmberPay WebPay requires the Production Api Key in order to function.', 'amberpay-payments-woo' )
					. '</p></div>';	
				}

				if ( isset($this->live_client_id) && isset($this->live_signature_url) && isset($this->live_payment_url) && isset($this->live_api_key)) {
					$is_available = true;
				}
			}

			return $is_available;
		}
		
		/**
	 * This function sets up constants and general messages used by the amberPay WebPay.
	 *
	 * @since 1.0.0
	 */
	public function setup_constants() {
		// Create user agent string.
		define( 'IPG_SOFTWARE_NAME', 'WooCommerce' );
		define( 'IPG_SOFTWARE_VER', WC_VERSION );
		define( 'IPG_MODULE_NAME', 'WooCommerce-AmberPay-Gateway' );
		define( 'IPG_MODULE_VER', isset($this->version) ? $this->version : '' );

		
		// Features
		// - PHP
		$IPG_features = 'PHP ' . phpversion() . ';';
		// - cURL
		if ( in_array( 'curl', get_loaded_extensions() ) ) {
			define( 'IPG_CURL', '' );
			$IPG_version = curl_version();
			$IPG_features .= ' curl ' . $IPG_version['version'] . ';';
		} else {
			$IPG_features .= ' nocurl;';
		}
		// User agent
		define( 'IPG_USER_AGENT', IPG_SOFTWARE_NAME . '/' . IPG_SOFTWARE_VER . ' (' . trim( $IPG_features ) . ') ' . IPG_MODULE_NAME . '/' . IPG_MODULE_VER );
		


		// General Defines
		define( 'IPG_TIMEOUT', 15 );
		define( 'IPG_EPSILON', 0.01 );
 
		// Error Messages
		define( 'IPG_ERR_AMOUNT_MISMATCH', __( 'Amount mismatch', 'amberpay-payments-woo' ) );
		define( 'IPG_ERR_INVALID_DATA', __( 'Invalid or no data received', 'amberpay-payments-woo' ) );
		define( 'IPG_ERR_BAD_SOURCE_IP', __( 'Bad source IP address', 'amberpay-payments-woo' ) );
		define( 'IPG_ERR_INVALID_CHECKSUM', __( 'Security checksum mismatch', 'amberpay-payments-woo' ) );
		define( 'IPG_ERR_ORDER_ID_MISMATCH', __( 'Order ID mismatch', 'amberpay-payments-woo' ) );
		define( 'IPG_ERR_ORDER_PROCESSED', __( 'This order has already been processed', 'amberpay-payments-woo' ) );
		define( 'IPG_ERR_UNKNOWN', __( 'Unkown error occurred', 'amberpay-payments-woo' ) );

		//Set log header
		/*$this->log( PHP_EOL
			. '-----------------------------------------'
			. PHP_EOL . 'AmberpayPay WebPay setup'
			. PHP_EOL . '-----------------------------------------');
		$this->log( IPG_USER_AGENT );*/

		do_action( 'woocommerce_gateway_amberpay_setup_constants' );
	}

		/**
		 * You will need it if you want your custom credit card form, Step 4 is about it
		 */
		public function payment_fields() {
			if( $this->description ) {
				// Displaying the description below the payment option
				echo wpautop( wp_kses_post( $this->description ) );
			}
		}

		/*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
	 	public function payment_scripts() {


	
	 	}

		/*
 		 * Fields validation, more in Step 5
		 */
		public function validate_fields() {

		

		}

		/*
		 * We're processing the payments here, everything about it is in Step 5
		 */
		public function process_payment( $order_id ) {
			$order = new WC_Order( $order_id );
			return array(
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url( true )
			);
		}
	
		// here, prepare your form and submit it to the required URL
		public function receipt_page( $order_id ) {
			echo $this->generate_amberpay_form( $order_id );
		}

		/**
		 * This function generates the HTML Form for submission to the amberPay WebPay.
		 * 
		 * @since 1.0.0
		 */
		public function generate_amberpay_form( $order_id ) {
			$order = wc_get_order( $order_id );
			if ($this->get_option( 'testmode' ) == 'yes') {
				$ClientIdPay = $this->get_option( 'test_client_id' );
				$signature_url = $this->get_option( 'test_signature_url' );
				$payment_url = $this->get_option( 'test_payment_url' );
				$api_key = $this->get_option( 'test_api_key' );
			}else{
				$ClientIdPay = $this->get_option( 'live_client_id' );
				$signature_url = $this->get_option( 'live_signature_url' );
				$payment_url = $this->get_option( 'live_payment_url' );
				$api_key = $this->get_option( 'live_api_key' );
			}
			
			$amount =  $order->total;
			$transactionDatetime = time();
			$randomNumber = str_pad(random_int(1, 999), 3, '0', STR_PAD_LEFT);
			$transactionId = "{$transactionDatetime}{$randomNumber}";
			$order->set_transaction_id( $transactionId );
			$order->transaction_id = $transactionId;
			$signaturePay['ClientId'] = $ClientIdPay;
			$signaturePay['TransactionId'] = $order->transaction_id;
			$signaturePay['CurrencyCode'] = "USD";
			$signaturePay['Amount'] =  $amount;
			$authorization = "Authorization: Bearer ".$api_key; 
			
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $signature_url);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array($authorization ));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $signaturePay);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$output = curl_exec($ch);
			if (curl_errno($ch)) {
				$error_msg = curl_error($ch);
			}

			$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			 	
			if (isset($error_msg)) {
				echo '<div class="error amberpay-admin-message"><p>'. __( $error_msg, 'amberpay-payments-woo' ). '</p></div>';
			}

			// close curl resource to free up system resources
			curl_close($ch);

			if( $httpcode == 200) {

				$output = json_decode( $output );
				if($order->data["billing"]["email"]){
					$email = $order->data["billing"]["email"];
				}
				
				if($order->data["billing"]["phone"]){
					$mobile = $order->data["billing"]["phone"];
				}
				if($order->data["billing"]["first_name"] && $order->data["billing"]["last_name"]){
					$name = $order->data["billing"]["first_name"]." ".$order->data["billing"]["last_name"];
				}else if($order->data["billing"]["first_name"]){
					$name = $order->data["billing"]["first_name"];
				}
				if($order->data["billing"]["country"]){
					$country = $order->data["billing"]["country"];
				}
				if($order->data["billing"]["state"]){
					$state = $order->data["billing"]["state"];
				}
				if($order->data["billing"]["city"]){
					$city = $order->data["billing"]["city"];
				}
				if($order->data["billing"]["postcode"]){
					$postcode = $order->data["billing"]["postcode"];
				}
				if($order->data["billing"]["address_1"] && $order->data["billing"]["address_2"]){
					$address = $order->data["billing"]["address_1"]." ".$order->data["billing"]["address_2"];
				}else if($order->data["billing"]["address_1"]){
					$address = $order->data["billing"]["address_1"];
				}
				
				$order_key = self::get_order_prop( $order, 'order_key' );
				
				$return_url = get_site_option( 'siteurl' )."/checkout/order-received/".$order_id."/?key=".$order_key;

				// Build form structure and send
				$amberpay_args_array = array();
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("ClientId") . '" value="' . esc_attr( $ClientIdPay ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("TransactionId") . '" value="' . esc_attr( $transactionId ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("CurrencyCode") . '" value="' . esc_attr( "USD" ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("Amount") . '" value="' . esc_attr( $amount ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("Signature") . '" value="' . esc_attr( $output->Signature ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("ReturnToMerchant") . '" value="' . esc_attr( "Y" ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("AutoRedirect") . '" value="' . esc_attr( "Y" ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("CustomMessage") . '" value="' . esc_attr( $order_id ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("CustomerReference") . '" value="' . esc_attr( $order_key ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("PaymentMethod") . '" value="' . esc_attr( "N" ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("BillToEmail") . '" value="' . esc_attr( $email ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("BillToTelephone") . '" value="' . esc_attr( $mobile ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("CustomerInvoice") . '" value="' . esc_attr( "N" ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("BillToAddress") . '" value="' . $address . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("BillToCountry") . '" value="' . esc_attr( $country ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("BillToState") . '" value="' . esc_attr( $state ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("BillToFirstName") . '" value="' . esc_attr( $name ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("BillToCity") . '" value="' . esc_attr( $city ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("BillToZipPostCode") . '" value="' . esc_attr( $postcode ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("CardTokenize") . '" value="' . esc_attr( "N" ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("3DSFlag") . '" value="' . esc_attr( "N" ) . '" />';
				$amberpay_args_array[] = '<input type="hidden" name="' . esc_attr("ReturnUrl") . '" value="' . esc_attr( $return_url ) . '" />';
				return '<form action="' . esc_url( $payment_url ) . '" method="post" id="amberpay_payment_form">
						' . implode( '', $amberpay_args_array ) . '
						<input type="submit" class="button-alt" id="submit_amberpay_payment_form" value="' . __( 'Pay via amberPay') . '" style="border: 1px solid green;background: green;color: white;padding: 10px;border-radius: 5px;cursor: pointer;display:none;" />
						<a class="button cancel" href="' . $order->get_cancel_order_url() . '" style="margin-left: 10px;border: 1px solid gray;background: gray;color: white;padding: 10px;border-radius: 5px; text-decoration: none;display:none;">' . __( 'Cancel order &amp; restore cart') . '</a>
						<script type="text/javascript">
						jQuery(function(){
							jQuery("body").block(
								{
									message: "' . __( 'Thank you for your order. We are now redirecting you to amberPay WebPay to make payment.', 'amberpay-payments-woo' ) . '",
									overlayCSS:
									{
										background: "#fff",
										opacity: 0.6
									},
									css: {
										padding:        20,
										textAlign:      "center",
										color:          "#555",
										border:         "3px solid #aaa",
										backgroundColor:"#fff",
										cursor:         "wait"
									}
								});
							jQuery( "#submit_amberpay_payment_form" ).click();
						});
					</script>
					</form>';
			}else{
				echo '<div class="error amberpay-admin-message"><p>'
				. __( 'AmberPay WebPay requires the authorized signature in order to function.', 'amberpay-payments-woo' )
				. '</p></div>';
			}
		}
	
		/**
		 * This function gets the payment notification response from amberPay WebPay and acknowledge receipt.
		 *
		 * @since 1.0.0
		 */
		public function check_payment_notification_response() {
			
			// Decode data from JSON format
			$notification_data = json_decode(file_get_contents('php://input'), true);
			//$notification_data = stripslashes_deep( $_POST );
			//Set log header
			$this->log( PHP_EOL
						. '---------------------------------------------------'
						. PHP_EOL . 'AmberPay WebPay Notification Response Received'
						. PHP_EOL . '---------------------------------------------------');
						$this->log( 'Get received data' );
						$this->log( 'AmberPay WebPay Data: ' . print_r( $notification_data, true ) );
		
			// Set up variables
			$order_id			= $notification_data['CustomMessage'];
			$order				= wc_get_order( $order_id );
			$order_key			= wc_clean( $notification_data['CustomerReference'] );
			$amberpay_error	= false;
			$amberpay_done		= false;
			$original_order		= $order;
			
			// Check that notification data exists
			if ( false === $notification_data ) {
				$amberpay_error  = true;
				$amberpay_error_message = IPG_ERR_INVALID_DATA;
			}
					
			// Verify source IP (If not in debug mode)
			/* if ( ! $amberpay_error && ! $amberpay_done && $this->get_option( 'testmode' ) != 'yes' ) {
				$this->log( 'Verifying source IP' );
				$this->log( 'Source IP = ' .  $_SERVER['REMOTE_ADDR'] );

				if ( ! $this->is_valid_ip( $_SERVER['REMOTE_ADDR'] ) ) {
					$amberpay_error			= true;
					$amberpay_error_message = IPG_ERR_BAD_SOURCE_IP;
				}
			} */
			
			// Check data against internal order
			if ( ! $amberpay_error && ! $amberpay_done ) {
				$this->log( 'Checking received data against internal order data' );

				// Check order amount
				if ( ! $this->amounts_equal( $notification_data['AmountCharged'], self::get_order_prop( $order, 'order_total' ) ) ) {
					$this->log( 'Notification amount = ' . $notification_data['AmountCharged'] );
					$this->log( 'Original order amount = ' . self::get_order_prop( $order, 'order_total' ) );
					$amberpay_error  = true;
					$amberpay_error_message = IPG_ERR_AMOUNT_MISMATCH;
				} elseif ( strcasecmp( $notification_data['CustomMessage'], self::get_order_prop( $order, 'id' ) ) != 0 ) {
					// Check order ID
					$this->log( 'ID mismatch indicator = ' . strcasecmp( $notification_data['CustomMessage'], self::get_order_prop( $order, 'id' )) );
					$this->log( 'Notification payee reference = ' . substr($notification_data['CustomMessage'],3) );
					$this->log( 'Original order id = ' . self::get_order_prop( $order, 'id' ) );
					$amberpay_error  = true;
					$amberpay_error_message = IPG_ERR_ORDER_ID_MISMATCH;
				}
			}

			// Get internal order and verify it hasn't already been processed
			if ( ! $amberpay_error && ! $amberpay_done ) {
				$this->log_order_details( $order );

				// Check if order has already been processed
				if ( 'completed' === self::get_order_prop( $order, 'status' ) ) {
					$this->log( 'Order has already been processed' );
					$amberpay_done = true;
				}
			}

			// If an error occurred send debug email and log error condition
			if ( $amberpay_error ) {
				$this->log( 'Error occurred during payment notification validation: ' . $amberpay_error_message );				
			} elseif ( ! $amberpay_done ) {

				$this->log( 'Check status and update order' );

				if ( $order_key !== self::get_order_prop( $original_order, 'order_key' ) ) {
					$this->log( 'Order key does not match' );
					exit;
				}

				$status = strtolower( $notification_data['PaymentStatus'] );

				$this->log( 'Request Status: '. $status );

				if ( 'success' === $status ) {
					$this->handle_amberpay_payment_complete( $notification_data, $order );
				}elseif ( 'failed' === $status ) {
					$this->handle_amberpay_payment_failed( $notification_data, $order );
				}

			} // End if().

			$this->log( PHP_EOL
				. '---------------------------------------------------'
				. PHP_EOL . 'End AmberPay WebPay Payment Notification Response Validation '
				. PHP_EOL . '---------------------------------------------------'
			);

			// Notify amberPay WebPay that information has been received and valid
			header( 'HTTP/1.0 200 OK' );
			flush();
		}
		
		/**
		 * This function handles payment complete response from amberPay WebPay.
		 * 
		 * @since 1.0.0
		 * @param $data
		 * @param $order
		 */
		 public function handle_amberpay_payment_complete( $data, $order ) {
			$this->log( '- Complete' );
			$order->add_order_note( __( 'AmberPay WebPay payment completed', 'amberpay-payments-woo' ) );
			$order_id = $order->get_id();

			// Set order status for processing
			$order->payment_complete();
			
			// Remove cart.
			WC()->cart->empty_cart();
		}

		/**
		 * This function handles payment failed response from amberPay WebPay.
		 * 
		 * @since 1.0.0
		 * @param $data
		 * @param $order
		 */
		public function handle_amberpay_payment_failed( $data, $order ) {
			$this->log( '- Failed' );
			/* translators: 1: payment status */
			$order->update_status( 'failed', sprintf( __( 'Payment %s via amberPay WebPay.', 'amberpay-payments-woo' ), strtolower( sanitize_text_field( $data['requestStatus'] ) ) ) );
		}

		/**
		* This function handles logging the order details.
		* 
		* @since 1.0.0
		* @param $order
		*/
		public function log_order_details( $order ) {
			if ( version_compare( WC_VERSION,'3.0.0', '<' ) ) {
				$customer_id = get_post_meta( $order->get_id(), '_customer_user', true );
			} else {
				$customer_id = self::get_order_prop( $order, 'user_id' );
			}

			$details = "Order Details:"
			. PHP_EOL . 'Customer ID:  ' . $customer_id
			. PHP_EOL . 'Order ID:     ' . self::get_order_prop( $order, 'id' )
			. PHP_EOL . 'Parent ID:    ' . self::get_order_prop( $order, 'parent_id' )		//$order->get_parent_id()
			. PHP_EOL . 'Status:       ' . self::get_order_prop( $order, 'status' ) 		//$order->get_status()
			. PHP_EOL . 'Total:        ' . self::get_order_prop( $order, 'order_total' ) 	//$order->get_total()
			. PHP_EOL . 'Currency:     ' . self::get_order_prop( $order, 'currency' )		//$order->get_currency()
			. PHP_EOL . 'Key:          ' . self::get_order_prop( $order, 'order_key' )		//$order->get_order_key()
			. "";

			$this->log( $details );
		}
		
		/**
		 * Get order property with compatibility check on order getter introduced
		 * in WC 3.0.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Order $order Order object.
		 * @param string   $prop  Property name.
		 *
		 * @return mixed Property value
		 */
		public static function get_order_prop( $order, $prop ) {
			switch ( $prop ) {
				case 'order_total':
					$getter = array( $order, 'get_total' );
					break;
				default:
					$getter = array( $order, 'get_' . $prop );
					break;
			}

			return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
		}
		
		/**
		 * This function logs system processes.
		 * @since 1.0.0
		 */
		public function log( $message ) {
			if ( 'yes' === $this->get_option( 'testmode' ) || isset($this->enable_logging) ) {
				if ( empty( $this->logger ) ) {
					$this->logger = new WC_Logger();
				}
				$this->logger->add( 'AmberPay_WebPay_log', $message );
			}
		}
		
		/**
		* amounts_equal()
		*
		* Checks to see whether the given amounts are equal using a proper floating
		* point comparison with an Epsilon which ensures that insignificant decimal
		* places are ignored in the comparison.
		*
		* eg. 100.00 is equal to 100.0001
		*
		* @param $amount1 Float 1st amount for comparison
		* @param $amount2 Float 2nd amount for comparison
		* @since 1.0.0
		* @return bool
		*/
		public function amounts_equal( $amount1, $amount2 ) {
			return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > IPG_EPSILON );
		}

		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		public function webhook() {
		}

 	}
}
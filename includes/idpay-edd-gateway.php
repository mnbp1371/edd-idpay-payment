<?php

define( 'IDPAY_EDD_GATEWAY', 'idpay_edd_gateway' );

// registers the gateway
function idpay_edd_register_gateway( $gateways ) {
	if ( ! isset( $_SESSION ) ) {
		session_start();
	}

	$gateways[ IDPAY_EDD_GATEWAY ] = array(
		'admin_label'    => __( 'IDPay', 'idpay-for-edd' ),
		'checkout_label' => __( 'IDPay payment gateway', 'idpay-for-edd' ),
	);

	return $gateways;
}

/**
 * Hooks a function into the filter 'edd_payment_gateways' which is defined by
 * the EDD's core plugin.
 */
add_filter( 'edd_payment_gateways', 'idpay_edd_register_gateway' );

/**
 * Adds noting to the credit card form in the checkout page. In the other hand,
 * We want to disable the credit card form in the checkout.
 *
 * Therefore we just return.
 */
function idpay_edd_gateway_cc_form() {
	return;
}

/**
 * Hooks into edd_{payment gateway ID}_cc_form which is defined
 * by the EDD's core plugin.
 */
add_action( 'edd_' . IDPAY_EDD_GATEWAY . '_cc_form', 'idpay_edd_gateway_cc_form' );

/**
 * Adds the IDPay gateway settings to the Payment Gateways section.
 *
 * @param $settings
 *
 * @return array
 */
function idpay_edd_add_settings( $settings ) {

	$idpay_gateway_settings = array(
		array(
			'id'   => 'idpay_edd_gateway_settings',
			'type' => 'header',
			'name' => __( 'IDPay payment gateway', 'idpay-for-edd' ),
		),
		array(
			'id'   => 'idpay_api_key',
			'type' => 'text',
			'name' => 'API Key',
			'size' => 'regular',
			'desc' => __( 'You can create an API Key by going to your <a href="https://idpay.ir/dashboard/web-services">IDPay account</a>.', 'idpay-for-edd' ),
		),
		array(
			'id'      => 'idpay_sandbox',
			'type'    => 'checkbox',
			'name'    => __( 'Sandbox', 'idpay-for-edd' ),
			'default' => 0,
			'desc'    => __( 'If you check this option, the gateway will work in Test (Sandbox) mode.', 'idpay-for-edd' ),
		),
	);

	return array_merge( $settings, $idpay_gateway_settings );
}

/**
 * Hooks a function into the filter 'edd_settings_gateways' which is defined by
 * the EDD's core plugin.
 */
add_filter( 'edd_settings_gateways', 'idpay_edd_add_settings' );

/**
 * Creates a payment on the gateway.
 * See https://idpay.ir/web-service for more information.
 *
 * @param $purchase_data
 *  The argument which will be passed to
 *  the hook edd_gateway_{payment gateway ID}
 *
 * @return bool
 */
function idpay_edd_create_payment( $purchase_data ) {
	global $edd_options;

	$payment_data = array(
		'price'        => $purchase_data['price'],
		'date'         => $purchase_data['date'],
		'user_email'   => $purchase_data['user_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency'     => $edd_options['currency'],
		'downloads'    => $purchase_data['downloads'],
		'user_info'    => $purchase_data['user_info'],
		'cart_details' => $purchase_data['cart_details'],
		'status'       => 'pending',
	);

	// record the pending payment
	$payment_id = edd_insert_payment( $payment_data );

	if ( empty( $payment_id ) ) {
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}

	$api_key = empty( $edd_options['idpay_api_key'] ) ? '' : $edd_options['idpay_api_key'];
	$sandbox = empty( $edd_options['idpay_sandbox'] ) ? 'false' : 'true';

	$amount   = idpay_edd_get_amount( intval( $purchase_data['price'] ), edd_get_currency() );
	$desc     = __( 'Order number #', 'idpay-for-edd' ) . $payment_id;
	$callback = add_query_arg( 'verify_idpay_edd_gateway', '1', get_permalink( $edd_options['success_page'] ) );

	if ( empty( $amount ) ) {
		$message = __( 'Selected currency is not supported.', 'idpay-for-edd' );
		edd_insert_payment_note( $payment_id, $message );
		edd_update_payment_status( $payment_id, 'failed' );
		edd_set_error( 'idpay_connect_error', $message );
		edd_send_back_to_checkout();

		return FALSE;
	}

	$data = array(
		'order_id' => $payment_id,
		'amount'   => $amount,
		'phone'    => '',
		'desc'     => $desc,
		'callback' => $callback,
	);

	$headers = array(
		'Content-Type' => 'application/json',
		'X-API-KEY'    => $api_key,
		'X-SANDBOX'    => $sandbox,
	);

	$args        = array(
		'body'    => json_encode( $data ),
		'headers' => $headers,
	);
	$response    = wp_safe_remote_post( 'https://api.idpay.ir/v1/payment', $args );
	$http_status = wp_remote_retrieve_response_code( $response );
	$result      = wp_remote_retrieve_body( $response );
	$result      = json_decode( $result );

	if ( $http_status != 201 || empty( $result ) || empty( $result->link ) ) {
		$message = $result->error_message;
		edd_insert_payment_note( $payment_id, $http_status . ' - ' . $message );
		edd_update_payment_status( $payment_id, 'failed' );
		edd_set_error( 'idpay_connect_error', $message );
		edd_send_back_to_checkout();

		return FALSE;
	}

	//save id and link
	edd_insert_payment_note( $payment_id, __( 'Transaction ID: ', 'idpay-for-edd' ) . $result->id );
	edd_insert_payment_note( $payment_id, 'Redirecting to the payment gateway.', 'idpay-for-edd' );
	edd_update_payment_meta( $payment_id, 'idpay_payment_id', $result->id );
	edd_update_payment_meta( $payment_id, 'idpay_payment_link', $result->link );

	$_SESSION['idpay_payment'] = $payment_id;

	wp_redirect( $result->link );
}

/**
 * Hooks into edd_gateway_{payment gateway ID} which is defined in the
 * EDD's core plugin.
 */
add_action( 'edd_gateway_' . IDPAY_EDD_GATEWAY, 'idpay_edd_create_payment' );

/**
 * Holds an inquiry for the payment created on the gateway.
 *
 * See https://idpay.ir/web-service for more information.
 */
function idpay_edd_hold_inquiry() {
	global $edd_options;

	if ( empty( $_POST['id'] ) || empty( $_POST['order_id'] ) ) {
		return FALSE;
	}

	$payment = edd_get_payment( $_SESSION['idpay_payment'] );
	unset( $_SESSION['idpay_payment'] );

	if ( ! $payment ) {
		wp_die( __( 'The information sent is not correct.', 'idpay-for-edd' ) );
	}

	if ( $payment->status != 'pending' ) {
		return FALSE;
	}

	$api_key = empty( $edd_options['idpay_api_key'] ) ? '' : $edd_options['idpay_api_key'];
	$sandbox = empty( $edd_options['idpay_sandbox'] ) ? 'false' : 'true';

	$data = array(
		'id'       => $_POST['id'],
		'order_id' => $payment->ID,
	);

	$headers = array(
		'Content-Type' => 'application/json',
		'X-API-KEY'    => $api_key,
		'X-SANDBOX'    => $sandbox,
	);

	$args = array(
		'body'    => json_encode( $data ),
		'headers' => $headers,
	);

	$response    = wp_safe_remote_post( 'https://api.idpay.ir/v1/payment/inquiry', $args );
	$http_status = wp_remote_retrieve_response_code( $response );
	$result      = wp_remote_retrieve_body( $response );
	$result      = json_decode( $result );

	if ( $http_status != 200 ) {
		$message = $result->error_message;
		edd_insert_payment_note( $payment->ID, $http_status . ' - ' . $message );
		edd_update_payment_status( $payment->ID, 'failed' );
		edd_set_error( 'idpay_connect_error', $message );
		edd_send_back_to_checkout();

		return FALSE;
	}

	edd_insert_payment_note( $payment->ID, __( 'IDPay tracking id: ', 'idpay-for-edd' ) . $result->track_id );
	edd_insert_payment_note( $payment->ID, $result->status . ' - ' . idpay_edd_get_inquiry_status_message( $result->status ) );
	edd_insert_payment_note( $payment->ID, __( 'Payer card number: ', 'idpay-for-edd' ) . $result->card_no );
	edd_update_payment_meta( $payment->ID, 'idpay_track_id', $result->track_id );
	edd_update_payment_meta( $payment->ID, 'idpay_status', $result->status );
	edd_update_payment_meta( $payment->ID, 'idpay_payment_card_no', $result->card_no );

	if ( $result->status == 100 ) {
		edd_empty_cart();
		edd_update_payment_status( $payment->ID, 'publish' );
		edd_send_to_success_page();
	} else {
		edd_update_payment_status( $payment->ID, 'failed' );
		wp_redirect( get_permalink( $edd_options['failure_page'] ) );
	}
}

/**
 * Hooks into our custom hook in order to verifying the payment.
 */
add_action( 'idpay_edd_inquiry', 'idpay_edd_hold_inquiry' );

/**
 * Helper function to obtain the amount by considering whether a unit price is
 * in Iranian Rial Or Iranian Toman unit.
 *
 * As the IDPay gateway accepts orders with IRR unit price, We must convert
 * Tomans into Rials by multiplying them by 10.
 *
 * @param $amount
 * @param $currency
 *
 * @return float|int
 */
function idpay_edd_get_amount( $amount, $currency ) {
	switch ( strtolower( $currency ) ) {
		case strtolower( 'IRR' ):
		case strtolower( 'RIAL' ):
			return $amount;

		case strtolower( 'تومان ایران' ):
		case strtolower( 'تومان' ):
		case strtolower( 'IRT' ):
		case strtolower( 'Iranian_TOMAN' ):
		case strtolower( 'Iran_TOMAN' ):
		case strtolower( 'Iranian-TOMAN' ):
		case strtolower( 'Iran-TOMAN' ):
		case strtolower( 'TOMAN' ):
		case strtolower( 'Iran TOMAN' ):
		case strtolower( 'Iranian TOMAN' ):
			return $amount * 10;

		case strtolower( 'IRHT' ):
			return $amount * 10000;

		case strtolower( 'IRHR' ):
			return $amount * 1000;

		default:
			return 0;
	}
}

/**
 * Helper function to the obtain gateway messages at the inquiry endpoint
 * according to their codes.
 *
 * for more information refer to the gateway documentation:
 * https://idpay.ir/web-service
 *
 * @param $code
 *
 * @return string
 */
function idpay_edd_get_inquiry_status_message( $code ) {
	switch ( $code ) {
		case 1:
			return __( 'Payment has not been made.', 'idpay-for-edd' );

		case 2:
			return __( 'Payment has been unsuccessful.', 'idpay-for-edd' );

		case 3:
			return __( 'An error occurred.', 'idpay-for-edd' );

		case 100:
			return __( 'Payment has been confirmed.', 'idpay-for-edd' );

		default:
			return __( 'The code has not been defined.', 'idpay-for-edd' );
	}
}

/**
 * Listen to incoming queries
 *
 * @return void
 */
function listen() {
	if ( isset( $_GET[ 'verify_' . IDPAY_EDD_GATEWAY ] ) && $_GET[ 'verify_' . IDPAY_EDD_GATEWAY ] ) {

		// Executes the function(s) hooked into our custom hook for verifying the payment.
		do_action( 'idpay_edd_inquiry' );
	}
}

/**
 * Hooks the listen() function into the Wordpress initializing process.
 */
add_action( 'init', 'listen' );

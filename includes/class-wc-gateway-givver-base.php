<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base gateway containing common settings & helpers.
 */
abstract class WC_Gateway_Givver_Base extends WC_Payment_Gateway {

    /**
     * Debug.
     *
     * @var bool
     */
    public $debug;

    /**
     * Publishable key (live).
     *
     * @var string
     */
    public $publishable_key;

    /**
     * Publishable key (test).
     *
     * @var string
     */
    public $test_publishable_key;

    /**
     * Secret key (live, Admin API).
     *
     * @var string
     */
    public $secret_key;

    /**
     * Secret key (test, Admin API).
     *
     * @var string
     */
    public $test_secret_key;

    /**
     * Test mode.
     *
     * @var string yes|no
     */
    public $testmode;


    /**
     * Double-redirect flow
     *
     * @var string yes|no
     */
    public $payment_redirection_flow;
    /**
     * Capture type: capture or authorize.
     *
     * @var string
     */
    public $capture_type;

    /**
     * API client instance (per request).
     *
     * @var Givver_API_Client|null
     */
    protected $api_client;

    /**
     * Givver api endpoint.
     *
     * @var Givver_API_Client|null
     */
    protected $api_endpoint;

    public function __construct() {
        $this->supports = array(
            'products',
            'refunds'
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title                = $this->get_option( 'title' );
        $this->description          = $this->get_option( 'description' );
        $this->enabled              = $this->get_option( 'enabled', 'no' );
        $this->testmode             = $this->get_option( 'testmode', 'no' );
        $this->payment_redirection_flow = "yes";
        $this->publishable_key      = $this->get_option( 'publishable_key' );
        $this->test_publishable_key = $this->get_option( 'test_publishable_key' );
        $this->secret_key           = $this->get_option( 'secret_key' );
        $this->test_secret_key      = $this->get_option( 'test_secret_key' );
        $this->capture_type         = 'capture';
        $this->api_endpoint         = $this->get_option( 'api_endpoint', 'https://api.givver.cards' );
        $this->debug                = 'yes' === $this->get_option( 'debug', 'no' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    /**
     * Default settings.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'givver-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this payment method', 'givver-woocommerce' ),
                'default' => 'no',
            ),
            'title'   => array(
                'title'       => __( 'Title', 'givver-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Title seen by customers at checkout.', 'givver-woocommerce' ),
                'default'     => __( 'Credit Card', 'givver-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'givver-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Description seen by customers at checkout.', 'givver-woocommerce' ),
                'default'     => __( 'For card payments and your security, click the “Place Order” button below to seamlessly complete your order through a private, encrypted verified card payment processor.', 'givver-woocommerce' ),
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __( 'Sandbox / Test Mode', 'givver-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Test Mode (use test keys & sandbox store)', 'givver-woocommerce' ),
                'default'     => 'no',
                'description' => __( 'Use your Givver sandbox store & test card numbers while this is enabled.', 'givver-woocommerce' ),
            ),
            'publishable_key' => array(
                'title'       => __( 'Live Publishable API Key', 'givver-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your Givver live publishable API key (pk_...).', 'givver-woocommerce' ),
                'default'     => '',
            ),
            'secret_key' => array(
                'title'       => __( 'Live Secret API Key (Admin API)', 'givver-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your Givver live Secret API key (sk_...). Used for admin / payouts.', 'givver-woocommerce' ),
                'default'     => '',
            ),
            'test_publishable_key' => array(
                'title'       => __( 'Test Publishable API Key', 'givver-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your Givver test publishable API key for sandbox.', 'givver-woocommerce' ),
                'default'     => '',
            ),
            'test_secret_key' => array(
                'title'       => __( 'Test Secret API Key (Admin API)', 'givver-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your Givver test Secret API key for sandbox Admin API.', 'givver-woocommerce' ),
                'default'     => '',
            ),
            'developers_section' => array(
                'title'       => __( 'Developers Settings', 'givver-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Developers related settings', 'givver-woocommerce' ),
            ),
            'api_endpoint' => array(
                'title'       => __( 'API Endpoint', 'givver-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your Givver API endpoint.', 'givver-woocommerce' ),
                'default'     => 'https://api.givver.cards',
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'givver-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging (logs to WooCommerce > Status > Logs)', 'givver-woocommerce' ),
                'default'     => 'no',
            ),
        );
    }

    /**
     * Get API client.
     *
     * @return Givver_API_Client
     */
    public function get_api_client() {
        $use_test = ( 'yes' === $this->testmode );
        $pub_key  = $use_test ? $this->test_publishable_key : $this->publishable_key;
        $sec_key  = $use_test ? $this->test_secret_key : $this->secret_key;
        $endpoint = untrailingslashit( $this->api_endpoint );

        if ( $this->api_client instanceof Givver_API_Client ) {
            $this->api_client->set_testmode( $use_test );
            $this->api_client->set_publishable_key( $pub_key );
            $this->api_client->set_secret_key( $sec_key );
            $this->api_client->set_endpoint( $endpoint );
            $this->api_client->set_admin_api_endpoint( trailingslashit( $endpoint ) . 'admin' );
            return $this->api_client;
        }

        $this->api_client = new Givver_API_Client( $endpoint, $pub_key, $sec_key, $use_test );

        return $this->api_client;
    }

    /**
     * Return current publishable key (used in JS).
     *
     * @return string
     */
    public function get_publishable_key() {
        $use_test = ( 'yes' === $this->testmode );
        return $use_test ? $this->test_publishable_key : $this->publishable_key;
    }

    /**
     * Helper: log.
     */
    protected function log( $message, $level = 'info' ) {
        if ( ! $this->debug ) {
            return;
        }
        Givver_Logger::log( '[' . $this->id . '] ' . $message, $level );
    }

    /**
     * Utility to build customer object from order.
     *
     * @param WC_Order $order
     * @return array
     */
    protected function build_customer_from_order( $order ) {
        return array(
            'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        );
    }

    /**
     * Build billing address object from order.
     *
     * @param WC_Order $order
     * @return array
     */
    protected function build_billing_address_from_order( $order ) {
        return array(
            'street'      => $order->get_billing_address_1(),
            'unit'        => $order->get_billing_address_2(),
            'city'        => $order->get_billing_city(),
            'state'       => $order->get_billing_state(),
            'zipCode'     => $order->get_billing_postcode(),
            'countryCode' => $order->get_billing_country(),
            'phone'       => $order->get_billing_phone(),
        );
    }

    /**
     * Remember the amount, currency, and environment sent to Givver.
     * The payment webhook must match this snapshot.
     *
     * @param WC_Order   $order
     * @param float      $amount
     * @param string     $currency
     */
    protected function store_payment_expectation( $order, $amount, $currency ) {
        $mode = ( 'yes' === $this->testmode ) ? 'test' : 'live';
        $order->update_meta_data( '_givver_amount', (string) (float) $amount );
        $order->update_meta_data( '_givver_currency', strtolower( (string) $currency ) );
        $order->update_meta_data( '_givver_environment', $mode );
    }

    /**
     * Common refund handler.
     *
     * @param int    $order_id
     * @param float  $amount
     * @param string $reason
     *
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'givver_no_order', __( 'Order not found.', 'givver-woocommerce' ) );
        }

        $givver_order_id = $order->get_meta( '_givver_order_id' );
        if ( ! $givver_order_id ) {
            return new WP_Error( 'givver_no_remote_order', __( 'Givver transaction not found for this order.', 'givver-woocommerce' ) );
        }

        $this->log( "Refund requested for order {$order->get_id()}, Givver orderId {$givver_order_id}, amount {$amount}" );

        $api      = $this->get_api_client();
        $response = $api->refund( $givver_order_id, $amount );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $order->add_order_note( sprintf( __( 'Givver refund processed. Reason: %s', 'givver-woocommerce' ), $reason ) );

        return true;
    }
}


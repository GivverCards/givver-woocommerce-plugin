<?php
/**
 * Plugin Name: Givver Payment Gateway for WooCommerce
 * Description: Givver payment gateway for WooCommerce (Credit Cards).
 * Author: Givver
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GIVVER_WC_VERSION', '1.0.0' );
define( 'GIVVER_WC_PLUGIN_FILE', __FILE__ );
define( 'GIVVER_WC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GIVVER_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize the gateway after WooCommerce is loaded.
 */
function givver_wc_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    // Includes.
    require_once GIVVER_WC_PLUGIN_PATH . 'includes/class-givver-logger.php';
    require_once GIVVER_WC_PLUGIN_PATH . 'includes/class-givver-api-client.php';
    require_once GIVVER_WC_PLUGIN_PATH . 'includes/class-givver-webhook.php';
    require_once GIVVER_WC_PLUGIN_PATH . 'includes/class-wc-gateway-givver-base.php';
    require_once GIVVER_WC_PLUGIN_PATH . 'includes/class-wc-gateway-givver-card.php';
    require_once GIVVER_WC_PLUGIN_PATH . 'includes/class-wc-gateway-givver-bank.php';

    // Register gateways.
    add_filter( 'woocommerce_payment_gateways', 'givver_wc_register_gateways' );

    // Assets on checkout/pay page.
    add_action( 'wp_enqueue_scripts', 'givver_wc_enqueue_assets' );

    // HPOS support
    // https://woocommerce.com/document/high-performance-order-storage/
    // https://webkul.com/blog/woocommerce-plugin-high-performance-order-storage-compatible/
    add_action('before_woocommerce_init', function() {
        // wc_get_logger()->info('before_woocommerce_init1', ['source' => 'givver']);
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables', // HPOS feature name
                __FILE__,              // your main plugin file
                true                   // true = compatible, false = incompatible
            );
        }
    });

    // That hook injects JavaScript into the page footer to refresh the checkout when the payment method is changed
    add_action( 'wp_footer', 'givver_refresh_checkout_on_payment_change' );
}
add_action( 'plugins_loaded', 'givver_wc_init', 20 );
add_action( 'woocommerce_init', 'givver_maybe_ensure_webhook', 20 );

register_activation_hook(__FILE__, 'givver_activate');

/**
 * Register Givver gateways with WooCommerce.
 *
 * @param array $gateways
 * @return array
 */
function givver_wc_register_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_Givver_Card';

    return $gateways;
}

/**
 * Enqueue frontend assets.
 */
function givver_wc_enqueue_assets() {
    if ( ! function_exists( 'is_checkout' ) ) {
        return;
    }

    if ( ! ( is_checkout() || is_checkout_pay_page() ) ) {
        return;
    }

    wp_enqueue_style(
        'givver-checkout-css',
        GIVVER_WC_PLUGIN_URL . 'assets/css/givver-checkout.css',
        array(),
        GIVVER_WC_VERSION
    );

    wp_enqueue_script(
        'givver-checkout-utils-js',
        GIVVER_WC_PLUGIN_URL . 'assets/js/givver-checkout-utils.js',
        array(),
        GIVVER_WC_VERSION,
        true
    );

    wp_enqueue_script(
        'givver-checkout-js',
        GIVVER_WC_PLUGIN_URL . 'assets/js/givver-checkout.js',
        array( 'jquery', 'givver-checkout-utils-js' ),
        GIVVER_WC_VERSION,
        true
    );

    // Load 3ds script.
    // TODO: maybe load it only if 3DS enabled?.
    wp_enqueue_script(
      'givver-gateway-js',
      'https://secure.networkmerchants.com/js/v1/Gateway.js',
      array(),
      null,
      true // load in footer
    );

    // Get active gateways and their settings.
    $gateways = WC()->payment_gateways() ? WC()->payment_gateways->payment_gateways() : array();

    /** @var WC_Gateway_Givver_Card|null $card_gateway */
    $card_gateway     = isset( $gateways['givver_card'] ) ? $gateways['givver_card'] : null;

    $cart_total = 0;
    if ( function_exists( 'WC' ) && WC()->cart ) {
        $cart_total = (float) WC()->cart->get_total( 'edit' );
    }

    $params = array(
        'ajax_url'           => admin_url( 'admin-ajax.php' ),
        'checkout_url'       => WC_AJAX::get_endpoint( 'checkout' ),
        'card_gateway_id'    => 'givver_card',
        'is_checkout'        => is_checkout() ? 'yes' : 'no',
        'is_pay_for_order'   => is_checkout_pay_page() ? 'yes' : 'no',
        'cart_total'         => $cart_total,
        'currency'           => get_woocommerce_currency(),
        'card_publishable'   => $card_gateway ? $card_gateway->get_publishable_key() : '',
        'enable_3ds'        => 'no',
        '3ds_mount_element_selector' => '',
        'card_testmode'      => $card_gateway && 'yes' === $card_gateway->get_option( 'testmode', 'no' ) ? 'yes' : 'no',
        'payment_redirection_flow' => $card_gateway->payment_redirection_flow,
        'api_endpoint'       => $card_gateway ? $card_gateway->get_option( 'api_endpoint' ) : 'https://api.givver.cards',
        'i18n'               => array(
            'card_error'       => __( 'Unable to process your card. Please check the details and try again.', 'givver-woocommerce' ),
            'card_required'    => __( 'Please fill in all required card fields.', 'givver-woocommerce' ),
        ),
        'version'            => GIVVER_WC_VERSION,
    );

    wp_localize_script( 'givver-checkout-js', 'givver_wc_params', $params );
}

function givver_bank_transfer_discount( $cart ) {
    //wc_get_logger()->info('givver_bank_transfer_discount', ['source' => 'givver-woocommerce']);

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        // $this->log( 'givver_bank_transfer_discount is_admin && ! defined( DOING_AJAX )', 'info' );
        return;
    }

    if ( ! WC()->session ) {
        // $this->log( 'givver_bank_transfer_discount WC()->session not found', 'info' );
        return;
    }

    $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );

    if ( $chosen_payment_method === 'givver_bank' ) {

        // 5% from subtotal (products only)
        $discount = $cart->get_subtotal() * 0.05;
        // $this->log( 'givver_bank_transfer_discount discount: ' . $discount, 'info' );

        if ( $discount > 0 ) {
            $cart->add_fee('5% Pay-By-Bank Discount', -$discount );
        }
    }
}

function givver_refresh_checkout_on_payment_change() {
    if ( ! is_checkout() ) {
        return;
    }
    ?>
    <script type="text/javascript">
        jQuery(function($){
            $('form.checkout').on('change', 'input[name="payment_method"]', function(){
                $('body').trigger('update_checkout');
            });
        });
    </script>
    <?php
}

function givver_activate() {
    // enable Givver gateways by default
    $gateways = [ 'givver_card' ];

    foreach ($gateways as $gateway) {

        $option_key = "woocommerce_{$gateway}_settings";

        if (!get_option($option_key)) {
            add_option($option_key, [
                'enabled' => 'yes'
            ]);
        }
    }
}

/**
 * Persist the Givver webhook signing secret and required events after install/upgrade.
 * No-ops while the stored endpoint matches the webhook URL, the current Secret API key, and the
 * payment.succeeded / payment.failed subscription.
 */
function givver_maybe_ensure_webhook() {
    if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
        return;
    }

    if ( get_transient( 'givver_wc_webhook_ensure_lock' ) ) {
        return;
    }

    $gateways = WC()->payment_gateways()->payment_gateways();
    if ( empty( $gateways['givver_card'] ) || ! is_object( $gateways['givver_card'] ) ) {
        return;
    }

    if ( ! method_exists( $gateways['givver_card'], 'ensure_webhook' ) ) {
        return;
    }

    $ok = $gateways['givver_card']->ensure_webhook( false );
    if ( ! $ok ) {
        set_transient( 'givver_wc_webhook_ensure_lock', 1, 10 * MINUTE_IN_SECONDS );
    }
}


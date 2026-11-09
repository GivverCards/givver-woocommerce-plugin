<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Givver Card gateway.
 */
class WC_Gateway_Givver_Card extends WC_Gateway_Givver_Base {

    /**
     * Prevents rendering the confirmation popup more than once per request.
     *
     * @var bool
     */
    private static $popup_rendered = false;

    /**
     * Events this plugin must receive on the store webhook.
     *
     * @var string[]
     */
    const WEBHOOK_EVENTS = array( 'payment.succeeded', 'payment.failed' );

    public function __construct() {
        $this->id                 = 'givver_card';
        $this->method_title       = __( 'Givver – Credit Card', 'givver-woocommerce' );
        $this->method_description = __( 'Pay securely with credit/debit cards via Givver.', 'givver-woocommerce' );
        $this->has_fields         = true;
        parent::__construct();

        add_action('woocommerce_api_wc_gateway_givver_card_success_url', [$this, 'handle_return']);
        add_action('woocommerce_api_wc_gateway_givver_card_success_url_webhook', [$this, 'handle_webhook']);
        add_action('woocommerce_api_givver_order_status', [$this, 'ajax_order_status']);

        add_action( 'woocommerce_before_thankyou', array( $this, 'show_order_received_popup' ), 10, 1 );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options'] );
    }

    public function process_admin_options() {
        $this->log( 'process_admin_options', 'info' );

        parent::process_admin_options();
        $this->load_settings();

        $this->ensure_webhook( false, true );
    }

    /**
     * Register the Givver webhook and persist the one-time signing secret.
     *
     * The stored endpoint is reused while the webhook URL, the Secret API key, and
     * the events match. Otherwise the endpoint is looked up in the store that owns
     * the current Secret API key and created there when missing. A disabled endpoint
     * is enabled again whenever it is looked up.
     *
     * @param bool $force  Re-register even when the stored endpoint matches.
     * @param bool $verify Look the endpoint up even when the stored one matches, so a
     *                     settings save repairs an endpoint Givver disabled or deleted.
     *                     Storefront requests skip this to avoid an API call per page.
     * @return bool
     */
    public function ensure_webhook( $force = false, $verify = false ) {
        $mode       = ( 'yes' === $this->testmode ) ? 'test' : 'live';
        $secret_key = $this->get_secret_key();
        if ( '' === $secret_key ) {
            $this->log( 'ensure_webhook skipped: no Secret API key for ' . $mode . ' mode', 'info' );
            return false;
        }

        $webhook_url     = Givver_Webhook::webhook_url();
        $key_fingerprint = Givver_Webhook::key_fingerprint( $secret_key );
        $stored          = Givver_Webhook::get_for_mode( $mode );
        $reason          = $force
            ? 'forced'
            : Givver_Webhook::reregister_reason( $mode, $webhook_url, $key_fingerprint, self::WEBHOOK_EVENTS );

        if ( '' === $reason ) {
            if ( ! $verify ) {
                return true;
            }
            $reason = 'settings saved';
        }

        $this->log( 'ensure_webhook mode=' . $mode . ' reason=' . $reason, 'info' );

        $api      = $this->get_api_client();
        $existing = $api->get_webhooks();
        if ( is_wp_error( $existing ) ) {
            $this->log( 'ensure_webhook get_webhooks error: ' . $existing->get_error_message(), 'error' );
            return false;
        }

        $endpoints = isset( $existing['webhook_endpoints'] ) && is_array( $existing['webhook_endpoints'] )
            ? $existing['webhook_endpoints']
            : array();

        $match = null;
        foreach ( $endpoints as $endpoint ) {
            $ep_url = isset( $endpoint['url'] ) ? $endpoint['url'] : '';
            if ( $ep_url === $webhook_url ) {
                $match = $endpoint;
                break;
            }
        }

        if ( $match && ! empty( $match['id'] ) ) {
            if ( ! $this->ensure_remote_webhook_state( $api, $match ) ) {
                return false;
            }

            // Endpoint ids are unique, so finding the stored id with the current key
            // means the key belongs to the same store and the stored secret still applies.
            $stored_id = isset( $stored['id'] ) ? (string) $stored['id'] : '';
            if ( ! $force && ! empty( $stored['secret'] ) && $stored_id === (string) $match['id'] ) {
                Givver_Webhook::save_for_mode( $mode, $match['id'], $stored['secret'], $webhook_url, self::WEBHOOK_EVENTS, $key_fingerprint );
                $this->log( 'ensure_webhook kept endpoint ' . $match['id'] . ' and its secret', 'info' );
                return true;
            }

            // The signing secret is returned only on create or rotate, so an existing
            // endpoint this site has no secret for needs a new one.
            $rotated = $api->rotate_webhook_secret( $match['id'] );
            if ( is_wp_error( $rotated ) || empty( $rotated['secret'] ) ) {
                $message = is_wp_error( $rotated ) ? $rotated->get_error_message() : 'missing secret';
                $this->log( 'ensure_webhook rotate error: ' . $message, 'error' );
                return false;
            }
            Givver_Webhook::save_for_mode( $mode, $match['id'], $rotated['secret'], $webhook_url, self::WEBHOOK_EVENTS, $key_fingerprint );
            $this->log( 'ensure_webhook rotated secret for endpoint ' . $match['id'], 'info' );
            return true;
        }

        $result = $api->create_webhook(
            $webhook_url,
            'WOO',
            self::WEBHOOK_EVENTS,
            true
        );
        if ( is_wp_error( $result ) ) {
            $this->log( 'ensure_webhook create error: ' . $result->get_error_message(), 'error' );
            return false;
        }

        $endpoint_id = isset( $result['webhook_endpoint']['id'] ) ? $result['webhook_endpoint']['id'] : '';
        $secret      = isset( $result['secret'] ) ? $result['secret'] : '';
        if ( ! $endpoint_id || ! $secret ) {
            $this->log( 'ensure_webhook create response missing id or secret', 'error' );
            return false;
        }

        Givver_Webhook::save_for_mode( $mode, $endpoint_id, $secret, $webhook_url, self::WEBHOOK_EVENTS, $key_fingerprint );
        $this->log( 'ensure_webhook created endpoint ' . $endpoint_id . ' for ' . $webhook_url, 'info' );
        return true;
    }

    /**
     * Subscribe an existing Givver endpoint to the required events without dropping
     * other events, and enable it again if Givver disabled it after failed deliveries.
     *
     * @param Givver_API_Client $api
     * @param array             $endpoint
     * @return bool
     */
    protected function ensure_remote_webhook_state( $api, $endpoint ) {
        $fields = array();

        $current = isset( $endpoint['events'] ) && is_array( $endpoint['events'] ) ? $endpoint['events'] : array();
        if ( array_diff( self::WEBHOOK_EVENTS, $current ) ) {
            $fields['events'] = array_values( array_unique( array_merge( $current, self::WEBHOOK_EVENTS ) ) );
        }
        // Givver disables an endpoint after 8 failed attempts or 24 hours and never enables it again.
        if ( isset( $endpoint['enabled'] ) && false === $endpoint['enabled'] ) {
            $fields['enabled'] = true;
        }
        if ( empty( $fields ) ) {
            return true;
        }

        $updated = $api->update_webhook( $endpoint['id'], $fields );
        if ( is_wp_error( $updated ) ) {
            $this->log( 'ensure_webhook update endpoint error: ' . $updated->get_error_message(), 'error' );
            return false;
        }

        if ( isset( $fields['events'] ) ) {
            $this->log( 'ensure_webhook subscribed events: ' . implode( ',', $fields['events'] ), 'info' );
        }
        if ( isset( $fields['enabled'] ) ) {
            $this->log( 'ensure_webhook enabled endpoint ' . $endpoint['id'], 'info' );
        }
        return true;
    }

    /**
     * Payment fields on checkout page.
     * NOTE: card inputs deliberately do NOT have name attributes, so card data is never posted to your server.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }

        if ( 'yes' !== $this->payment_redirection_flow ) {
            ?>
        <fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-givver-card-form wc-payment-form">
            <div class="givver-card-row">
                <label for="givver-card-number"><?php esc_html_e( 'Card Number', 'givver-woocommerce' ); ?> <span class="required">*</span></label>
                <div class="givver-card-number-wrapper">
                    <input id="givver-card-number"
                           class="input-text givver-card-number"
                           type="tel"
                           inputmode="numeric"
                           autocomplete="cc-number"
                           data-givver-card-number="1"
                           placeholder="•••• •••• •••• ••••" />
                    <span class="givver-card-brand"></span>
                </div>
            </div>

            <div class="givver-card-row givver-card-row--exp-cvc">
                <div class="givver-card-expiry">
                    <label for="givver-card-expiry"><?php esc_html_e( 'Expiry (MM/YY)', 'givver-woocommerce' ); ?> <span class="required">*</span></label>
                    <input id="givver-card-expiry"
                           class="input-text givver-card-expiry"
                           type="tel"
                           inputmode="numeric"
                           autocomplete="cc-exp"
                           data-givver-card-expiry="1"
                           placeholder="<?php esc_attr_e( 'MM/YY', 'givver-woocommerce' ); ?>" />
                </div>

                <div class="givver-card-cvc">
                    <label for="givver-card-cvc"><?php esc_html_e( 'CVC', 'givver-woocommerce' ); ?> <span class="required">*</span></label>
                    <input id="givver-card-cvc"
                           class="input-text givver-card-cvc"
                           type="tel"
                           inputmode="numeric"
                           autocomplete="cc-csc"
                           data-givver-card-cvc="1"
                           placeholder="•••" />
                </div>
            </div>

            <input type="hidden" id="givver-opaque-data" name="givver_opaque_data" value="" />
            <input type="hidden" id="givver-3ds-data" name="givver_3ds_data" value="" />
        </fieldset>
        <?php
        }
    }


    /**
     * Process the payment: uses previously generated opaqueData from JS.
     *
     * @param int $order_id
     * @return array|void
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'givver-woocommerce' ), 'error' );
            return;
        }

        // Payment redirection flow: create payment request and redirect to external checkout
        //
        if ( 'yes' === $this->payment_redirection_flow ) {
            // 
            $api = $this->get_api_client();
            $payment_redirect_success_url = Givver_Webhook::success_url( $order );
            $this->log( 'payment_redirect_success_url: ' . $payment_redirect_success_url, 'info' );
            $amount   = $order->get_total();
            $currency = $order->get_currency();
            $response = $api->create_payment_request( $amount, $currency, "card", $payment_redirect_success_url );
            if ( is_wp_error( $response ) ) {
                wc_add_notice( __( 'Payment has been failed..', 'givver-woocommerce' ), 'error' );
                return;
            }
            $this->store_payment_expectation( $order, $amount, $currency );
            $order->save();
            $payment_request = $response['payment_request'];
            $checkout_url = $payment_request['checkout_url'] . '?success_url=' . urlencode($payment_redirect_success_url);

            // Pass billing params to payment form (from checkout form / order).
            $billing_params = array_filter(array(
                'email'          => $order->get_billing_email(),
                'phone-number'   => $order->get_billing_phone(),
                'street-address' => $order->get_billing_address_1(),
                'unit-address'   => $order->get_billing_address_2(),
                'city'           => $order->get_billing_city(),
                'state'          => $order->get_billing_state(),
                'zip-code'       => $order->get_billing_postcode(),
                'country'        => $order->get_billing_country(),
            ));
            if ( ! empty( $billing_params ) ) {
                $checkout_url = add_query_arg( $billing_params, $checkout_url );
            }

            // add external order id to the checkout url
            $checkout_url = add_query_arg( array('external_order_id' => $order->get_id()), $checkout_url );

            // by default order status is "pending" (Pending payment) 
            // so no need to do anything additional here
            // $order->get_status();

            return array(
                'result'   => 'success',
                'redirect' => $checkout_url,
            );
        }

        // tokenized card
        $opaque_raw = isset( $_POST['givver_opaque_data'] ) ? wp_unslash( $_POST['givver_opaque_data'] ) : '';
        if ( empty( $opaque_raw ) ) {
            wc_add_notice( __( 'There was a problem tokenizing your card. Please try again.', 'givver-woocommerce' ), 'error' );
            return;
        }
        $opaque_data = json_decode( $opaque_raw, true );
        if ( empty( $opaque_data ) || ! is_array( $opaque_data ) ) {
            wc_add_notice( __( 'Invalid card token received. Please try again.', 'givver-woocommerce' ), 'error' );
            return;
        }

        // 3ds data
        $three_ds_raw = isset( $_POST['givver_3ds_data'] ) ? wp_unslash( $_POST['givver_3ds_data'] ) : '';
        $three_ds_data = null;
        if (!empty( $three_ds_raw ) ) {
          $decoded = json_decode( $three_ds_raw, true );
          if ( !empty( $decoded ) && is_array( $decoded ) ) {
              $three_ds_data = $decoded;
          }
        }
       

        // Build payload.
        $amount   = $order->get_total();
        $currency = $order->get_currency();

        $payload = array(
            'currency'       => $currency,
            'amount'         => (string) $amount,
            'type'           => 'fiat',
            'opaqueData'     => $opaque_data,
            'customer'       => $this->build_customer_from_order( $order ),
            'billingAddress' => $this->build_billing_address_from_order( $order ),
            'orderId'        => (string) $order->get_id(),
        );

        // Conditionally add 3DS block
        if (!empty($three_ds_data)) {
            $payload['threeDS'] = $three_ds_data;
        }

        $api = $this->get_api_client();

        $this->log( 'Processing card payment for order ' . $order->get_id() . ' with payload: ' . Givver_Logger::encode( $payload ) );

        $response = $api->transact( $payload );

        if ( is_wp_error( $response ) ) {
            $order->update_status('failed', __('Payment has been failed.', 'givver-woocommerce'));
            $error_message = $response->get_error_message();
            $error_data    = $response->get_error_data();
            $status        = is_array( $error_data ) && isset( $error_data['status'] ) ? $error_data['status'] : '';
            $this->log( 'Payment failed: ' . $status . ' ' . $error_message, 'error' );
            wc_add_notice($error_message, 'error' );
            return;
        }

        $this->log( 'Givver response: ' . Givver_Logger::encode( $response ) );

        $result_data = isset( $response['result'] ) ? $response['result'] : array();
        $givver_order_id = isset( $result_data['orderId'] ) ? $result_data['orderId'] : '';
        $order_display_id = isset( $result_data['orderDisplayId'] ) ? $result_data['orderDisplayId'] : '';

        if ( ! $givver_order_id ) {
            $this->log( 'Missing Givver orderId in response.', 'error' );
            wc_add_notice( __( 'Payment failed: missing transaction id.', 'givver-woocommerce' ), 'error' );
            return;
        }

        $this->store_payment_expectation( $order, $amount, $currency );
        $order->update_meta_data( '_givver_order_id', $givver_order_id );
        $order->update_meta_data( '_givver_order_display_id', $order_display_id );
        $order->save();

        if ( 'authorize' === $this->capture_type ) {
            $order->update_status( 'on-hold', __( 'Givver payment authorized. Capture later via Givver or gateway.', 'givver-woocommerce' ) );
        } else {
            $order->payment_complete( $givver_order_id );
        }

        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Mark a WooCommerce order paid only after the webhook matches the stored payment.
     *
     * @param int         $order_id
     * @param string|null $givver_order_id
     * @param string|null $givver_order_display_id
     * @param string      $environment      test|live from the signed event
     * @param mixed       $amount
     * @param mixed       $currency
     * @return WC_Order|null
     */
    protected function complete_order( $order_id, $givver_order_id = null, $givver_order_display_id = null, $environment = '', $amount = null, $currency = null ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        $allowed_methods = array( 'givver_card', 'givver_bank' );
        if ( ! in_array( $order->get_payment_method(), $allowed_methods, true ) ) {
            return null;
        }

        if ( empty( $givver_order_id ) ) {
            return null;
        }

        $reason = $this->payment_completion_block_reason( $order, $givver_order_id, $environment, $amount, $currency );
        if ( '' !== $reason ) {
            $this->log( 'payment completion blocked for order ' . $order->get_id() . ': ' . $reason, 'error' );
            if ( ! $order->is_paid() ) {
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: why the Givver payment was not applied */
                        __( 'Givver payment was not completed: %s', 'givver-woocommerce' ),
                        $reason
                    )
                );
                $order->save();
            }
            return null;
        }

        if ( $order->is_paid() ) {
            if ( '' === (string) $order->get_meta( '_givver_order_id' ) ) {
                $order->update_meta_data( '_givver_order_id', $givver_order_id );
                $order->save();
            }
            return $order;
        }

        $order->update_meta_data( '_givver_order_id', $givver_order_id );
        if ( ! empty( $givver_order_display_id ) ) {
            $order->update_meta_data( '_givver_order_display_id', $givver_order_display_id );
        }
        $order->payment_complete( $givver_order_id );
        $order->save();
        return $order;
    }

    /**
     * Empty string when the event may complete this order.
     *
     * @param WC_Order $order
     * @param string   $givver_order_id
     * @param string   $environment
     * @param mixed    $amount
     * @param mixed    $currency
     * @return string
     */
    protected function payment_completion_block_reason( $order, $givver_order_id, $environment, $amount, $currency ) {
        $expected_environment = (string) $order->get_meta( '_givver_environment' );
        if ( ! in_array( $expected_environment, array( 'test', 'live' ), true ) ) {
            return __( 'order has no Givver environment', 'givver-woocommerce' );
        }
        if ( $expected_environment !== (string) $environment ) {
            return __( 'event environment does not match the order', 'givver-woocommerce' );
        }

        $expected_amount   = $order->get_meta( '_givver_amount' );
        $expected_currency = $order->get_meta( '_givver_currency' );
        if ( '' === (string) $expected_amount || '' === (string) $expected_currency ) {
            return __( 'order has no stored amount or currency', 'givver-woocommerce' );
        }
        if ( ! Givver_Webhook::amounts_match( $expected_amount, $amount ) ) {
            return __( 'payment amount does not match the order', 'givver-woocommerce' );
        }
        if ( ! Givver_Webhook::currencies_match( $expected_currency, $currency ) ) {
            return __( 'payment currency does not match the order', 'givver-woocommerce' );
        }

        $stored_id = (string) $order->get_meta( '_givver_order_id' );
        // A failed attempt stores its own Givver id. A later successful attempt
        // for the same Woo order has a new id and may replace it. A paid order
        // keeps the id that completed it.
        if ( '' !== $stored_id && $stored_id !== (string) $givver_order_id && 'failed' !== $order->get_status() ) {
            return __( 'transaction does not match the one stored on this order', 'givver-woocommerce' );
        }
        if ( $this->transaction_is_used_on_another_order( $givver_order_id, $order->get_id() ) ) {
            return __( 'transaction was already used for another order', 'givver-woocommerce' );
        }

        return '';
    }

    /**
     * @param string $givver_order_id
     * @param int    $order_id
     * @return bool
     */
    protected function transaction_is_used_on_another_order( $givver_order_id, $order_id ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return false;
        }

        $found = wc_get_orders(
            array(
                'limit'        => 2,
                'return'       => 'ids',
                'meta_key'     => '_givver_order_id',
                'meta_value'   => (string) $givver_order_id,
                'meta_compare' => '=',
            )
        );
        if ( ! is_array( $found ) ) {
            return false;
        }
        foreach ( $found as $found_id ) {
            if ( (int) $found_id !== (int) $order_id ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mark a WooCommerce order failed after a verified payment.failed webhook.
     *
     * @param int         $order_id
     * @param string|null $givver_order_id
     * @param string|null $givver_order_display_id
     * @param string      $failure_message
     * @param string      $environment test|live from the signed event
     * @return WC_Order|null
     */
    protected function fail_order( $order_id, $givver_order_id = null, $givver_order_display_id = null, $failure_message = '', $environment = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        $allowed_methods = array( 'givver_card', 'givver_bank' );
        if ( ! in_array( $order->get_payment_method(), $allowed_methods, true ) ) {
            return null;
        }

        if ( $order->is_paid() ) {
            return $order;
        }

        $expected_environment = (string) $order->get_meta( '_givver_environment' );
        if ( in_array( $expected_environment, array( 'test', 'live' ), true ) && $expected_environment !== (string) $environment ) {
            return null;
        }

        $stored_id = (string) $order->get_meta( '_givver_order_id' );
        if ( '' !== $stored_id && ! empty( $givver_order_id ) && $stored_id !== (string) $givver_order_id ) {
            return null;
        }

        $status = $order->get_status();
        if ( in_array( $status, array( 'failed', 'cancelled', 'refunded' ), true ) ) {
            return $order;
        }

        if ( ! empty( $givver_order_id ) ) {
            $order->update_meta_data( '_givver_order_id', $givver_order_id );
        }
        if ( ! empty( $givver_order_display_id ) ) {
            $order->update_meta_data( '_givver_order_display_id', $givver_order_display_id );
        }
        $order->delete_meta_data( '_givver_pending_confirm' );

        $note = __( 'Givver payment failed.', 'givver-woocommerce' );
        if ( is_string( $failure_message ) && '' !== $failure_message ) {
            $note = sprintf(
                /* translators: %s: processor failure message */
                __( 'Givver payment failed: %s', 'givver-woocommerce' ),
                $failure_message
            );
        }

        $order->update_status( 'failed', $note );
        return $order;
    }

    /**
     * Browser return from the Givver Payment Form.
     * Redirects to the thank-you page only — never marks the order as paid.
     */
    public function handle_return() {
        $order_id = absint( isset( $_GET['order_id'] ) ? $_GET['order_id'] : 0 );
        $key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

        $this->log( 'handle_return: order_id: ' . $order_id, 'info' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Invalid order', '', array( 'response' => 400 ) );
        }
        $order_key = (string) $order->get_order_key();
        if ( '' === $key || strlen( $key ) !== strlen( $order_key ) || ! hash_equals( $order_key, $key ) ) {
            wp_die( 'Invalid order', '', array( 'response' => 400 ) );
        }

        $thankyou = add_query_arg( 'givver_confirm', '1', $this->get_return_url( $order ) );

        set_transient( 'givver_return_poll_' . $order_id, 1, 5 * MINUTE_IN_SECONDS );
        $order->update_meta_data( '_givver_pending_confirm', 'yes' );
        $order->save();

        wp_safe_redirect( $thankyou );
        exit;
    }

    /**
     * Shows the confirmation popup on the order received page after payment redirect.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public function show_order_received_popup( $order_id ) {
        if ( self::$popup_rendered ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $should_show = 'yes' === $order->get_meta( '_givver_pending_confirm' )
            || ( isset( $_GET['givver_confirm'] ) && '1' === $_GET['givver_confirm'] );

        if ( ! $should_show ) {
            return;
        }

        self::$popup_rendered = true;
        $this->log( 'show_order_received_popup for order ' . $order_id, 'info' );
        $this->render_confirmation_popup( $order_id );
    }

    /**
     * Renders the confirmation popup with optional auto-confirm countdown, then polls order status.
     *
     * @param int $order_id WooCommerce order ID.
     */
    protected function render_confirmation_popup( $order_id ) {
        $this->log( 'render_confirmation_popup. order_id: ' . $order_id, 'info' );

        $status_url = add_query_arg(
            array(
                'wc-api'     => 'givver_order_status',
                'order_id'   => absint( $order_id ),
                'from_popup' => '1',
            ),
            home_url( '/' )
        );
        ?>
        <div id="givver-confirm-overlay" class="givver-overlay" role="dialog" aria-modal="true" aria-labelledby="givver-confirm-title">
            <div class="givver-overlay-backdrop" aria-hidden="true"></div>
            <div class="givver-page">
                <div class="givver-card givver-confirm" id="givver-confirm">
                    <h2 class="givver-title" id="givver-confirm-title"><?php esc_html_e( 'Confirm transaction', 'givver-woocommerce' ); ?></h2>
                    <p class="givver-subtitle"><?php esc_html_e( 'Confirm the transaction by clicking the button below', 'givver-woocommerce' ); ?></p>

                    <div class="givver-countdown-block">
                        <div class="givver-countdown-bar" aria-hidden="true">
                            <div class="givver-countdown-bar-fill" id="givver-countdown-bar"></div>
                        </div>
                        <p class="givver-countdown-label" id="givver-countdown" aria-live="polite"></p>
                    </div>

                    <button type="button" class="givver-confirm-btn" id="givver-confirm-btn">
                        <?php esc_html_e( 'Confirm', 'givver-woocommerce' ); ?>
                    </button>
                </div>

                <div class="givver-card givver-finalizing" id="givver-finalizing">
                    <div class="givver-loader" aria-hidden="true"></div>
                    <p class="givver-finalizing-text"><?php esc_html_e( 'Finalizing your order and updating inventory...', 'givver-woocommerce' ); ?></p>
                </div>
            </div>
        </div>
        <style>
            .givver-overlay {
                --givver-bg: #f3f3f3;
                --givver-surface: #ffffff;
                --givver-border: #e2e2e2;
                --givver-text: #1a1a1a;
                --givver-text-muted: #6b6b6b;
                --givver-btn: #2c2c2c;
                --givver-btn-hover: #1a1a1a;
                --givver-track: #e8e8e8;
                --givver-progress: #8a8a8a;

                position: fixed;
                inset: 0;
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
                color: var(--givver-text);
                line-height: 1.5;
            }

            .givver-overlay-backdrop {
                position: absolute;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
            }

            .givver-overlay .givver-page {
                position: relative;
                z-index: 1;
                width: min(100%, 480px);
            }

            .givver-overlay .givver-card {
                background: var(--givver-surface);
                border: 1px solid var(--givver-border);
                border-radius: 4px;
                padding: 2.5rem 2rem;
                text-align: center;
            }

            .givver-overlay .givver-title {
                margin: 0 0 0.75rem;
                font-size: 1.375rem;
                font-weight: 600;
                color: var(--givver-text);
            }

            .givver-overlay .givver-subtitle {
                margin: 0 0 2rem;
                color: var(--givver-text-muted);
                font-size: 0.9375rem;
                line-height: 1.6;
            }

            .givver-overlay .givver-countdown-block {
                margin-bottom: 2rem;
            }

            .givver-overlay .givver-countdown-bar {
                height: 4px;
                background: var(--givver-track);
                border-radius: 2px;
                overflow: hidden;
                margin-bottom: 0.75rem;
            }

            .givver-overlay .givver-countdown-bar-fill {
                height: 100%;
                width: 100%;
                background: var(--givver-progress);
                border-radius: 2px;
                transition: width 1s linear;
            }

            .givver-overlay .givver-countdown-label {
                margin: 0;
                color: var(--givver-text-muted);
                font-size: 0.875rem;
            }

            .givver-overlay .givver-confirm-btn {
                display: inline-block;
                width: 100%;
                max-width: 280px;
                padding: 0.875rem 1.5rem;
                font-family: inherit;
                font-size: 0.9375rem;
                font-weight: 500;
                color: #ffffff;
                background: var(--givver-btn);
                border: 1px solid var(--givver-btn);
                border-radius: 3px;
                cursor: pointer;
                transition: background 0.15s ease, border-color 0.15s ease;
            }

            .givver-overlay .givver-confirm-btn:hover {
                background: var(--givver-btn-hover);
                border-color: var(--givver-btn-hover);
            }

            .givver-overlay .givver-confirm-btn:focus-visible {
                outline: 2px solid #888888;
                outline-offset: 2px;
            }

            .givver-overlay .givver-confirm-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .givver-overlay .givver-finalizing { display: none; }
            .givver-overlay .givver-finalizing.is-active { display: block; }
            .givver-overlay .givver-confirm { display: block; }
            .givver-overlay .givver-confirm.is-hidden { display: none; }

            .givver-overlay .givver-loader {
                width: 36px;
                height: 36px;
                border: 2px solid var(--givver-track);
                border-top-color: var(--givver-progress);
                border-radius: 50%;
                animation: givver-spin 0.8s linear infinite;
                margin: 0 auto 1.25rem;
            }

            .givver-overlay .givver-finalizing-text {
                margin: 0;
                color: var(--givver-text-muted);
                font-size: 0.9375rem;
                line-height: 1.6;
            }

            @keyframes givver-spin { to { transform: rotate(360deg); } }

            body.givver-confirm-open {
                overflow: hidden;
            }

            @media (prefers-reduced-motion: reduce) {
                .givver-overlay .givver-loader { animation: none; }
                .givver-overlay .givver-countdown-bar-fill { transition: none; }
                .givver-overlay .givver-confirm-btn { transition: none; }
            }
        </style>
        <script>
            (function() {
                var statusUrl = <?php echo wp_json_encode( $status_url ); ?>;
                var pollInterval = 2000;
                var countdownTotal = 5;
                var countdownSeconds = countdownTotal;
                var confirmed = false;
                var countdownTimer = null;

                var overlay = document.getElementById('givver-confirm-overlay');
                var confirmSection = document.getElementById('givver-confirm');
                var finalizingSection = document.getElementById('givver-finalizing');
                var confirmBtn = document.getElementById('givver-confirm-btn');
                var countdownEl = document.getElementById('givver-countdown');
                var countdownBar = document.getElementById('givver-countdown-bar');

                document.body.classList.add('givver-confirm-open');

                function updateCountdown(seconds) {
                    countdownEl.textContent = '<?php echo esc_js( __( 'Automatic confirmation in', 'givver-woocommerce' ) ); ?> ' + seconds;
                    countdownBar.style.width = ((seconds / countdownTotal) * 100) + '%';
                }

                function refreshThankYouPage() {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('givver_confirm');
                    window.location.replace(url.toString());
                }

                function checkStatus() {
                    fetch(statusUrl)
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.completed || data.failed) {
                                refreshThankYouPage();
                            }
                        })
                        .catch(function(err) {
                            console.error('checkStatus. error', err);
                        });
                }

                function startPolling() {
                    checkStatus();
                    setInterval(checkStatus, pollInterval);
                }

                function confirmTransaction() {
                    if (confirmed) {
                        return;
                    }
                    confirmed = true;

                    if (countdownTimer) {
                        clearInterval(countdownTimer);
                        countdownTimer = null;
                    }

                    confirmBtn.disabled = true;
                    confirmSection.classList.add('is-hidden');
                    finalizingSection.classList.add('is-active');
                    startPolling();
                }

                updateCountdown(countdownSeconds);
                countdownTimer = setInterval(function() {
                    countdownSeconds -= 1;
                    if (countdownSeconds <= 0) {
                        confirmTransaction();
                        return;
                    }
                    updateCountdown(countdownSeconds);
                }, 1000);

                confirmBtn.addEventListener('click', confirmTransaction);
            })();
        </script>
        <?php
    }

    /**
     * AJAX handler: returns order status for the confirmation popup poll.
     */
    public function ajax_order_status() {
        $order_id = absint( $_GET['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json( array( 'completed' => false, 'status' => '' ) );
        }
        if ( ! get_transient( 'givver_return_poll_' . $order_id ) ) {
            wp_send_json( array( 'completed' => false, 'status' => '' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json( array( 'completed' => false, 'status' => '' ) );
        }
        $status = $order->get_status();
        $this->log('ajax_order_status. status: ' . $status, 'info');
        $completed = in_array( $status, array( 'processing', 'completed' ), true );
        $failed    = in_array( $status, array( 'failed', 'cancelled' ), true );
        $from_popup = isset( $_GET['from_popup'] ) && '1' === $_GET['from_popup'];
        if ( ( $completed || $failed ) && $from_popup ) {
            delete_transient( 'givver_return_poll_' . $order_id );
            $order->delete_meta_data( '_givver_pending_confirm' );
            $order->save();
        }
        wp_send_json( array( 'completed' => $completed, 'failed' => $failed, 'status' => $status ) );
    }

    /**
     * Server-to-server payment.succeeded / payment.failed.
     * Updates the WooCommerce order only after HMAC verification.
     */
    public function handle_webhook() {
        $this->log( 'handle_webhook', 'info' );

        $raw_body = file_get_contents( 'php://input' );
        if ( false === $raw_body ) {
            $raw_body = '';
        }

        if ( empty( Givver_Webhook::secrets() ) ) {
            $this->log( 'handle_webhook rejected: signing secret not stored', 'error' );
            status_header( 503 );
            wp_die( 'Webhook secret not configured', '', array( 'response' => 503 ) );
        }

        $matched_modes = Givver_Webhook::matching_request_modes( $raw_body );
        if ( empty( $matched_modes ) ) {
            $this->log( 'handle_webhook rejected: invalid signature', 'error' );
            status_header( 401 );
            wp_die( 'Invalid signature', '', array( 'response' => 401 ) );
        }

        $payload = json_decode( $raw_body, true );
        if ( ! is_array( $payload ) ) {
            status_header( 400 );
            wp_die( 'Invalid payload', '', array( 'response' => 400 ) );
        }

        $this->log( 'handle_webhook payload: ' . Givver_Logger::encode( $payload ), 'info' );

        $environment = isset( $payload['environment'] ) ? (string) $payload['environment'] : '';
        if ( ! Givver_Webhook::environment_is_authorized( $matched_modes, $environment ) ) {
            $this->log( 'handle_webhook rejected: environment ' . $environment . ' is not signed by ' . implode( ',', $matched_modes ), 'error' );
            wp_send_json( array( 'received' => true, 'ignored' => true ) );
        }

        $type                    = isset( $payload['type'] ) ? $payload['type'] : '';
        $object                  = isset( $payload['data']['object'] ) && is_array( $payload['data']['object'] ) ? $payload['data']['object'] : array();
        $order_id                = absint( isset( $object['external_order_id'] ) ? $object['external_order_id'] : 0 );
        $givver_order_id         = isset( $object['order_id'] ) ? $object['order_id'] : null;
        $givver_order_display_id = isset( $object['order_display_id'] ) ? $object['order_display_id'] : null;
        $requested               = Givver_Webhook::requested_amount( $object );
        $amount                  = $requested['amount'];
        $currency                = $requested['currency'];

        $this->log( 'handle_webhook type=' . $type . ' order_id=' . $order_id, 'info' );

        if ( 'payment.succeeded' === $type ) {
            if ( ! $order_id || empty( $givver_order_id ) ) {
                wp_send_json( array( 'received' => true, 'ignored' => true ) );
            }

            $order = $this->complete_order( $order_id, $givver_order_id, $givver_order_display_id, $environment, $amount, $currency );
            if ( ! $order ) {
                $this->log( 'handle_webhook: order not completable ' . $order_id, 'error' );
                wp_send_json( array( 'received' => true, 'ignored' => true ) );
            }

            wp_send_json( array( 'received' => true, 'order_id' => $order->get_id() ) );
        }

        if ( 'payment.failed' === $type ) {
            if ( ! $order_id ) {
                wp_send_json( array( 'received' => true, 'ignored' => true ) );
            }

            $failure_message = isset( $object['failure_message'] ) ? sanitize_text_field( $object['failure_message'] ) : '';
            $order           = $this->fail_order( $order_id, $givver_order_id, $givver_order_display_id, $failure_message, $environment );
            if ( ! $order ) {
                $this->log( 'handle_webhook: order not failable ' . $order_id, 'error' );
                wp_send_json( array( 'received' => true, 'ignored' => true ) );
            }

            wp_send_json( array( 'received' => true, 'order_id' => $order->get_id(), 'status' => $order->get_status() ) );
        }

        wp_send_json( array( 'received' => true, 'ignored' => true ) );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Givver Bank gateway.
 *
 * Displays a single "Pay With Bank" button. Uses Givver API for link token,
 * public token exchange, and bank-to-bank transaction (Cabbage SDK on frontend).
 */
class WC_Gateway_Givver_Bank extends WC_Gateway_Givver_Base {

    public function __construct() {
        $this->id                 = 'givver_bank';
        $this->method_title       = __( 'Givver – Pay-By-Bank', 'givver-woocommerce' );
        $this->method_description = __( 'Save 5% on your order when you Pay-By-Bank. Click "Place Order" below to securely and seamlessly complete your purchase through our private, verified Pay-By-Bank processor', 'givver-woocommerce' );
        $this->has_fields         = true;

        parent::__construct();

        add_action( 'woocommerce_api_wc_gateway_givver_bank', array( $this, 'handle_return' ) );
        add_action( 'woocommerce_api_wc_gateway_givver_bank_fingrid', array( $this, 'handle_bank_connect' ) );
    }

    /**
     * Extra settings: Bank-specific.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        if ( isset( $this->form_fields['title'] ) ) {
            $this->form_fields['title']['default'] = __( 'Pay-By-Bank - SAVE 5%', 'givver-woocommerce' );
        }
        if ( isset( $this->form_fields['description'] ) ) {
            $this->form_fields['description']['default'] = $this->method_description;
        }
    }

    /**
     * Payment fields: description and a single "Pay With Bank" button.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
    }

    /**
     * Process the payment: redirect to bank connect page (link_token → Cabbage SDK → exchange → transact).
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

        // redirect to the bank connect page
        //
        $connect_url = WC()->api_request_url( 'wc_gateway_givver_bank_fingrid' );
        $connect_url = add_query_arg( array(
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        ), $connect_url );

        return array(
            'result'   => 'success',
            'redirect' => $connect_url,
        );
    }

    /**
     * Bank connect page: GET shows link flow; POST receives public_token and completes payment.
     */
    public function handle_bank_connect() {
        $order_id = absint( isset( $_REQUEST['order_id'] ) ? $_REQUEST['order_id'] : 0 );
        $key      = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $key ) {
            wp_die( esc_html__( 'Invalid order.', 'givver-woocommerce' ), 400 );
        }

        if ( $this->is_post_request() && ! empty( $_POST['fingrid_public_token'] ) ) {
            if ( ! isset( $_POST['fingrid_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fingrid_nonce'] ) ), 'givver_fingrid_connect' ) ) {
                wp_die( esc_html__( 'Security check failed.', 'givver-woocommerce' ), 403 );
            }
            $this->handle_bank_public_token( $order );
            return;
        }

        $this->render_bank_connect_page( $order );
    }

    /**
     * Check if current request is POST.
     *
     * @return bool
     */
    protected function is_post_request() {
        return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Get the amount to charge for a bank-to-bank transaction.
     * Uses order total; if the order has the "5% Pay-By-Bank Discount" fee, that is already included in the total.
     * If the fee is not on the order, applies 5% discount to subtotal so the charged amount matches.
     *
     * @param WC_Order $order
     * @return float
     */
    protected function get_bank_transaction_amount( $order ) {
        $this->log( 'get_bank_transaction_amount', 'info' );
        $this->log( 'order->get_total(): ' . $order->get_total(), 'info' );
        $this->log( 'order->get_subtotal(): ' . $order->get_subtotal(), 'info' );

        $amount = (float) $order->get_total();
        $has_discount_fee = false;
        foreach ( $order->get_fees() as $fee ) {
            if ( $fee->get_name() === '5% Pay-By-Bank Discount' ) {
                $has_discount_fee = true;
                break;
            }
        }
        if ( $has_discount_fee ) {
            $discount = (float) $order->get_subtotal() * 0.05;
            $amount   = max( 0, $amount + $discount );
            // TODO: maybe we can just use $order->get_subtotal() ?
            $this->log( 'get_bank_transaction_amount amount: ' . $amount, 'info' );
        }
        return $amount;
    }

    /**
     * Handle submitted public_token: exchange for bank_token and run Givver bank-to-bank transaction.
     *
     * @param WC_Order $order
     */
    protected function handle_bank_public_token( $order ) {
        $public_token = sanitize_text_field( wp_unslash( $_POST['fingrid_public_token'] ) );
        $link_token = sanitize_text_field( wp_unslash( $_POST['fingrid_link_token'] ) );
        $givver_api   = $this->get_api_client();

        $exchange = $givver_api->exchange_public_token( $public_token );
        if ( is_wp_error( $exchange ) ) {
            $this->log( 'Givver exchange_public_token failed: ' . $exchange->get_error_message(), 'error' );
            wc_add_notice( __( 'Bank connection could not be completed. Please try again.', 'givver-woocommerce' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $bank_token = isset( $exchange['bank_token'] ) ? $exchange['bank_token'] : ( isset( $exchange['bankToken'] ) ? $exchange['bankToken'] : '' );
        if ( empty( $bank_token ) ) {
            wc_add_notice( __( 'Invalid response from bank. Please try again.', 'givver-woocommerce' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $return_url = $this->get_return_url( $order );
        $amount     = $this->get_bank_transaction_amount( $order );
        $transaction = $givver_api->transact_bank_to_bank( $bank_token, $link_token, $amount, (string) $order->get_id());

        if ( is_wp_error( $transaction ) ) {
            $this->log( 'Givver transact_bank_to_bank failed: ' . $transaction->get_error_message(), 'error' );
            wc_add_notice( $transaction->get_error_message(), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $givver_order_id = isset( $transaction['result']['orderId'] ) ? $transaction['result']['orderId'] : ( isset( $transaction['orderId'] ) ? $transaction['orderId'] : ( isset( $transaction['id'] ) ? $transaction['id'] : '' ) );
        $givver_order_id = is_scalar( $givver_order_id ) ? trim( (string) $givver_order_id ) : '';
        if ( '' === $givver_order_id ) {
            $this->log( 'Givver bank transact response missing orderId', 'error' );
            wc_add_notice( __( 'Payment could not be completed. Please try again.', 'givver-woocommerce' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }
        $this->store_payment_expectation( $order, $amount, $order->get_currency() );
        $order->update_meta_data( '_givver_order_id', $givver_order_id );
        $order->payment_complete( $givver_order_id );
        $order->save();

        WC()->cart->empty_cart();
        wp_safe_redirect( $return_url );
        exit;
    }

    /**
     * Render the bank connect page: create link_token via Givver and output HTML + Cabbage SDK.
     *
     * @param WC_Order $order
     */
    protected function render_bank_connect_page( $order ) {
        $givver_api = $this->get_api_client();
        $params     = array(
            'clientName'      => get_bloginfo( 'name' ),
            'redirectUri'     => $this->get_return_url( $order ),
            'custPhoneNumber' => $order->get_billing_phone(),
            'custEmail'       => $order->get_billing_email(),
            'custFirstName'   => $order->get_billing_first_name(),
            'custLastName'   => $order->get_billing_last_name(),
            'themeColor'     => '1A73E8',
            'themeLogo'      => '',
        );

        $link_response = $givver_api->create_link_token( $params );
        if ( is_wp_error( $link_response ) ) {
            $this->log( 'Givver create_link_token failed: ' . $link_response->get_error_message(), 'error' );
            wp_die( esc_html__( 'Unable to start bank connection. Please try again.', 'givver-woocommerce' ), 500 );
        }

        $link_token = isset( $link_response['link_token'] ) ? $link_response['link_token'] : '';
        if ( empty( $link_token ) ) {
            wp_die( esc_html__( 'Invalid link token.', 'givver-woocommerce' ), 500 );
        }

        $use_sandbox = ( 'yes' === $this->testmode );
        $sdk_url = $use_sandbox
            ? 'https://cabbagepay.com/js/sandbox/cabbage.js'
            : 'https://cabbagepay.com/js/production/cabbage.js';
        $form_action = WC()->api_request_url( 'wc_gateway_givver_bank_fingrid' );
        $form_action = add_query_arg( array(
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        ), $form_action );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e( 'Pay With Bank', 'givver-woocommerce' ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 2rem; max-width: 480px; margin: 0 auto; }
                .fingrid-loading { color: #666; }
                .fingrid-error { color: #b32d2e; margin-top: 1rem; }
                .fingrid-form { margin-top: 1.5rem; }
                button.button { padding: 12px 24px; font-size: 16px; cursor: pointer; background: #0073aa; color: #fff; border: none; border-radius: 4px; }
                button.button:disabled { opacity: 0.6; cursor: not-allowed; }
            </style>
        </head>
        <body>
            <p class="fingrid-loading" id="fingrid-message"><?php esc_html_e( 'Opening secure bank connection…', 'givver-woocommerce' ); ?></p>
            <p class="fingrid-error" id="fingrid-error" style="display:none;"></p>

            <form id="fingrid-token-form" class="fingrid-form" action="<?php echo esc_url( $form_action ); ?>" method="post" style="display:none;">
                <?php wp_nonce_field( 'givver_fingrid_connect', 'fingrid_nonce' ); ?>
                <input type="hidden" name="fingrid_public_token" id="fingrid-public-token" value="" />
                <input type="hidden" name="fingrid_link_token" id="fingrid-link-token" value="<?php echo $link_token; ?>" />
            </form>

            <script src="<?php echo esc_url( $sdk_url ); ?>"></script>
            <script>
                (function() {
                    var linkToken = <?php echo wp_json_encode( $link_token ); ?>;
                    //
                    var form = document.getElementById('fingrid-token-form');
                    var publicTokenInput = document.getElementById('fingrid-public-token');
                    var message = document.getElementById('fingrid-message');
                    var errEl = document.getElementById('fingrid-error');

                    function showError(msg) {
                        message.style.display = 'none';
                        errEl.textContent = msg || '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'givver-woocommerce' ) ); ?>';
                        errEl.style.display = 'block';
                    }

                    function cabbageOriginAllowed(origin) {
                        try {
                            var parsed = new URL(origin);
                            if (parsed.protocol !== 'https:') {
                                return false;
                            }
                            var host = parsed.hostname.toLowerCase();
                            return host === 'cabbagepay.com' || (host.length > 15 && host.indexOf('.cabbagepay.com') === host.length - 15);
                        } catch (e) {
                            return false;
                        }
                    }

                    window.addEventListener('message', function(event) {
                        if (!cabbageOriginAllowed(event.origin)) {
                            if (event.origin) {
                                console.warn('[Givver bank] ignored message from', event.origin);
                            }
                            return;
                        }

                        try {
                            var data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                  
                            if (!data || !data.message) return;

                            if (data.message === 'success') {
                                var publicToken = data.public_token;
                                if (!publicToken) { showError(); return; }

                                cabbage.closeGrid();

                                publicTokenInput.value = publicToken;

                                message.textContent = '<?php echo esc_js( __( 'Bank connected. Completing payment…', 'givver-woocommerce' ) ); ?>';
                                form.submit();
                            }

                            if (data.message === 'terminated') {
                                //User terminated the bank connection flow.

                                showError('<?php echo esc_js( __( 'Bank connection was cancelled. You can try again from checkout.', 'givver-woocommerce' ) ); ?>');
                            }
                        } catch (e) {}
                    });

                    if (typeof cabbage !== 'undefined') {
                        console.log('[initializeGrid] linkToken', linkToken);
                        cabbage.initializeGrid(linkToken);
                        cabbage.openGrid(linkToken);
                    } else {
                        showError('<?php echo esc_js( __( 'Cabbage SDK not loaded. Please check gateway SDK URLs.', 'givver-woocommerce' ) ); ?>');
                    }
                })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Browser return after bank payment. Redirects to thank-you only — never marks paid.
     */
    public function handle_return() {
        $order_id = absint( isset( $_GET['order_id'] ) ? $_GET['order_id'] : 0 );
        $key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_die( esc_html__( 'Invalid order.', 'givver-woocommerce' ), '', array( 'response' => 400 ) );
        }
        $order_key = (string) $order->get_order_key();
        if ( '' === $key || strlen( $key ) !== strlen( $order_key ) || ! hash_equals( $order_key, $key ) ) {
            wp_die( esc_html__( 'Invalid order.', 'givver-woocommerce' ), '', array( 'response' => 400 ) );
        }

        wp_safe_redirect( $this->get_return_url( $order ) );
        exit;
    }
}

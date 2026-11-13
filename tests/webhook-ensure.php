<?php
/**
 * CLI checks for webhook registration after settings changes (no WordPress required).
 *
 * WordPress options, the WooCommerce settings API, and the Givver Admin API are
 * replaced with in-memory stubs. Each Secret API key belongs to one store, and
 * each store has its own webhook endpoints, like the real Admin API.
 *
 * Run: php tests/webhook-ensure.php
 */
define( 'ABSPATH', true );

$GLOBALS['givver_test_options'] = array();
$GLOBALS['givver_test_logs']    = array();

function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['givver_test_options'] ) ? $GLOBALS['givver_test_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = true ) {
    $GLOBALS['givver_test_options'][ $key ] = $value;
    return true;
}

function add_query_arg( $args, $url ) {
    return $url . '?' . http_build_query( $args );
}

function home_url( $path = '/' ) {
    return 'https://shop.example' . $path;
}

function untrailingslashit( $value ) {
    return rtrim( $value, '/\\' );
}

function trailingslashit( $value ) {
    return untrailingslashit( $value ) . '/';
}

function __( $text, $domain = 'default' ) {
    return $text;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

class WP_Error {
    protected $message;

    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }
}

class WC_Logger {
    public function log( $level, $message, $context = array() ) {
        $GLOBALS['givver_test_logs'][] = $message;
    }
}

function wc_get_logger() {
    return new WC_Logger();
}

/**
 * The parts of WC_Settings_API / WC_Payment_Gateway the gateway relies on.
 * process_admin_options() follows WooCommerce: it updates $this->settings and
 * the stored option, and leaves the gateway's own properties untouched.
 */
abstract class WC_Payment_Gateway {
    public $id;
    public $settings = array();
    public $form_fields = array();
    public $supports = array();
    public $title;
    public $description;
    public $enabled;
    public $method_title;
    public $method_description;
    public $has_fields;

    public function get_option_key() {
        return 'woocommerce_' . $this->id . '_settings';
    }

    public function init_settings() {
        $this->settings = get_option( $this->get_option_key(), null );
        if ( ! is_array( $this->settings ) ) {
            $this->settings = array();
            foreach ( $this->form_fields as $key => $field ) {
                $this->settings[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
            }
        }
    }

    public function get_option( $key, $empty_value = null ) {
        if ( empty( $this->settings ) ) {
            $this->init_settings();
        }
        if ( ! isset( $this->settings[ $key ] ) ) {
            $this->settings[ $key ] = isset( $this->form_fields[ $key ]['default'] ) ? $this->form_fields[ $key ]['default'] : '';
        }
        if ( ! is_null( $empty_value ) && '' === $this->settings[ $key ] ) {
            $this->settings[ $key ] = $empty_value;
        }
        return $this->settings[ $key ];
    }

    public function process_admin_options() {
        $this->init_settings();
        foreach ( $this->form_fields as $key => $field ) {
            if ( 'title' === $field['type'] ) {
                continue;
            }
            if ( 'checkbox' === $field['type'] ) {
                $this->settings[ $key ] = ! empty( $GLOBALS['givver_test_post'][ $key ] ) ? 'yes' : 'no';
            } else {
                $this->settings[ $key ] = isset( $GLOBALS['givver_test_post'][ $key ] ) ? (string) $GLOBALS['givver_test_post'][ $key ] : '';
            }
        }
        return update_option( $this->get_option_key(), $this->settings );
    }
}

require dirname( __DIR__ ) . '/includes/class-givver-logger.php';
require dirname( __DIR__ ) . '/includes/class-givver-api-client.php';
require dirname( __DIR__ ) . '/includes/class-givver-webhook.php';
require dirname( __DIR__ ) . '/includes/class-wc-gateway-givver-base.php';
require dirname( __DIR__ ) . '/includes/class-wc-gateway-givver-card.php';

/**
 * In-memory Admin API. Records the Secret API key each call was made with.
 */
class Givver_Test_API extends Givver_API_Client {
    /** @var array<string,string> secret key => store id */
    public $key_store = array(
        'sk_test_store_a_1' => 'store_a',
        'sk_test_store_a_2' => 'store_a',
        'sk_test_store_b'   => 'store_b',
        'sk_live_store_l'   => 'store_l',
    );
    /** @var array<string, array<int, array>> store id => endpoints */
    public $endpoints = array();
    /** @var array<int, array{0:string,1:string}> [method, secret key] */
    public $calls = array();
    protected $next_id = 1;

    public function __construct() {
        parent::__construct( 'https://api.example', '', '', false );
    }

    protected function store() {
        return isset( $this->key_store[ $this->secret_key ] ) ? $this->key_store[ $this->secret_key ] : null;
    }

    public function get_webhooks() {
        $this->calls[] = array( 'get', $this->secret_key );
        $store = $this->store();
        if ( null === $store ) {
            return new WP_Error( 'givver_no_secret', 'Givver secret key is missing.' );
        }
        $list = isset( $this->endpoints[ $store ] ) ? $this->endpoints[ $store ] : array();
        return array( 'webhook_endpoints' => array_values( $list ) );
    }

    public function create_webhook( $url, $name = 'WOO', $events = array(), $enabled = true ) {
        $this->calls[] = array( 'create', $this->secret_key );
        $id     = 'we_' . $this->next_id;
        $secret = 'whsec_' . $this->next_id;
        $this->next_id++;
        $this->endpoints[ $this->store() ][ $id ] = array( 'id' => $id, 'url' => $url, 'events' => $events, 'enabled' => $enabled, 'secret' => $secret );
        return array( 'webhook_endpoint' => array( 'id' => $id ), 'secret' => $secret );
    }

    public function rotate_webhook_secret( $id ) {
        $this->calls[] = array( 'rotate', $this->secret_key );
        $secret = 'whsec_rotated_' . $this->next_id;
        $this->next_id++;
        $this->endpoints[ $this->store() ][ $id ]['secret'] = $secret;
        return array( 'secret' => $secret );
    }

    public function update_webhook( $id, $fields ) {
        $this->calls[] = array( 'update', $this->secret_key, $fields );
        $this->endpoints[ $this->store() ][ $id ] = array_merge( $this->endpoints[ $this->store() ][ $id ], $fields );
        return array();
    }

    public function call_names() {
        return array_map( function ( $call ) {
            return $call[0];
        }, $this->calls );
    }
}

class Givver_Test_Gateway extends WC_Gateway_Givver_Card {
    public function use_api( $api ) {
        $this->api_client = $api;
    }
}

$failed = 0;

function givver_assert( $ok, $label ) {
    global $failed;
    if ( $ok ) {
        echo "ok  $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
}

/**
 * Start from a fresh site. $settings are already saved before the request.
 */
function givver_reset( $settings = null ) {
    $GLOBALS['givver_test_options'] = array();
    $GLOBALS['givver_test_logs']    = array();
    if ( null !== $settings ) {
        update_option( 'woocommerce_givver_card_settings', array_merge( givver_settings( array() ), $settings ) );
    }
}

function givver_settings( $overrides ) {
    return array_merge(
        array(
            'enabled'              => 'yes',
            'testmode'             => 'yes',
            'publishable_key'      => '',
            'secret_key'           => '',
            'test_publishable_key' => '',
            'test_secret_key'      => '',
            'api_endpoint'         => 'https://api.example',
            'debug'                => 'yes',
        ),
        $overrides
    );
}

/**
 * A new request: the gateway is constructed from the saved settings.
 */
function givver_request( $api ) {
    $gateway = new Givver_Test_Gateway();
    $gateway->use_api( $api );
    return $gateway;
}

/**
 * Settings form submit: the gateway is constructed from the old settings, then saves the new ones.
 */
function givver_save( $api, $settings ) {
    $GLOBALS['givver_test_post'] = givver_settings( $settings );
    $gateway                     = givver_request( $api );
    $gateway->process_admin_options();
    return $gateway;
}

function givver_row( $mode ) {
    return Givver_Webhook::get_for_mode( $mode );
}

function givver_logged( $needle ) {
    foreach ( $GLOBALS['givver_test_logs'] as $line ) {
        if ( false !== strpos( $line, $needle ) ) {
            return true;
        }
    }
    return false;
}

// First save of the keys registers the webhook in the same request, with the new key.
givver_reset();
$api = new Givver_Test_API();
givver_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
$row = givver_row( 'test' );
givver_assert( array( 'get', 'create' ) === $api->call_names(), 'first save creates the endpoint' );
givver_assert( 'sk_test_store_a_1' === $api->calls[0][1], 'first save calls the API with the key just saved' );
givver_assert( isset( $api->endpoints['store_a'][ $row['id'] ] ), 'endpoint is created in the store of the key' );
givver_assert( $api->endpoints['store_a'][ $row['id'] ]['secret'] === $row['secret'], 'stored secret is the endpoint secret' );
givver_assert( Givver_Webhook::key_fingerprint( 'sk_test_store_a_1' ) === $row['key'], 'stored row has the key fingerprint' );
givver_assert(
    false === strpos( serialize( $GLOBALS['givver_test_options']['givver_wc_webhooks'] ), 'sk_test_store_a_1' ),
    'the Secret API key itself is not stored with the webhook'
);

// Later requests with the same settings make no API calls and log nothing.
$api->calls                   = array();
$GLOBALS['givver_test_logs'] = array();
givver_assert( true === givver_request( $api )->ensure_webhook(), 'storefront request reports the webhook as registered' );
givver_assert( array() === $api->calls, 'storefront request makes no API calls' );
givver_assert( array() === $GLOBALS['givver_test_logs'], 'storefront request logs nothing' );

// Saving again without changes only looks the endpoint up and keeps the secret.
givver_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
givver_assert( array( 'get' ) === $api->call_names(), 'unchanged save only looks the endpoint up' );
givver_assert( givver_row( 'test' ) === $row, 'unchanged save keeps the endpoint and its secret' );
givver_assert( givver_logged( 'reason=settings saved' ) && givver_logged( 'kept endpoint ' . $row['id'] ), 'unchanged save logs what it checked' );

// Givver disabled the endpoint after failed deliveries: a storefront request does not
// notice (no API call), the next settings save enables it again without rotating.
$api->endpoints['store_a'][ $row['id'] ]['enabled'] = false;
$api->calls = array();
givver_request( $api )->ensure_webhook();
givver_assert( array() === $api->calls, 'storefront request does not check whether the endpoint is enabled' );
givver_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
givver_assert( array( 'get', 'update' ) === $api->call_names(), 'save enables a disabled endpoint without rotating' );
givver_assert( array( 'enabled' => true ) === $api->calls[1][2], 'only enabled is sent when the events are already subscribed' );
givver_assert( true === $api->endpoints['store_a'][ $row['id'] ]['enabled'], 'endpoint is enabled again' );
givver_assert( givver_row( 'test' ) === $row, 'enabling keeps the stored secret' );
givver_assert( givver_logged( 'enabled endpoint ' . $row['id'] ), 'enabling is logged' );
$api->endpoints['store_a'][ $row['id'] ]['enabled'] = true;

// Keys from another store: the webhook is created in that store.
$old_id = $row['id'];
$api->calls = array();
givver_save( $api, array( 'test_secret_key' => 'sk_test_store_b' ) );
$row = givver_row( 'test' );
givver_assert( array( 'get', 'create' ) === $api->call_names(), 'key from another store creates a new endpoint' );
givver_assert( 'sk_test_store_b' === $api->calls[0][1], 'new store is looked up with the new key' );
givver_assert( isset( $api->endpoints['store_b'][ $row['id'] ] ) && $row['id'] !== $old_id, 'stored endpoint now belongs to the new store' );
givver_assert( $api->endpoints['store_b'][ $row['id'] ]['secret'] === $row['secret'], 'stored secret now signs the new store endpoint' );
givver_assert( givver_logged( 'reason=secret API key changed' ), 'log says the key changed' );

// store1 -> store2 -> store1: the store1 endpoint is reused, its secret rotated
// (the stored row now holds store2's endpoint), and it is enabled again if Givver
// disabled it while deliveries were failing.
foreach ( array( 'sk_test_store_a_1' => 'same key', 'sk_test_store_a_2' => 'new key of store1' ) as $return_key => $variant ) {
    givver_reset();
    $api = new Givver_Test_API();
    givver_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
    $first = givver_row( 'test' );
    givver_save( $api, array( 'test_secret_key' => 'sk_test_store_b' ) );
    $api->endpoints['store_a'][ $first['id'] ]['enabled'] = false;
    $api->calls = array();
    givver_save( $api, array( 'test_secret_key' => $return_key ) );
    $back = givver_row( 'test' );
    givver_assert( array( 'get', 'update', 'rotate' ) === $api->call_names(), "back to store1 ($variant): looked up, enabled, rotated" );
    givver_assert( $first['id'] === $back['id'] && $first['secret'] !== $back['secret'], "back to store1 ($variant): same endpoint, new secret" );
    givver_assert( $api->endpoints['store_a'][ $first['id'] ]['secret'] === $back['secret'], "back to store1 ($variant): stored secret signs the endpoint" );
    givver_assert( true === $api->endpoints['store_a'][ $first['id'] ]['enabled'], "back to store1 ($variant): endpoint enabled again" );
    givver_assert( 1 === count( $api->endpoints['store_a'] ), "back to store1 ($variant): no second endpoint in store1" );
}

// Another key of the same store keeps the endpoint and its secret.
givver_reset();
$api = new Givver_Test_API();
givver_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
$before     = givver_row( 'test' );
$api->calls = array();
givver_save( $api, array( 'test_secret_key' => 'sk_test_store_a_2' ) );
$after = givver_row( 'test' );
givver_assert( array( 'get' ) === $api->call_names(), 'new key of the same store only looks the endpoint up' );
givver_assert( $before['id'] === $after['id'] && $before['secret'] === $after['secret'], 'same store keeps endpoint id and secret' );
givver_assert( Givver_Webhook::key_fingerprint( 'sk_test_store_a_2' ) === $after['key'], 'same store stores the new key fingerprint' );

// Rows saved by older versions have no fingerprint: adopt it without rotating.
givver_reset( givver_settings( array( 'test_secret_key' => 'sk_test_store_a_1' ) ) );
$api = new Givver_Test_API();
$api->endpoints['store_a']['we_old'] = array( 'id' => 'we_old', 'url' => Givver_Webhook::webhook_url(), 'events' => WC_Gateway_Givver_Card::WEBHOOK_EVENTS, 'secret' => 'whsec_old' );
update_option( 'givver_wc_webhooks', array( 'test' => array( 'id' => 'we_old', 'secret' => 'whsec_old', 'url' => Givver_Webhook::webhook_url(), 'events' => WC_Gateway_Givver_Card::WEBHOOK_EVENTS ) ) );
givver_assert( true === givver_request( $api )->ensure_webhook(), 'legacy row is accepted' );
$row = givver_row( 'test' );
givver_assert( array( 'get' ) === $api->call_names(), 'legacy row is not rotated' );
givver_assert( 'whsec_old' === $row['secret'] && Givver_Webhook::key_fingerprint( 'sk_test_store_a_1' ) === $row['key'], 'legacy row keeps its secret and gets the fingerprint' );

// The store already has an endpoint for this URL, but this site has no secret for it.
givver_reset( givver_settings( array( 'test_secret_key' => 'sk_test_store_a_1' ) ) );
$api = new Givver_Test_API();
$api->endpoints['store_a']['we_manual'] = array( 'id' => 'we_manual', 'url' => Givver_Webhook::webhook_url(), 'events' => array( 'payment.succeeded' ), 'secret' => 'whsec_manual' );
givver_request( $api )->ensure_webhook();
$row = givver_row( 'test' );
givver_assert( array( 'get', 'update', 'rotate' ) === $api->call_names(), 'existing endpoint without a stored secret is subscribed and rotated' );
givver_assert( array( 'events' => WC_Gateway_Givver_Card::WEBHOOK_EVENTS ) === $api->calls[1][2], 'only the events are sent for an enabled endpoint' );
givver_assert( 'we_manual' === $row['id'] && $api->endpoints['store_a']['we_manual']['secret'] === $row['secret'], 'rotated secret is stored' );

// Turning test mode off on save registers the live endpoint with the live key.
givver_reset();
$api = new Givver_Test_API();
givver_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1', 'secret_key' => 'sk_live_store_l' ) );
$test_row   = givver_row( 'test' );
$api->calls = array();
givver_save( $api, array( 'testmode' => '', 'test_secret_key' => 'sk_test_store_a_1', 'secret_key' => 'sk_live_store_l' ) );
$live_row = givver_row( 'live' );
givver_assert( 'sk_live_store_l' === $api->calls[0][1], 'switching to live uses the live key on the same save' );
givver_assert( isset( $api->endpoints['store_l'][ $live_row['id'] ] ), 'live endpoint is created in the live store' );
givver_assert( givver_row( 'test' ) === $test_row, 'test endpoint is kept when switching to live' );

// No Secret API key for the current mode: nothing is called and the reason is logged.
givver_reset();
$api = new Givver_Test_API();
givver_save( $api, array( 'secret_key' => 'sk_live_store_l' ) );
givver_assert( array() === $api->calls, 'missing key for the current mode makes no API calls' );
givver_assert( givver_logged( 'ensure_webhook skipped: no Secret API key for test mode' ), 'missing key for the current mode is logged' );

// The webhook URL changed (site moved): the endpoint registered for the old URL
// is left alone and a new one is registered for the current URL.
givver_reset();
$api = new Givver_Test_API();
givver_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
$old_url               = 'https://old.example/?wc-api=wc_gateway_givver_card_success_url_webhook';
$stored                = get_option( 'givver_wc_webhooks' );
$stored['test']['url'] = $old_url;
update_option( 'givver_wc_webhooks', $stored );
$api->endpoints['store_a'][ $stored['test']['id'] ]['url'] = $old_url;
$api->calls = array();
givver_request( $api )->ensure_webhook();
givver_assert( array( 'get', 'create' ) === $api->call_names(), 'changed webhook URL registers a new endpoint' );
givver_assert( Givver_Webhook::webhook_url() === givver_row( 'test' )['url'], 'stored URL is the current one' );

if ( $failed > 0 ) {
    echo "\n$failed failed\n";
    exit( 1 );
}

echo "\nall passed\n";
exit( 0 );

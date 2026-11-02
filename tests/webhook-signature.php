<?php
/**
 * CLI checks for Givver webhook HMAC verification (no WordPress required).
 *
 * Run: php tests/webhook-signature.php
 */
define( 'ABSPATH', true );

function get_option( $key, $default = false ) {
    return $default;
}

function update_option( $key, $value, $autoload = true ) {
    return true;
}

function add_query_arg( $args, $url ) {
    return $url;
}

function home_url( $path = '/' ) {
    return 'https://shop.example' . $path;
}

require dirname( __DIR__ ) . '/includes/class-givver-webhook.php';

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

$secret    = 'whsec_test_secret';
$timestamp = (string) time();
$body      = '{"id":"evt_1","type":"payment.succeeded","data":{"object":{"external_order_id":"12","order_id":"ord_1"}}}';
$digest    = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
$header    = 'v1=' . $digest;

givver_assert(
    Givver_Webhook::verify_with_secrets( $body, $header, $timestamp, array( $secret ) ),
    'accepts a valid v1 signature'
);

givver_assert(
    ! Givver_Webhook::verify_with_secrets( $body, 'v1=' . str_repeat( '0', 64 ), $timestamp, array( $secret ) ),
    'rejects a wrong digest'
);

givver_assert(
    ! Givver_Webhook::verify_with_secrets( $body, $header, $timestamp, array( 'other-secret' ) ),
    'rejects a wrong secret'
);

givver_assert(
    ! Givver_Webhook::verify_with_secrets( $body, $header, (string) ( time() - 600 ), array( $secret ) ),
    'rejects a stale timestamp'
);

givver_assert(
    ! Givver_Webhook::verify_with_secrets( $body, $header, 'not-a-unix-time', array( $secret ) ),
    'rejects a non-numeric timestamp'
);

givver_assert(
    Givver_Webhook::verify_with_secrets( $body, $header, $timestamp, array( 'wrong', $secret ) ),
    'accepts when one of several secrets matches'
);

givver_assert(
    Givver_Webhook::matching_modes( $body, $header, $timestamp, array( 'live' => 'wrong', 'test' => $secret ) ) === array( 'test' ),
    'a matching secret returns only its mode'
);

givver_assert(
    Givver_Webhook::matching_modes( $body, $header, $timestamp, array( 'live' => 'other', 'test' => 'nope' ) ) === array(),
    'returns no mode when neither secret matches'
);

$live_secret = 'whsec_live_secret';
$live_header = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $live_secret );
givver_assert(
    Givver_Webhook::matching_modes( $body, $live_header, $timestamp, array( 'test' => $secret, 'live' => $live_secret ) ) === array( 'live' ),
    'live signature matches only the live secret'
);

givver_assert(
    Givver_Webhook::environment_is_authorized( array( 'test' ), 'test' ),
    'test signature authorizes a test event'
);

givver_assert(
    ! Givver_Webhook::environment_is_authorized( array( 'test' ), 'live' ),
    'test signature does not authorize a live event'
);

givver_assert(
    ! Givver_Webhook::environment_is_authorized( array( 'test' ), '' ),
    'missing environment is not authorized'
);

givver_assert(
    Givver_Webhook::matching_modes( $body, $header, $timestamp, array( 'test' => $secret, 'live' => $secret ) ) === array( 'test', 'live' ),
    'identical secrets match both modes'
);

givver_assert(
    Givver_Webhook::environment_is_authorized( array( 'test', 'live' ), 'live' ),
    'a shared secret still has to match the event environment'
);

givver_assert(
    Givver_Webhook::amounts_match( '19.99', 19.99 ),
    'amounts match across string and number'
);

givver_assert(
    Givver_Webhook::amounts_match( '19.99', array( 'numeric' => 19.99 ) ),
    'amounts match a Medusa numeric amount object'
);

givver_assert(
    Givver_Webhook::amounts_match( '19.990', '19.99' ),
    'amounts match when extra precision is the same cents'
);

givver_assert(
    ! Givver_Webhook::amounts_match( '100.00', '10.00' ),
    'a smaller payment does not match'
);

givver_assert(
    ! Givver_Webhook::amounts_match( '', '10.00' ),
    'missing amount does not match'
);

givver_assert(
    Givver_Webhook::currencies_match( 'USD', 'usd' ),
    'currency compare ignores case'
);

givver_assert(
    ! Givver_Webhook::currencies_match( 'USD', 'EUR' ),
    'different currencies do not match'
);

givver_assert(
    ! Givver_Webhook::currencies_match( '', 'usd' ),
    'missing currency does not match'
);

$converted = Givver_Webhook::requested_amount(
    array(
        'amount'            => 126.53,
        'currency_code'     => 'usd',
        'original_amount'   => 100,
        'original_currency' => 'gbp',
    )
);
givver_assert(
    Givver_Webhook::amounts_match( '100', $converted['amount'] )
        && Givver_Webhook::currencies_match( 'gbp', $converted['currency'] ),
    'a converted payment matches the original request amount and currency'
);

givver_assert(
    ! Givver_Webhook::amounts_match( '200', $converted['amount'] ),
    'a converted payment does not match a different original amount'
);

$usd = Givver_Webhook::requested_amount(
    array(
        'amount'            => array( 'numeric' => 19.99 ),
        'currency_code'     => 'usd',
        'original_amount'   => null,
        'original_currency' => null,
    )
);
givver_assert(
    Givver_Webhook::amounts_match( '19.99', $usd['amount'] ) && 'usd' === $usd['currency'],
    'a USD payment uses amount and currency_code'
);

$partial = Givver_Webhook::requested_amount(
    array(
        'amount'          => 10,
        'currency_code'   => 'usd',
        'original_amount' => 8,
    )
);
givver_assert(
    10 === $partial['amount'] && 'usd' === $partial['currency'],
    'original amount without original currency is ignored'
);

givver_assert(
    Givver_Webhook::extract_v1_signature( 'v1=abc' ) === 'abc',
    'extracts v1= from the signature header'
);

if ( $failed > 0 ) {
    echo "\n$failed failed\n";
    exit( 1 );
}

echo "\nall passed\n";
exit( 0 );

<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Givver webhook signing: store the one-time secret and verify incoming deliveries.
 *
 * Givver signs each POST with:
 *   Webhook-Timestamp: unix seconds
 *   Webhook-Signature: v1=<hex(hmac_sha256(secret, "{timestamp}.{raw_body}"))>
 */
class Givver_Webhook {

    const OPTION_KEY           = 'givver_wc_webhooks';
    const TIMESTAMP_TOLERANCE  = 300;
    const SUCCESS_URL_ENDPOINT = 'wc_gateway_givver_card_success_url';
    const WEBHOOK_ENDPOINT     = 'wc_gateway_givver_card_success_url_webhook';

    /**
     * Public storefront URL Givver redirects the customer to after payment.
     * This URL must never mark an order as paid.
     *
     * @param WC_Order $order
     * @return string
     */
    public static function success_url( $order ) {
        return add_query_arg(
            array(
                'wc-api'   => self::SUCCESS_URL_ENDPOINT,
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            ),
            home_url( '/' )
        );
    }

    /**
     * Public webhook URL Givver POSTs payment.succeeded and payment.failed to.
     *
     * @return string
     */
    public static function webhook_url() {
        return add_query_arg(
            array( 'wc-api' => self::WEBHOOK_ENDPOINT ),
            home_url( '/' )
        );
    }

    /**
     * @return array<string, array{id?: string, secret?: string, url?: string}>
     */
    public static function get_stored() {
        $stored = get_option( self::OPTION_KEY, array() );
        return is_array( $stored ) ? $stored : array();
    }

    /**
     * @param string $mode test|live
     * @return array{id?: string, secret?: string, url?: string}
     */
    public static function get_for_mode( $mode ) {
        $stored = self::get_stored();
        $row    = isset( $stored[ $mode ] ) && is_array( $stored[ $mode ] ) ? $stored[ $mode ] : array();
        return $row;
    }

    /**
     * Persist the webhook secret shown once on create/rotate.
     *
     * @param string $mode   test|live
     * @param string $id     Givver webhook endpoint id
     * @param string $secret Plain webhook signing secret
     * @param string $url    Registered URL
     */
    public static function save_for_mode( $mode, $id, $secret, $url, $events = array() ) {
        $stored           = self::get_stored();
        $stored[ $mode ]  = array(
            'id'     => (string) $id,
            'secret' => (string) $secret,
            'url'    => (string) $url,
            'events' => array_values( $events ),
        );
        update_option( self::OPTION_KEY, $stored, false );
    }

    /**
     * True when the stored endpoint already subscribes to every required event.
     *
     * @param string   $mode
     * @param string[] $required
     * @return bool
     */
    public static function has_events( $mode, $required ) {
        $row    = self::get_for_mode( $mode );
        $events = isset( $row['events'] ) && is_array( $row['events'] ) ? $row['events'] : array();
        foreach ( $required as $event ) {
            if ( ! in_array( $event, $events, true ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Remember which events the stored endpoint subscribes to, without rotating the secret.
     *
     * @param string   $mode
     * @param string[] $events
     */
    public static function save_events_for_mode( $mode, $events ) {
        $stored = self::get_stored();
        if ( empty( $stored[ $mode ] ) || ! is_array( $stored[ $mode ] ) ) {
            return;
        }
        $stored[ $mode ]['events'] = array_values( $events );
        update_option( self::OPTION_KEY, $stored, false );
    }

    /**
     * True when this site already has a signing secret for the current webhook URL.
     *
     * @param string $mode
     * @param string $url
     * @return bool
     */
    public static function is_configured_for( $mode, $url ) {
        $row = self::get_for_mode( $mode );
        return ! empty( $row['secret'] ) && ! empty( $row['id'] ) && isset( $row['url'] ) && $row['url'] === $url;
    }

    /**
     * @return string[]
     */
    public static function secrets() {
        $secrets = array();
        foreach ( self::get_stored() as $row ) {
            if ( is_array( $row ) && ! empty( $row['secret'] ) ) {
                $secrets[] = (string) $row['secret'];
            }
        }
        return $secrets;
    }

    /**
     * Read a request header (Webhook-Signature, Webhook-Timestamp, …).
     *
     * @param string $name
     * @return string
     */
    public static function get_request_header( $name ) {
        $server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        if ( ! empty( $_SERVER[ $server_key ] ) ) {
            return (string) $_SERVER[ $server_key ];
        }
        if ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            if ( is_array( $headers ) ) {
                foreach ( $headers as $key => $value ) {
                    if ( 0 === strcasecmp( (string) $key, $name ) ) {
                        return (string) $value;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Verify Givver webhook signature using stored secrets.
     *
     * @param string $raw_body
     * @return bool
     */
    public static function verify_request( $raw_body ) {
        return self::verify_with_secrets(
            $raw_body,
            self::get_request_header( 'Webhook-Signature' ),
            self::get_request_header( 'Webhook-Timestamp' ),
            self::secrets()
        );
    }

    /**
     * Modes whose stored secret matches this request. Empty when the signature is invalid.
     *
     * @param string $raw_body
     * @return string[] test and/or live, in that order
     */
    public static function matching_request_modes( $raw_body ) {
        $by_mode = array();
        foreach ( array( 'test', 'live' ) as $mode ) {
            $row = self::get_for_mode( $mode );
            if ( ! empty( $row['secret'] ) ) {
                $by_mode[ $mode ] = (string) $row['secret'];
            }
        }
        return self::matching_modes(
            $raw_body,
            self::get_request_header( 'Webhook-Signature' ),
            self::get_request_header( 'Webhook-Timestamp' ),
            $by_mode
        );
    }

    /**
     * @param string              $raw_body
     * @param string              $signature_header
     * @param string              $timestamp_header
     * @param array<string,string> $secrets_by_mode
     * @return string[]
     */
    public static function matching_modes( $raw_body, $signature_header, $timestamp_header, $secrets_by_mode ) {
        $signed = self::signed_payload( $raw_body, $signature_header, $timestamp_header );
        if ( '' === $signed || empty( $secrets_by_mode ) ) {
            return array();
        }

        $provided = self::extract_v1_signature( $signature_header );
        $matched  = array();
        foreach ( array( 'test', 'live' ) as $mode ) {
            if ( empty( $secrets_by_mode[ $mode ] ) ) {
                continue;
            }
            $expected = hash_hmac( 'sha256', $signed, (string) $secrets_by_mode[ $mode ] );
            if ( hash_equals( $expected, $provided ) ) {
                $matched[] = $mode;
            }
        }
        return $matched;
    }

    /**
     * The event environment must be one of the modes whose secret signed the body.
     *
     * @param string[] $matched_modes
     * @param string   $payload_environment
     * @return bool
     */
    public static function environment_is_authorized( $matched_modes, $payload_environment ) {
        $payload_environment = (string) $payload_environment;
        return in_array( $payload_environment, array( 'test', 'live' ), true )
            && in_array( $payload_environment, $matched_modes, true );
    }

    /**
     * Compare money amounts in cents so "19.99" and 19.990 are the same value.
     *
     * @param mixed $expected
     * @param mixed $actual
     * @return bool
     */
    public static function amounts_match( $expected, $actual ) {
        $expected_cents = self::amount_to_cents( $expected );
        $actual_cents   = self::amount_to_cents( $actual );
        return null !== $expected_cents && $expected_cents === $actual_cents;
    }

    /**
     * @param mixed $amount
     * @return int|null
     */
    public static function amount_to_cents( $amount ) {
        if ( is_array( $amount ) && isset( $amount['numeric'] ) ) {
            $amount = $amount['numeric'];
        }
        if ( is_string( $amount ) ) {
            $amount = trim( $amount );
        }
        if ( ! is_numeric( $amount ) ) {
            return null;
        }
        return (int) round( (float) $amount * 100 );
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     * @return bool
     */
    public static function currencies_match( $expected, $actual ) {
        $expected = strtolower( trim( (string) $expected ) );
        $actual   = strtolower( trim( (string) $actual ) );
        return '' !== $expected && $expected === $actual;
    }

    /**
     * Amount and currency of the payment request this plugin created.
     *
     * Givver converts non-USD payment requests to USD, so amount / currency_code
     * are the USD charge. original_amount / original_currency hold the request
     * amount and are null when the request was already USD.
     *
     * @param array $object payment.succeeded data.object
     * @return array{amount: mixed, currency: string}
     */
    public static function requested_amount( $object ) {
        $original_amount   = isset( $object['original_amount'] ) ? $object['original_amount'] : null;
        $original_currency = isset( $object['original_currency'] ) ? trim( (string) $object['original_currency'] ) : '';
        if ( null !== self::amount_to_cents( $original_amount ) && '' !== $original_currency ) {
            return array(
                'amount'   => $original_amount,
                'currency' => $original_currency,
            );
        }

        return array(
            'amount'   => isset( $object['amount'] ) ? $object['amount'] : null,
            'currency' => isset( $object['currency_code'] ) ? (string) $object['currency_code'] : '',
        );
    }

    /**
     * @param string   $raw_body
     * @param string   $signature_header
     * @param string   $timestamp_header
     * @param string[] $secrets
     * @return bool
     */
    public static function verify_with_secrets( $raw_body, $signature_header, $timestamp_header, $secrets ) {
        $signed = self::signed_payload( $raw_body, $signature_header, $timestamp_header );
        if ( '' === $signed || empty( $secrets ) ) {
            return false;
        }

        $provided = self::extract_v1_signature( $signature_header );
        foreach ( $secrets as $secret ) {
            $expected = hash_hmac( 'sha256', $signed, (string) $secret );
            if ( hash_equals( $expected, $provided ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonical "{timestamp}.{body}" string, or '' when the request cannot be verified.
     *
     * @param string $raw_body
     * @param string $signature_header
     * @param string $timestamp_header
     * @return string
     */
    protected static function signed_payload( $raw_body, $signature_header, $timestamp_header ) {
        if ( '' === $raw_body || '' === $signature_header || '' === $timestamp_header ) {
            return '';
        }
        if ( ! ctype_digit( (string) $timestamp_header ) ) {
            return '';
        }

        $timestamp = (int) $timestamp_header;
        if ( abs( time() - $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
            return '';
        }
        if ( '' === self::extract_v1_signature( $signature_header ) ) {
            return '';
        }

        return $timestamp . '.' . $raw_body;
    }

    /**
     * @param string $header
     * @return string hex digest without the v1= prefix
     */
    public static function extract_v1_signature( $header ) {
        $parts = preg_split( '/[\s,]+/', (string) $header );
        if ( ! is_array( $parts ) ) {
            return '';
        }
        foreach ( $parts as $part ) {
            if ( 0 === strpos( $part, 'v1=' ) ) {
                return substr( $part, 3 );
            }
        }
        return '';
    }
}

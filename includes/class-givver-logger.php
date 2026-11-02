<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Givver logger: writes to WooCommerce logs after redacting secrets and PII.
 */
class Givver_Logger {

    const MASK = '***';

    /**
     * Keys whose entire value is replaced (tokens, secrets, card blobs).
     * Compared after lowercasing and stripping _ and -.
     *
     * @var string[]
     */
    protected static $secret_keys = array(
        'opaquedata',
        'cardnumber',
        'cardnum',
        'pan',
        'cvv',
        'cvc',
        'cvv2',
        'publictoken',
        'banktoken',
        'linktoken',
        'secret',
        'secretkey',
        'authorization',
        'password',
        'apikey',
        'threeds',
        'threedsdata',
    );

    /**
     * Keys whose leaf value is PII and is replaced, while sibling structure is kept.
     *
     * @var string[]
     */
    protected static $pii_keys = array(
        'email',
        'custemail',
        'phone',
        'phonenumber',
        'custphonenumber',
        'street',
        'streetaddress',
        'address',
        'address1',
        'unit',
        'unitaddress',
        'address2',
        'name',
        'firstname',
        'lastname',
        'custfirstname',
        'custlastname',
        'zip',
        'zipcode',
        'postcode',
        'postalcode',
        'city',
        'state',
        'key',
    );

    /**
     * Log key.
     *
     * @var string
     */
    protected static $source = 'givver-woocommerce';

    /**
     * Write a message after redacting emails, keys, and sensitive query params.
     *
     * @param string $message
     * @param string $level
     */
    public static function log( $message, $level = 'info' ) {
        if ( ! class_exists( 'WC_Logger' ) ) {
            return;
        }

        $logger = wc_get_logger();
        $logger->log( $level, self::redact_string( (string) $message ), array( 'source' => self::$source ) );
    }

    /**
     * JSON-encode a value after redaction. Safe to append to a log line.
     *
     * @param mixed $data
     * @return string
     */
    public static function encode( $data ) {
        $redacted = self::redact( $data );
        if ( function_exists( 'wp_json_encode' ) ) {
            $json = wp_json_encode( $redacted );
        } else {
            $json = json_encode( $redacted );
        }
        return false === $json ? self::MASK : $json;
    }

    /**
     * Recursively replace secrets and PII with ***.
     *
     * @param mixed $value
     * @param string|null $key
     * @return mixed
     */
    public static function redact( $value, $key = null ) {
        if ( null !== $key ) {
            $kind = self::key_kind( $key );
            if ( 'secret' === $kind || 'pii' === $kind ) {
                return self::MASK;
            }
        }

        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $child_key => $child ) {
                $out[ $child_key ] = self::redact( $child, (string) $child_key );
            }
            return $out;
        }

        if ( is_object( $value ) ) {
            return self::redact( (array) $value, $key );
        }

        if ( is_string( $value ) ) {
            return self::redact_string( $value );
        }

        return $value;
    }

    /**
     * Redact emails, API keys, and sensitive query parameters inside a free-text string.
     *
     * @param string $message
     * @return string
     */
    public static function redact_string( $message ) {
        $message = (string) $message;

        if ( false !== strpos( $message, '://' ) || false !== strpos( $message, '?' ) ) {
            $message = preg_replace_callback(
                '/https?:\/\/[^\s\'"]+/i',
                function ( $matches ) {
                    return self::redact_url( $matches[0] );
                },
                $message
            );
        }

        $message = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', self::MASK, $message );
        $message = preg_replace( '/\b((?:sk|pk)_(?:live|test)_)[A-Za-z0-9]+/', '$1' . self::MASK, $message );
        $message = preg_replace( '/\b(Basic\s+)[A-Za-z0-9+\/=]+/i', '$1' . self::MASK, $message );

        return $message;
    }

    /**
     * @param string $url
     * @return string
     */
    public static function redact_url( $url ) {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
            return $url;
        }

        $query = array();
        parse_str( $parts['query'], $query );
        $query = self::redact( $query );

        $rebuilt  = '';
        if ( ! empty( $parts['scheme'] ) ) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if ( ! empty( $parts['host'] ) ) {
            $rebuilt .= $parts['host'];
        }
        if ( ! empty( $parts['port'] ) ) {
            $rebuilt .= ':' . $parts['port'];
        }
        if ( isset( $parts['path'] ) ) {
            $rebuilt .= $parts['path'];
        }
        $rebuilt .= '?' . http_build_query( $query );
        if ( ! empty( $parts['fragment'] ) ) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * @param string $key
     * @return string|null secret|pii|null
     */
    protected static function key_kind( $key ) {
        $normalized = strtolower( preg_replace( '/[_-]/', '', (string) $key ) );
        if ( in_array( $normalized, self::$secret_keys, true ) ) {
            return 'secret';
        }
        if ( in_array( $normalized, self::$pii_keys, true ) ) {
            return 'pii';
        }
        return null;
    }
}

<?php

namespace Homer\Payment\Alipay\Hashing;

class OpensslHasher
{
    /**
     * Hash the given value.
     *
     * @param string  $value
     * @param array $options     options including
     *                           - private_key     (mandatory) file path to private key
     *                           - algorithm       (optional) algorithm used to sign
     *                           - base64          (optional) base64-encode the signature if turned on
     *                                                        by default, it's turned on
     * @return string
     */
    public function hash($value, array $options = [])
    {
        // find private key and algorithm to sign
        $key = openssl_get_privatekey(file_get_contents(array_get($options, 'private_key')));
        $algorithm = array_get($options, 'algorithm', OPENSSL_ALGO_SHA1);

        openssl_sign($value, $signature, $key, $algorithm);
        openssl_free_key($key);

        // base64 encode the signature on demand
        return array_get($options, 'base64', true) ? base64_encode($signature) : $signature;
    }

    /**
     * Check the given plain value against a hash.
     *
     * @param string  $value
     * @param string  $hashedValue
     * @param array $options         options including
     *                               - public_key      (mandatory) file path to public key
     *                               - algorithm       (optional) algorithm used to sign
     *                               - base64          (optional) base64-decode the signature if turned on
     *                                                            by default, it's turned on
     * @throws \Exception
     * @return bool
     */
    public function check($value, $hashedValue, array $options = [])
    {
        // find public key
        $key = openssl_get_publickey(file_get_contents(array_get($options, 'public_key')));
        // if hashedValue is in base64 format, decode it first
        $hashedValue = array_get($options, 'base64', true) ? base64_decode($hashedValue) : $hashedValue;
        $algorithm = array_get($options, 'algorithm', OPENSSL_ALGO_SHA1);

        $ok = openssl_verify($value, $hashedValue, $key, $algorithm);
        openssl_free_key($key);

        if ($ok == -1) { // error happens
            throw new \Exception(openssl_error_string());
        }

        return $ok === 1;
    }
}
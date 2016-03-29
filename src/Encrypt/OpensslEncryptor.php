<?php

namespace Homer\Payment\Alipay\Encrypt;

class OpensslEncryptor
{
    /**
     * decrypt the cipher text
     *
     * @param string $cipher
     * @param array $options   options including
     *                         - key             (mandatory) file path to private key
     *                         - base64          (optional) base64-decode the signature if turned on
     *                                                      by default, it's turned on
     *                         - block_size      (optional) block size, default to 128
     *                         - padding         (optional) padding for decryption, default OPENSSL_PKCS1_PADDING
     *
     * @return string|null
     */
    public function privateDecrypt($cipher, array $options = [])
    {
        $res = openssl_get_privatekey(file_get_contents(array_get($options, 'key')));
        $cipher = array_get($options, 'base64', true) ? base64_decode($cipher) : $cipher;
        $blockSize = array_get($options, 'block_size', 128);
        $padding = array_get($options, 'padding', OPENSSL_PKCS1_PADDING);

        $decrypted = '';
        $success = true;
        for($i = 0, $n = strlen($cipher) / $blockSize; $i < $n; ++$i) {
            $block = substr($cipher, $i * $blockSize, $blockSize);
            $success = openssl_private_decrypt($block, $blockDecrypted, $res, $padding);
            if ($success) {
                $decrypted .= $blockDecrypted;
            } else {
                break;
            }
        }
        openssl_free_key($res);

        return $success ? $decrypted : null;
    }
}
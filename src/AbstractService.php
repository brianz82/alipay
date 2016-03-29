<?php

namespace Homer\Payment\Alipay;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Homer\Payment\Alipay\Encrypt\OpensslEncryptor;

/**
 * base class for alipay service
 */
abstract class AbstractService
{
    const REFUND_URL = 'https://mapi.alipay.com/gateway.do';
    
    /**
     * partner's id (under contract to Alipay). a 16-digit numbers, starting with '2088'
     * 
     * @var string
     */
    protected $partner;
    
    /**
     * seller's account on alipay, e.g., email/mobile
     * @var string
     */
    protected $seller;
    
    /**
     * secure key
     * @var string
     */
    protected $secureKey;
    
    /**
     * url to be notified when trade status changes
     * @var string
     */
    protected $notifyUrl;
    
    /**
     * url to be notified when trade refund status changes
     * @var string
     */
    protected $refundNotifyUrl;
    
    /**
     * file path to partner's private key. It's used to sign trade data before sending
     * to Alipay. 
     * 
     * @var string
     */
    private $certFile;
    
    /**
     * file path to Alipay's public key, which is used to verify that the incoming
     * data is sent by Alipay.
     * 
     * @var string
     */
    private $aliCertFile;
    
    /**
     * Http client
     * @var \GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * @param array $config     configuration.
     *                          - partner             partner id (under contract to Alipay). a  16-digit
     *                                                numbers, starting with '2088'
     *                          - cert_file           partner's private key(points to a file in .pem format)
     *                          - ali_cert_file       alipay's public key (points to a file in .pem format)
     *                          - seller              seller's id/account on alipay, e.g., mail/mobile
     *                          - notify_url          asynchronous notification url on trade payment
     *                          - refund_notify_url   asynchronous notification url on trade refund
     *                          - secure_key          the secure key (安全校验码) going with the seller
     *                                            
     * @param ClientInterface $client
     */
    public function __construct(array $config, ClientInterface $client = null)
    {
        $this->partner          = array_get($config, 'partner');
        $this->certFile         = array_get($config, 'cert_file');
        $this->aliCertFile      = array_get($config, 'ali_cert_file');

        // seller's id and partner id sometimes can be used interchangeably,
        // but it's better to distinguish them
        $this->seller           = array_get($config, 'seller', $this->partner);

        $this->notifyUrl        = array_get($config, 'notify_url');
        $this->refundNotifyUrl  = array_get($config, 'refund_notify_url');
        $this->secureKey        = array_get($config, 'secure_key');
        
        $this->client           = $client ?: $this->createDefaultHttpClient();
    }

    /**
     * handle refund for multiple trades
     *
     * @param array $trades       trades that need refunding. Each element in this array is also an array, whose
     *                            elements should be like this: [ trade#, fee, reason, options ]. Refer to
     *                            static::refundTrade() for more about those fields.
     *
     * @param array $options      (optional) additional options. accepted fields are:
     *                            - seq_no  sequence# for this refund
     *
     * @return \stdClass          result of this refund. with following fields set:
     *                            - status 'REFUND_SUCCESS' for success,
     *                                     'REFUND_FAILED' for failure,
     *                                     'REFUND_PENDING' for pending(where an asynchronous notification will be sent)
     *                            - msg    when status equals 'REFUND_FAILED', this field gives out
     *                                     the reason why it failed
     *                            - seqNo  sequence# for this refund
     *
     * @throws \IllegalArgumentException
     */
    public function refundTrades(array $trades, array $options = [])
    {
        if (!($refundTradeNo = array_get($options, 'seq_no'))) {
            $refundTradeNo = $this->genRefundTradeNumber();
        }

        if (($batches = count($trades)) > 1000) {
            throw new \IllegalArgumentException('退款总笔数不能超过1000笔');
        }

        // detail data
        $detail = '';
        foreach ($trades as $trade) {
            list($tradeNo, $fee, $reason, $opts) = $trade;
            $detail .= $this->formatRefundTradeDetail($tradeNo, $fee, $reason, $opts) . '#';
        }
        $detail = rtrim($detail, '#');

        // NOTE: the following $params are sorted manually (to save some CPU)
        //       If a new key needs adding, do not break this rule. Otherwise,
        //       to fix it up you should call:
        //
        //          self::sort($params);
        //
        $params = [
            '_input_charset' => 'utf-8',
            'batch_no'       => $refundTradeNo,
            'batch_num'      => $batches,
            'detail_data'    => $detail,
            'notify_url'     => $this->refundNotifyUrl,
            'partner'        => $this->partner,
            'refund_date'    => date('Y-m-d H:i:s'),
            'return_type'    => 'xml',
            'service'        => 'refund_fastpay_by_platform_nopwd',
        ];

        $params['sign'] = $this->md5Sign(self::implode(array_filter($params)));
        $params['sign_type'] = 'MD5';

        $result = $this->postRefundRequestAndParse($params);
        $result->seqNo = $params['batch_no'];  // also append batch#, as it will be used on
        // asynchronous notification is received

        return $result;
    }
    
    /**
     * Refund for a trade
     *
     * @param string $tradeNo     the trade#
     * @param float $fee          total amount of money to refund
     * @param string $reason      reason
     * @param array $options      options
     *                            - sub    sub trade
     *                                - fee        fee to refund for this sub trade
     *                                - reason     reason why refunding this sub trade
     *                            - profit       (array) for shared profit refund. each element in this array is
     *                                           another array, and in turn each element complies with this format:
     *                                           [ 转出人支付宝账号/原收到分润金额的账户,
     *                                             转出人支付宝账号对应用户ID(2088开头16位纯数字),
    //                                             转入人支付宝账号/原付出分润金额的账户,
     *                                             转入人支付宝账号对应用户ID,
    //                                             退款金额,
     *                                             退款理由     --- optional, default to '协商退款'
     *                                           ]
     *                            - seq_no      sequence# for this refund
     * @return \stdClass          result of this refund. with following fields set:
     *                            - status 'REFUND_SUCCESS' for success,
     *                                     'REFUND_FAILED' for failure,
     *                                     'REFUND_PENDING' for pending(where an asynchronous notification will be sent)
     *                            - msg    when status equals 'REFUND_FAILED', this field gives out
     *                                     the reason why it failed
     *                            - seqNo  sequence# for this refund
     *
     * @throws \IllegalArgumentException
     */
    public function refundTrade($tradeNo, $fee, $reason = '协商退款', array $options = [])
    {
        return $this->refundTrades([
            [ $tradeNo, $fee, $reason, $options ]
        ], array_filter([
            'seq_no' => array_key_exists('seq_no', $options) ? $options['seq_no'] : null
        ]));
    }

    private function formatRefundTradeDetail($tradeNo, $fee, $reason, array $options = [])
    {
        // detail of refund can contain several parts
        //
        // the first and mandatory part is 交易退款数据 for the whole refund, whose format is
        //    原付款支付宝交易号^退款总金额^退款理由
        $detail = implode('^', [$tradeNo, $fee, $reason]);

        //
        // optionally, refund for profit sharing(分润) can appear
        //
        if (($profitRefunds = array_get($options, 'profit'))) {
            foreach ($profitRefunds as $profitRefund) {
                $detail .= '|' . $this->formatRefundProfitItem($profitRefund, $reason);
            }
        }

        //
        // the last part (also optional) is 子交易退款数据, whose format is
        //    金额^退款理由
        //
        if (($subTradeRefund = array_get($options, 'sub'))) {
            $detail .= '$$' . implode('^', [
                array_get($subTradeRefund, 'fee'),
                array_get($subTradeRefund, 'reason', $reason)
            ]);
        }

        return $detail;
    }

    private function formatRefundProfitItem(array $profitRefund, $defaultReason)
    {
        //
        // 分润退款数据格式: 转出人支付宝账号[原收到分润金额的账户]^转出人支付宝账号对应用户ID[2088开头16位纯数字]^
        //                 转入人支付宝账号[原付出分润金额的账户]^转入人支付宝账号对应用户ID^
        //                 退款金额^退款理由。
        if (count($profitRefund) == 5) { // reason is missing
            $profitRefund[] = $defaultReason;
        }

        return implode('^', $profitRefund);
    }
    
    private function postRefundRequestAndParse($params)
    {
        $response = $this->client->request('POST', self::REFUND_URL, [ RequestOptions::FORM_PARAMS => $params ]);
        if ($response->getStatusCode() != 200) {
            throw new \Exception('bad response from alipay: ' . (string)$response->getBody());
        }
        $xml = simplexml_load_string((string)$response->getBody());
    
        $result = new \stdClass();
        $result->status = 'REFUND_PENDING';
        $result->msg = NULL;
    
        if (isset($xml->is_success)) {
            switch ((string)$xml->is_success) {
                case 'T':
                    $result->status = 'REFUND_SUCCESS';
                    break;
                case 'F':
                    $result->status = 'REFUND_FAILED';
                    break;
                case 'P':
                    $result->status = 'REFUND_PENDING';
                    break;
                default:
                    throw new \Exception('Unknown refund status: ' . (string)$xml->is_success);
            }
        }
    
        if ($result->status == 'REFUND_FAILED') {
            $result->msg = $xml->error;
        }
    
        return $result;
    }
    
    // generate seq# for the refund trade
    private function genRefundTradeNumber()
    {
        // constraint: 退款日期(8位当天日期)+流水号(3~24位, 不能接受“000”,但是可以接受英文字符)
        //
        // Our implementation:
        // - 14 chars     current date(8) and time (6)
        // - 13 chars     generate with uniqid()
        // -  5 chars     random number between [1, 99999]. if not 5 in length, left pad with '0'
        //
        return date('YmdHis') . uniqid() . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
    
    /**
     * asynchronous notification on refund
     * @param array $notification
     * @param callable $callback    callback function. trade info will be given, with
     *                              - seqNo          the batch no
     *                              - notifyTime     the notification time
     *                              - details        an array of refund details, each element contains
     *                                               - tradeNo       the trade#
     *                                               - fee           fee
     *                                               - status        REFUND_SUCCESS or REFUND_CLOSED
     * @return \stdClass
     */
    public function refundTradeUpdated(array $notification, callable $callback)
    {
        $this->ensureResponseNotForged($notification);
    
        $trade = new \stdClass();
        $trade->seqNo = $notification['batch_no'];
        $trade->notifyTime   = $notification['notify_time'];
    
        $trade->details = [];
        $details = explode('#', $notification['result_details']);
        foreach ($details as $detail) {
            $tokens = explode('^', $detail);
             
            $item = new \stdClass();
            $item->tradeNo = $tokens[0];
            $item->fee     = floatval($tokens[1]);
            $item->status  = $tokens[2] == 'SUCCESS' ? 'REFUND_SUCCESS' : $tokens[2];
             
            $trade->details[] = $item;
        }
    
        if (call_user_func($callback, $trade)) {
            echo 'success';
        } else {
            echo 'fail';
        }
    }
    
    /**
     * RSA sign given text and encode the result with base64 encoding
     *
     * @param string $text text to be processed
     * @return string signed and base64 encoded text
     */
    private function rsaSign($text)
    {
        return $this->createHasher()->hash($text, [ 'private_key' => $this->certFile ]);
    }
    
    /**
     * MD5 sign given text
     *
     * @param string $text
     * @return string
     */
    private function md5Sign($text)
    {
        return md5($text . $this->secureKey);
    }
    
    /**
     * sign request
     *
     * @param string $request  request to be signed
     * @param string $type     sign type, 'RSA'| '0001', or 'MD5'
     *
     * @throws \Exception      if sign type is not acceptable
     * @return string          signed and base64 encoded text
     */
    protected final function signRequest($request, $type = 'RSA')
    {
        switch ($type) {
            case 'RSA':
            case '0001':
                return $this->rsaSign($request);
            case 'MD5':
                return $this->md5Sign($request);
            default:
                throw new \Exception('Unknown sign type: ' . $type);
        }
    }
    
    /**
     * ensure that response comes from alipay.
     * 
     * NOTE: signature will always be removed from given notification
     * 
     * @param array $response          the notification to verify
     * @param string $signTypeParam    name/key for sign type (typically, it's 'sign_type' (for SDK) or 'sec_id' (for WAP))
     * @param string $signParam        name/key for signature (typically, it's 'sign')
     * @param bool $keepSignType       flag to indicate whether the item denoting 'sign type' should be removed from $response 
     *                                 (true to remove, false to leave it intact)
     * @param bool $needSort           flag to indicate whether the response array should be sorted (by its key)
     * 
     * @throws \Exception
     */
    protected final function ensureResponseNotForged(array $response, 
                                                     $signTypeParam = 'sign_type', 
                                                     $signParam = 'sign', 
                                                     $keepSignType = false, 
                                                     $needSort = true)
    {
        if (!isset($response[$signTypeParam])) {
            throw new \Exception('Forged trade notification');
        }
    
        $signType = $response[$signTypeParam];
        $signature = $response[$signParam];
        // to verify, we need remove both 'sign' and 'sign_type'
        unset($response[$signParam]);
        if (!$keepSignType) {
            unset($response[$signTypeParam]);
        }
    
        if ($needSort) {
            self::sort($response);
        }
    
        switch ($signType) {
            case 'RSA':
            case '0001':  // in wap service '0001' has the same meaning of 'RSA'
                if(!$this->rsaVerify(self::implode($response), $signature)) {
                    throw new \Exception('Signature verification failed');
                }
                break;
            case 'MD5':
                if(!$this->md5Verify(self::implode($response), $signature)) {
                    throw new \Exception('Signature verification failed');
                }
                break;
            default:
                throw new \Exception('Unknown sign type: ' . $signType);
        }
    }
    
    /**
     * RSA verification - check the given text against the signature with RSA
     *
     * @param string $text  the text to be verified
     * @param string $signature the signature claimed. It's base64 encoded.
     * @return boolean true if the signature is valid. false otherwise.
     */
    private function rsaVerify($text, $signature)
    {
        return $this->createHasher()->check($text, $signature, [ 'public_key' => $this->aliCertFile ]);
    }
    
    /**
     * MD5 verification - check the given text against the signature with RSA
     *
     * @param string $text  the text to be verified
     * @param string $signature the signature claimed.
     * @return boolean true if the signature is valid. false otherwise.
     */
    private function md5Verify($text, $signature)
    {
        return md5($text . $this->secureKey) == $signature;
    }
    
    /**
     * RSA decryption - decrypt given cipher text
     * @param string $cipherText   cipher text to decrypt
     * @return string              plain text
     */
    protected final function rsaDecrypt($cipherText)
    {
        $encryptor = new OpensslEncryptor();
        return $encryptor->privateDecrypt($cipherText, [
            'key'  => $this->certFile
        ]);

    }
    
    /**
     * sort given array by its key
     * 
     * @param array $params
     */
    protected static function sort(array &$params)
    {
        ksort($params);
        reset($params);
    }
    
    /*
     * implode associated array while keeping both its keys and values intact, and
     * this is the reason why http_build_query is not used
     */
    protected static function implode(array $assoc, $inGlue = '=', $outGlue = '&') {
        $return = '';
    
        foreach ($assoc as $name => $value) {
            $return .=  $name . $inGlue . $value . $outGlue;
        }
    
        return substr($return, 0, -strlen($outGlue));
    }

    /**
     * @return \Homer\Payment\Alipay\Hashing\OpensslHasher
     */
    private function createHasher()
    {
        return new \Homer\Payment\Alipay\Hashing\OpensslHasher();
    }

    /*
     * create default http client
     *
     * @return Client
     */
    private function createDefaultHttpClient()
    {
        return new Client();
    }
}
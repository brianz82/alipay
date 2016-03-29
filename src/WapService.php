<?php
namespace Homer\Payment\Alipay;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/* reference: http://wohugb.gitbooks.io/alipay-wap/content/
 *
 * Q: How AlipayWap works?
 * A: The first step is to send trade request to alipay, and will be responded with 
 *    a form to the client(i.e., browser). User submits/cancels that form, and be redirected
 *    to either configured success (if payment succeeds) or abort (if user cancels) page.
 *    Server can get not only synchronous notification (via the configed success_url),
 *    but also asynchronous notification(via the configed notify_url).
 *    
 * Q: How's that implemented?
 * A: Step 1). Send trade request
 *       The prepareTrade() does this. Before sending, an authorization must be gained. 
 *       authorizeTrade() send authorization request to alipay, and a token is obtained as 
 *       the credential for authorization.
 *    Step 2). Synchronous notification
 *       This notification can be received once user pays successfully. Client sends a
 *       request (form obtained from last step) to alipay gateway, and the configured 
 *       success_url will be posted with some trade data, which is initiated by alipay.
 *       That's how we get this synchronous notification.
 *       On this synchronous notification, we do know that user pays successfully. To 
 *       get the trade data sent back, call tradePaid(), and it will shows that data.
 *    Step 3). Asynchronous notification
 *       When trade status changes, asynchronous notification will be received. Call tradeUpdated()
 *       to get the current trade.
 * 
 */
class WapService extends AbstractService
{
    const GATEWAY_URL = 'http://wappaygw.alipay.com/service/rest.htm';
    
    /**
     * On a successful payment, the web page will be redirect to
     * @var string
     */
    private $successUrl;
    
    /**
     * After payment is abort, the web page will be redirect to
     * @var string
     */
    private $abortUrl;
    
    /**
     * '0001' for 'RSA'  
     * 'MD5'  for 'MD5'. 
     * @var string
     */
    private $signType = '0001'; 
    
    /**
     * @see \Jihe\Services\Payment\Alipay\AbstractAlipayService::__construct()
     * @param array $config        besides configuration options in parent class, extra options are:
     *                             - successUrl   On a successful payment, the web page will be redirect to
     *                             - abortUrl     User may abort the payment. If true, redirect to this url
     *                             - 
     * @param ClientInterface $httpClient
     */
    public function __construct(array $config, ClientInterface $httpClient = null)
    {
        parent::__construct($config, $httpClient);
        
        $this->successUrl = array_get($config, 'success_url');
        $this->abortUrl   = array_get($config, 'abort_url');
    }
    
    /**
     * prepare for a trade
     * 
     * @param string $orderNo     order no
     * @param float $fee          amount (in RMB) of money to be paid (range: [0.01, 10000000.00])
     * @param string $expiry      expire time in minutes. default to 60 min (range [1, 21600], 21600min = 15days)
     * @param string $subject     Name of product/service being paid
     * 
     * @return \stdClass   preparation result. with the following fields set:
     *                     - params          params to be used (to form a HTML form> next
     *                     - formAction      where params will be sent to
     */
    public function prepareTrade($orderNo, $fee, $expiry = null, $subject = ' ') 
    {
        $token = $this->authorizeTrade($orderNo, $fee, $expiry, $subject);
        
        // after authorization token is grabbed, do the actual prepare
        $requestData = '<auth_and_execute_req><request_token>' . $token . '</request_token></auth_and_execute_req>';
        // NOTE: the following $params are sorted manually (to save some CPU)
        //       If a new key needs adding, do not break this rule. Otherwise,
        //       to fix it up you should call:
        //
        //          self::sort($params);
        //
        $params = [
            '_input_charset'  => 'utf-8',
            'format'          => 'xml',
            'partner'         => $this->partner,
            'req_data'        => $requestData,
            'sec_id'          => $this->signType,
            'service'         => 'alipay.wap.auth.authAndExecute',
            'v'               => '2.0'
        ];
        $params['sign'] = $this->signRequest(self::implode($params), $this->signType);
        
        $result = new \stdClass();
        $result->params = $params;
        $result->formAction = self::GATEWAY_URL;
        
        return $result;
    }
    
    /**
     * authorize the trade to gain a token
     */
    private function authorizeTrade($orderNo, $fee, $expiry = null, $subject = ' ')
    {
        // build request data
        $requestData = '<direct_trade_create_req><notify_url>' . $this->notifyUrl . '</notify_url>'.
                       '<call_back_url>' . $this->successUrl . '</call_back_url>'.
                       '<seller_account_name>' . $this->seller . '</seller_account_name>'.
                       '<out_trade_no>' . $orderNo . '</out_trade_no>'.
                       '<subject>' . $subject . '</subject>' .
                       '<total_fee>' . $fee . '</total_fee><merchant_url>' . $this->abortUrl . '</merchant_url>' .
                       '<pay_expire>' . $expiry . '</pay_expire></direct_trade_create_req>';
        
        // NOTE: the following $params are sorted manually (to save some CPU)
        //       If a new key needs adding, do not break this rule. Otherwise,
        //       to fix it up you should call:
        //
        //          self::sort($params);
        //
        $params = array('_input_charset'  => 'utf-8',
                        'format'          => 'xml',
                        'partner'         => $this->partner,
                        'req_data'        => $requestData,
                        'req_id'          => date('YmdHis') . uniqid(),
                        'sec_id'          => $this->signType,
                        'service'         => 'alipay.wap.trade.create.direct',
                        'v'               => '2.0');
    
        $params['sign'] = $this->signRequest(self::implode($params), $this->signType);
        $response = $this->httpClient->request('POST', self::GATEWAY_URL, [ RequestOptions::FORM_PARAMS => $params ]);
        if ($response->getStatusCode() != 200) {
            throw new \Exception('alipay server error: ' . (string)$response->getBody());
        }
        
        return $this->verifyAndParseAuthorizeResponse((string)$response->getBody());
    }
    
    // verify and parse response of authorization
    private function verifyAndParseAuthorizeResponse($response)
    {
        parse_str($response, $data);
        if (isset($data['res_error'])) { // there's something wrong
            $xml = simplexml_load_string($data['res_error']);
            throw new \Exception((string)$xml->detail);
        }
         
        $this->ensureResponseNotForged($data, 'sec_id');
        $xml = simplexml_load_string($data['res_data']);
         
        return (string)$xml->request_token;
    }
    
    /**
     * Invoked on user paid the trade (sync). Will not be trigged if user did not.
     * 
     * => this method should be called in $this->successUrl.
     * 
     * @param array $notification
     *
     * @return object payment result. with the following fields set:
     *                     - tradeNo      trade#
     *                     - orderNo      order#
     *                     - status       'SUCCESS' for successfully paid (currently only 'SUCCESS' is valid)
     *                     - authorizeToken the token obtained from the authorization phrase
     */
    public function tradePaid(array $notification)
    {
        // If neither 'sign_type' nor 'sec_id' is there, the signature
        // is performed based on our configuration => Forge a param named 'sec_id'
        // (and valued the configed sign type) so that we can leverage our 
        // ensureResponseNotForged() method
        $signTypeParam = 'sec_id';
        if (isset($notification['sign_type'])) {
            $signTypeParam = 'sign_type';
        } elseif (isset($notification['sec_id'])) {
            $signTypeParam = 'sec_id';
        } else {
            $notification[$signTypeParam] = $this->signType;
        }
        $this->ensureResponseNotForged($notification, $signTypeParam);
         
        $result = new \stdClass();
        $result->tradeNo = $notification['trade_no'];
        $result->orderNo = $notification['out_trade_no'];
        $result->status  = strtoupper($notification['result']);
        $result->authorizeToken = $notification['request_token'];
         
        return $result;
    }
    
    /**
     * async notification for trade update
     * @param array $notification
     *
     * @return object an object with the following fields set
     *                1). orderNo       -- the order# related to the trade
     *                2). status        -- trade status
     *                3). tradeNo       -- the trade# (in Alipay's system)
     *                4). fee           -- total price
     *                5). creationTime  -- when the trade is created(initiated)
     *                6). paymentTime   -- when the trade is paid
     *                7). notifyTime    -- when this notification is delivered
     */
    public function tradeUpdated(array $notification, callable $callback)
    {
        // WAP notification for trade uses a fixed number of params for verification
        // NOTE: DO NOT MODIFY THE ORDER OF THE KEYS BELOW
        $usedNotification = [
            'service'     => $notification['service'],
            'v'           => $notification['v'],
            'sec_id'      => $notification['sec_id'],
            'notify_data' => $this->rsaDecrypt($notification['notify_data']),
            'sign'        => $notification['sign'],
        ];
    
        $this->ensureResponseNotForged($usedNotification, 'sec_id', 'sign', true, false);
        $xml = simplexml_load_string($usedNotification['notify_data']);
        
        $trade = new \stdClass();
        $trade->status       = (string)$xml->trade_status;
        $trade->orderNo = (string)$xml->out_trade_no;
        $trade->tradeNo = (string)$xml->trade_no;
        $trade->fee = floatval($xml->total_fee);
        $trade->creationTime = (string)$xml->gmt_create;
        $trade->paymentTime = (string)$xml->gmt_payment;
        // $trade->closeTime  = (string)$xml->gmt_close;   // gmt_close is not always available
        $trade->notifyTime = (string)$xml->notify_time;
        
        if (call_user_func($callback, $trade)) {
            echo 'success';
        } else {
            echo 'fail';
        }
    }
}
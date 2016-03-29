<?php
namespace Homer\Payment\Alipay;

class Service extends AbstractService
{
    /**
     * prepare for a pay
     *
     * @param string $orderNo      Order no, no more than 64 in length
     * @param float $fee           Amount (in RMB) of money to be paid (range: [0.01, 10000000.00])
     * @param string $subject      Name of product/service being paid, at most 128 characters
     * @param string $description  Description of product/service being paid
     * @param string $paymentType  Type of the payment. '1' for 商品购买
     * @param string $expiry       Expire time (range: [1m, 15d], where 'for' miniute, 'h' for hour, 'd' for day, '1c' for the all day long(expire at 0:00AM))
     * @param string $accessToken  Access token (returned by alipay, also called session key)
     *
     * @return string              request with signature
     */
    public function prepareTrade($orderNo, $fee, 
                                 $subject = ' ', 
                                 $description = ' ',
                                 $paymentType = '1',
                                 $expiry = null,
                                 $accessToken = null)
    {
        // NOTE: the following $params are sorted manually (to save some CPU)
        //       If a new key needs adding, do not break this rule. Otherwise,
        //       to fix it up you should call:
        //
        //          self::sort($params);
        //
        $params = [
            '_input_charset' => 'utf-8',
            'body'           => $description,
            'extern_token'   => $accessToken,
            'it_b_pay'       => $expiry,
            'notify_url'     => $this->notifyUrl,
            'out_trade_no'   => str_pad($orderNo, 10, '0', STR_PAD_LEFT),
            'partner'        => $this->partner,
            'payment_type'   => $paymentType,
            'seller_id'      => $this->seller,
            'service'        => 'mobile.securitypay.pay',
            'subject'        => $subject,
            'total_fee'      => $fee,
        ];
    
        // all values in $params should be quoted with double-quote
        $request = self::implode(array_filter($params), '="', '"&') . '"';
        return $request.'&sign="' . urlencode($this->signRequest($request, 'RSA')) . '"&sign_type="RSA"';
    }
    
    /**
     * Called when a trade's status changes in asynchronous manner. Consumers of this method should understand
     * the meaning of each trade status:
     * 1).TRADE_SUCCESS  -- money transferred, but product not yet delivered
     * 2).TRADE_FINISHED -- money transferred, and product delivered
     * 3).In case of other statuses, exception will be raised
     *
     * @param array $notification notification (typically the whole $_POST) from Alipay
     * @param callable $callback  callback   callback will be passed the parsed trade as its param.
     *                                       the return value of callback function should be a boolean.
     *                                       when true, the trade is successfully handled.
     *
     *  callback trade object an object with two fields set
     *                1). orderNo   the order# related to the trade
     *                2). status    trade status
     *                              - TRADE_SUCCESS    money transferred, but product not yet delivered
     *                              - TRADE_FINISHED   money transferred, and product delivered
     *
     * @throws \Exception exception will thrown in case of invalid signature
     *                    or bad trade status
     */
    public function tradeUpdated(array $notification, callable $callback)
    {
        $this->ensureResponseNotForged($notification);
        
        $trade = $this->parseTradeUpdateNotification($notification);
        if ('TRADE_SUCCESS'  == $trade->status ||
            'TRADE_FINISHED' == $trade->status) {
            //
            // == TRADE_FINISHED vs. TRADE_SUCCESS ==
            // TRADE_FINISHED -- the trade is completely closed (money transferred and product delivered) 交易成功
            // TRADE_SUCCESS  -- Money transfer completes, but the trade is not yet closed   支付成功
            //
            // So order status will always be in TRADE_FINISHED after TRADE_SUCCESS
            //
            if (call_user_func($callback, $trade)) {
                echo 'success';
            } else {
                echo 'fail';
            }
        } elseif ('TRADE_CLOSED'   == $trade->status  ||
                  'WAIT_BUYER_PAY' == $trade->status) {
            // Won't be notified at all on those status by default settings
            //
            // WAIT_BUYER_PAY -- Wait for payment 交易创建
            // TRADE_CLOSED   -- Trade is closed 交易关闭
            //
        } else {
            throw new \Exception($trade->orderNo . '\'s status is ' . $trade->status .
                                 '. But I don\'t know how to handle it.');
        }
    }
    
    /**
     * find trade's related order#, status, etc. from given text
     *
     * @param $notification array   notification from alipay
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
    private function parseTradeUpdateNotification(array $notification)
    {
        $trade = new \stdClass();
        $trade->status       = $notification['trade_status'];
        $trade->orderNo      = $notification['out_trade_no'];
        $trade->tradeNo      = $notification['trade_no'];
        $trade->fee          = $notification['total_fee'];
        $trade->creationTime = $notification['gmt_create'];
        $trade->paymentTime  = $notification['gmt_payment'];
        $trade->notifyTime   = $notification['notify_time'];
    
        return $trade;
    }
}
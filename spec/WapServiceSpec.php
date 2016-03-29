<?php
namespace spec\Homer\Payment\Alipay;

use PhpSpec\ObjectBehavior;
use GuzzleHttp\ClientInterface;
use Prophecy\Argument;
use GuzzleHttp\Psr7\Response;

class WapServiceSpec extends ObjectBehavior
{
    function let(ClientInterface $httpClient)
    {
        $config = [
            'partner'           => '2088701892019087',
            'cert_file'         => __DIR__ . '/data/rsa_private_key.pem',
            'ali_cert_file'     => __DIR__ . '/data/alipay_wap_cert.pem',
            'notify_url'        => 'http://localhost/trade.php',
            'seller'            => 'alipay@zero2all.com',
            'refund_notify_url' => 'http://localhost/refund.php',
            'secure_key'        => 'cwygc4fpvwevu45m2jnh43w54vir9eqw',
            'success_url'       => 'http://localhost/payment_success.php',
            'abort_url'         => 'http://localhost/payment_abort.php',
        ];

        $this->beAnInstanceOf(\Homer\Payment\Alipay\WapService::class, [$config, $httpClient]);
    }

    //================================
    //        prepareTrade
    //================================
    function it_prepares_trade_well(ClientInterface $httpClient)
    {
        $httpClient->request('POST', 'http://wappaygw.alipay.com/service/rest.htm', Argument::cetera())
            ->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/wap_authorize_token_success.txt')));

        $result = $this->prepareTrade('201304101204101002', 0.01)->getWrappedObject();
        assert_true(is_object($result));
        assert_equals('<auth_and_execute_req><request_token>20100830e8085e3e0868a466b822350ede5886e8</request_token></auth_and_execute_req>', $result->params['req_data']);
        assert_equals('alipay.wap.auth.authAndExecute', $result->params['service']);
        assert_not_empty($result->formAction);
    }

    function its_authorization_can_fail_when_preparing_trade(ClientInterface $httpClient)
    {
        $httpClient->request('POST', 'http://wappaygw.alipay.com/service/rest.htm', Argument::cetera())
            ->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/wap_authorize_token_failed.txt')));

        $this->shouldThrow(new \Exception('合作伙伴没有开通接口访问权限'))
            ->duringPrepareTrade('201304101204101002', 0.01);
    }

    //================================
    //        tradePaid
    //================================
    function it_will_receive_sync_notification(ClientInterface $httpClient)
    {
        parse_str(file_get_contents(__DIR__.'/data/wap_payment_success.txt'), $notification);

        $result = $this->tradePaid($notification)->getWrappedObject();
        assert_equals('2014021067095547', $result->tradeNo);
        assert_equals('00001_52f85fd9f97644135c8b4570', $result->orderNo);
        assert_not_empty($result->authorizeToken);
        assert_equals('SUCCESS', $result->status);
    }

    //================================
    //        tradeUpdated
    //================================
    function it_receives_trade_updated_async(ClientInterface $httpClient)
    {
        parse_str(file_get_contents(__DIR__.'/data/wap_trade_updated.txt'), $notification);

        $this->tradeUpdated($notification, function ($trade) {
            assert_equals('2014021004393977', $trade->tradeNo);
            assert_equals('00001_52f86166f976440e5c8b4572', $trade->orderNo);
            assert_equals('TRADE_SUCCESS', $trade->status);
            assert_equals(81.00, $trade->fee, '', 1E-6);
            assert_equals('2014-02-10 13:22:55', $trade->creationTime);
            assert_equals('2014-02-10 13:46:56', $trade->notifyTime);
            assert_equals('2014-02-10 13:22:56', $trade->paymentTime);
        });
    }

}
<?php
namespace spec\Homer\Payment\Alipay;

use PhpSpec\ObjectBehavior;
use GuzzleHttp\ClientInterface;
use Prophecy\Argument;
use GuzzleHttp\Psr7\Response;

class ServiceSpec extends ObjectBehavior
{
    function let(ClientInterface $client)
    {
        $config = [
            'partner'            => '2088701892019087',
            'cert_file'          => __DIR__ . '/data/rsa_private_key.pem',
            'ali_cert_file'      => __DIR__ . '/data/alipay_cert.pem',
            'seller'             => 'alipay@homer.com',
            'notify_url'         => 'http://localhost/trade.php',
            'refund_notify_url'  => 'http://localhost/refund.php',
            'secure_key'         => 'cwygc4fpvwevu45m2jnh43w54vir9eqw',
        ];

        $this->beAnInstanceOf(\Homer\Payment\Alipay\Service::class, [$config, $client]);
    }

    //================================
    //        prepareTrade
    //================================
    function it_prepares_trade_well()
    {
        $this->prepareTrade('201304101204101002', 0.01)
            ->shouldBe('_input_charset="utf-8"&body=" "&notify_url="http://localhost/trade.php"&' .
                'out_trade_no="201304101204101002"&partner="2088701892019087"&payment_type="1"&' .
                'seller_id="alipay@homer.com"&service="mobile.securitypay.pay"&subject=" "&' .
                'total_fee="0.01"&sign="FyJdOKzy21kyCu5aVxop67NzJRSFpBiBWqTwF0KfywgiS8dRV7EGF%2B' .
                'N0xV8s%2Ff55q%2FnRwB0ofmbmhOClW9w77aRmtjFa3fUjoLjXxgBo08Kz3sQjulc1pEkJuwKR5HilN' .
                '%2BC1vQBD2kkBLnAXWTpj0TjTXrkTDjpq%2Bd%2FlE2EuZCU%3D"&sign_type="RSA"');
    }

    function it_rejects_if_forged_update_detected()
    {
        parse_str(file_get_contents(__DIR__ . '/data/trade_finished_forged.txt'), $notification);
        // cannot pass the signature verification
        $this->shouldThrow(new \Exception('Forged trade notification'))
            ->duringTradeUpdated($notification, function() {
                // don't care since this method won't be called
                throw new \Exception('should never be reached');
            });
    }

    function it_rejcts_on_bad_signature()
    {
        parse_str(file_get_contents(__DIR__ . '/data/trade_finished_bad_sig.txt'), $notification);
        $this->shouldThrow(new \Exception('Signature verification failed'))
            ->duringTradeUpdated($notification, function () {
                // don't care since this method won't be called
                throw new \Exception('should never be reached');
            });
    }

    function it_accepts_valid_trade_update()
    {
        parse_str(file_get_contents(__DIR__ . '/data/trade_finished.txt'), $notification);

        ob_start(); // capture the echo-ed output
        $this->tradeUpdated($notification, function ($trade) {
            assert_equals('00001_52b01e7571b14fa7768b4569', $trade->orderNo);
            assert_equals('TRADE_SUCCESS', $trade->status);
            assert_equals('2013121763945981', $trade->tradeNo);
            assert_equals('0.01', $trade->fee);
            assert_equals('2013-12-17 17:50:57', $trade->creationTime);
            assert_equals('2013-12-17 17:50:58', $trade->paymentTime);
            assert_equals('2013-12-17 17:54:32', $trade->notifyTime);

            return true;
        });

        assert_equals('success', ob_get_clean());
    }

    //================================
    //        refundTrade
    //================================
    function it_can_successfully_refund(ClientInterface $client)
    {
        $client->request('POST', 'https://mapi.alipay.com/gateway.do', Argument::that(function ($param) {
            if (!array_key_exists('form_params', $param)) {
                return false;
            }

            $params = $param['form_params'];
            assert_equals('utf-8', $params['_input_charset']);
            assert_equals('refund_fastpay_by_platform_nopwd', $params['service']);
            assert_equals(1, $params['batch_num']);
            assert_equals('TRADE#^100^协商退款', $params['detail_data']);
            if (!array_key_exists('batch_no', $params)) {
                return false;
            }

            return true;
        }))->shouldBeCalledTimes(1)
           ->willReturn(new Response(200, [], file_get_contents(__DIR__.'/data/refund_trade_sync_success.xml')));

        $result = $this->refundTrade('TRADE#', 100)->getWrappedObject();
        assert_equals('REFUND_SUCCESS', $result->status);
        assert_empty($result->msg);
        assert_not_empty($result->seqNo);
    }

    function it_refunds_trade_with_sub_trade(ClientInterface $client)
    {
        $client->request('POST', 'https://mapi.alipay.com/gateway.do', Argument::that(function ($param) {
            if (!array_key_exists('form_params', $param)) {
                return false;
            }

            $params = $param['form_params'];
            assert_equals(1, $params['batch_num']);
            assert_equals('TRADE#^100^协商退款$$20^协商退款', $params['detail_data']);

            return true;
        }))->shouldBeCalledTimes(1)
            ->willReturn(new Response(200, [], file_get_contents(__DIR__.'/data/refund_trade_sync_success.xml')));

        $this->shouldNotThrow()->duringRefundTrade('TRADE#', 100, '协商退款', [
            'sub' => 20, // or 'sub' => [ 20 ], or 'sub' => [ 20, 'some reason' ]
        ]);
    }

    function it_refunds_trade_with_shared_profit(ClientInterface $client)
    {
        $client->request('POST', 'https://mapi.alipay.com/gateway.do', Argument::that(function ($param) {
            if (!array_key_exists('form_params', $param)) {
                return false;
            }

            $params = $param['form_params'];
            assert_equals(1, $params['batch_num']);
            assert_equals('TRADE#^100^协商退款|from^2088701892019087^to^2088701892019088^30^协商退款', $params['detail_data']);

            return true;
        }))->shouldBeCalledTimes(1)
            ->willReturn(new Response(200, [], file_get_contents(__DIR__.'/data/refund_trade_sync_success.xml')));

        $this->shouldNotThrow()->duringRefundTrade('TRADE#', 100, '协商退款', [
            'profit' => [
                ['from', '2088701892019087', 'to', '2088701892019088', 30]
            ]
        ]);
    }

    function it_refunds_trade_with_shared_profit_and_sub_trade(ClientInterface $client)
    {
        $client->request('POST', 'https://mapi.alipay.com/gateway.do', Argument::that(function ($param) {
            if (!array_key_exists('form_params', $param)) {
                return false;
            }

            $params = $param['form_params'];
            assert_equals(1, $params['batch_num']);
            assert_equals('TRADE#^100^协商退款|from^2088701892019087^to^2088701892019088^30^协商退款$$20^协商退款', $params['detail_data']);

            return true;
        }))->shouldBeCalledTimes(1)
            ->willReturn(new Response(200, [], file_get_contents(__DIR__.'/data/refund_trade_sync_success.xml')));

        $this->shouldNotThrow()->duringRefundTrade('TRADE#', 100, '协商退款', [
            'sub'    => 20,
            'profit' => [
                ['from', '2088701892019087', 'to', '2088701892019088', 30]
            ]
        ]);
    }

    function it_fails_to_refund_on_bad_batch_no(ClientInterface $client)
    {
        $client->request('POST', 'https://mapi.alipay.com/gateway.do', Argument::cetera())
            ->willReturn(new Response(200, [], file_get_contents(__DIR__.'/data/refund_trade_sync_failed.xml')));

        $result = $this->refundTrade('TRADE#', 100)->getWrappedObject();
        assert_equals('REFUND_FAILED', $result->status);
        assert_equals('BATCH_NO_FORMAT_ERROR', $result->msg);
    }

    //================================
    //        refundTradeUpdated
    //================================
    function it_can_encounter_failed_status_on_refunding_notification_details()
    {
        parse_str(file_get_contents(__DIR__ . '/data/refund_trade_async_finished.txt'), $notification);

        ob_start();   // capture the echo-ed output
        $this->refundTradeUpdated($notification, function ($trade) {
            assert_equals('20060702001', $trade->seqNo);
            assert_equals('2009-08-12 11:08:32', $trade->notifyTime);
            assert_count(1, $trade->details);

            $detail = $trade->details[0];
            assert_equals('2010031206252779', $detail->tradeNo);
            assert_equals(10.00, $detail->fee, '', 1E-6);
            assert_equals('NOT_THIS_PARTNERS_TRADE', $detail->status);

            return false;
        });

        assert_equals('fail', ob_get_clean());
    }

    function it_can_successfully_notified_on_funding()
    {
        parse_str(file_get_contents(__DIR__ . '/data/refund_trade_async_success.txt'), $notification);

        ob_start();   // capture the echo-ed output
        $this->refundTradeUpdated($notification, function ($trade) {
            assert_equals('20060702001', $trade->seqNo);
            assert_equals('2009-08-12 11:08:32', $trade->notifyTime);
            assert_count(1, $trade->details);

            $detail = $trade->details[0];
            assert_equals('2010031206252779', $detail->tradeNo);
            assert_equals(10.00, $detail->fee, '', 1E-6);
            assert_equals('REFUND_SUCCESS', $detail->status);  // SUCCESS should be converted to REFUND_SUCCESS

            return true;
        });
        assert_equals('success', ob_get_clean());
    }
}

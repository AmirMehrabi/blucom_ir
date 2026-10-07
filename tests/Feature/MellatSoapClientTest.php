<?php

namespace Tests\Feature;

use App\Exceptions\PaymentTransportException;
use App\Models\PaymentGatewayVersion;
use App\Services\Commerce\MellatSoapClient;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MellatSoapClientTest extends TestCase
{
    private function account(): PaymentGatewayVersion
    {
        return new PaymentGatewayVersion(['credentials' => ['terminal_id' => '123456', 'username' => 'synthetic <user>', 'password' => ' synthetic <&> password ']]);
    }

    private function fields(): array
    {
        return ['orderId' => 17, 'amount' => 2500000, 'localDate' => '20261007', 'localTime' => '090501',
            'additionalData' => '', 'callBackUrl' => 'https://my.blucom.ir/payments/mellat/callback/synthetic', 'payerId' => 0];
    }

    private function envelope(string $result, string $method = 'bpPayRequest'): string
    {
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><p:'.$method.'Response xmlns:p="http://interfaces.core.sw.bps.com/"><return>'.$result.'</return></p:'.$method.'Response></soap:Body></soap:Envelope>';
    }

    public function test_soap_request_escapes_credentials_and_enforces_tls_timeouts_and_fixed_origin(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $this->envelope('0,SyntheticCaseRef'))]));
        $stack->push(Middleware::history($history));
        $client = new MellatSoapClient(new Client(['handler' => $stack]));
        $this->assertSame('0,SyntheticCaseRef', $client->call($this->account(), 'bpPayRequest', $this->fields()));
        $request = $history[0]['request'];
        $this->assertSame(MellatSoapClient::ENDPOINT, (string) $request->getUri());
        $this->assertSame('POST', $request->getMethod());
        $this->assertTrue($history[0]['options']['verify']);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertSame(15, $history[0]['options']['timeout']);
        $this->assertSame(5, $history[0]['options']['connect_timeout']);
        $this->assertFalse($history[0]['options']['stream']);
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML((string) $request->getBody()));
        $xpath = new DOMXPath($document);
        $this->assertSame(' synthetic <&> password ', $xpath->evaluate('string(//*[local-name()="userPassword"])'));
        $this->assertSame('synthetic <user>', $xpath->evaluate('string(//*[local-name()="userName"])'));
        $this->assertSame('2500000', $xpath->evaluate('string(//*[local-name()="amount"])'));
    }

    public static function malformedResponses(): array
    {
        return [
            'html' => [200, '<html>Unavailable</html>'],
            'http error' => [500, 'synthetic sensitive payload'],
            'redirect' => [302, ''],
            'doctype' => [200, '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><x>&x;</x>'],
            'oversized' => [200, str_repeat('x', 33000)],
            'wrong namespace' => [200, '<Envelope><Body><bpPayRequestResponse><return>0,ref</return></bpPayRequestResponse></Body></Envelope>'],
            'malformed xml' => [200, '<broken>'],
            'soap fault' => [200, '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultstring>synthetic password</faultstring></s:Fault></s:Body></s:Envelope>'],
        ];
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_fault_redirect_and_sensitive_responses_fail_without_retaining_payload(int $status, string $body): void
    {
        $client = new MellatSoapClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status, [], $body)]))]));
        try {
            $client->call($this->account(), 'bpPayRequest', $this->fields());
            $this->fail('Invalid transport response accepted.');
        } catch (PaymentTransportException $exception) {
            $this->assertSame('Payment provider outcome is uncertain.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_arbitrary_operation_fields_cannot_override_merchant_credentials(): void
    {
        $client = new MellatSoapClient;
        $this->expectException(PaymentTransportException::class);
        $client->call($this->account(), 'bpPayRequest', [...$this->fields(), 'userPassword' => 'untrusted']);
    }

    public function test_response_sink_aborts_before_buffering_more_than_the_limit(): void
    {
        $buffer = null;
        $mock = new MockHandler([function ($request, array $options) use (&$buffer) {
            $buffer = $options['sink'];
            $buffer->write(str_repeat('x', 32000));
            $buffer->write(str_repeat('x', 1000));

            return new Response(200, [], 'unreachable');
        }]);
        $client = new MellatSoapClient(new Client(['handler' => HandlerStack::create($mock)]));
        try {
            $client->call($this->account(), 'bpPayRequest', $this->fields());
            $this->fail('Oversized response was accepted.');
        } catch (PaymentTransportException $exception) {
            $this->assertSame('Payment provider outcome is uncertain.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertSame(32000, $buffer->getSize());
        }
    }
}

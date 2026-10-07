<?php

namespace App\Services\Commerce;

use App\Contracts\MellatClient;
use App\Exceptions\PaymentTransportException;
use App\Models\PaymentGatewayVersion;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Throwable;

/** Bounded SOAP 1.1 transport; does not dispatch framework HTTP payload telemetry. */
class MellatSoapClient implements MellatClient
{
    public const ENDPOINT = 'https://bpm.shaparak.ir/pgwchannel/services/pgw';

    public const PAYMENT_URL = 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat';

    private const NS = 'http://interfaces.core.sw.bps.com/';

    public function __construct(private ?ClientInterface $http = null) {}

    public function call(#[\SensitiveParameter] PaymentGatewayVersion $account, string $method, array $fields): string
    {
        if (! in_array($method, ['bpPayRequest', 'bpVerifyRequest', 'bpSettleRequest', 'bpInquiryRequest', 'bpReversalRequest'], true)) {
            throw new PaymentTransportException('Unsupported payment operation.');
        }
        $allowed = $method === 'bpPayRequest'
            ? ['orderId', 'amount', 'localDate', 'localTime', 'additionalData', 'callBackUrl', 'payerId']
            : ['orderId', 'saleOrderId', 'saleReferenceId'];
        if (array_diff(array_keys($fields), $allowed) !== [] || array_diff($allowed, array_keys($fields)) !== []) {
            throw new PaymentTransportException('Invalid payment operation fields.');
        }
        try {
            $credentials = $account->credentials;
            $document = new DOMDocument('1.0', 'UTF-8');
            $envelope = $document->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soap:Envelope');
            $document->appendChild($envelope);
            $body = $document->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soap:Body');
            $envelope->appendChild($body);
            $operation = $document->createElementNS(self::NS, 'bps:'.$method);
            $body->appendChild($operation);
            foreach (['terminalId' => $credentials['terminal_id'], 'userName' => $credentials['username'], 'userPassword' => $credentials['password'], ...$fields] as $name => $value) {
                $element = $document->createElement($name);
                $element->appendChild($document->createTextNode((string) $value));
                $operation->appendChild($element);
            }
            // An explicit cURL handler applies timeout to the whole transfer. The default
            // streamed PHP handler only bounds each read, allowing a slow response to overrun a lease.
            $buffer = Utils::streamFor(fopen('php://temp', 'w+'));
            $received = 0;
            $sink = FnStream::decorate($buffer, ['write' => function (string $chunk) use ($buffer, &$received): int {
                if ($received + strlen($chunk) > 32768) {
                    throw new PaymentTransportException('Payment provider response exceeds the limit.');
                }
                $written = $buffer->write($chunk);
                $received += $written;

                return $written;
            }]);
            $http = $this->http ??= new Client(['handler' => HandlerStack::create(new CurlHandler)]);
            $response = $http->request('POST', self::ENDPOINT, [
                'headers' => ['Content-Type' => 'text/xml; charset=UTF-8', 'SOAPAction' => '""'],
                'body' => $document->saveXML(), 'verify' => true, 'allow_redirects' => false,
                'connect_timeout' => 5, 'timeout' => 15, 'http_errors' => false, 'stream' => false, 'sink' => $sink,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new PaymentTransportException('Payment provider unavailable.');
            }
            $response->getBody()->rewind();
            $xml = '';
            while (! $response->getBody()->eof() && strlen($xml) <= 32768) {
                $chunk = $response->getBody()->read(4096);
                if ($chunk === '' && ! $response->getBody()->eof()) {
                    throw new PaymentTransportException('Incomplete payment provider response.');
                }
                $xml .= $chunk;
            }
            if (strlen($xml) > 32768 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
                throw new PaymentTransportException('Invalid payment provider response.');
            }
            $previous = libxml_use_internal_errors(true);
            try {
                $parsed = new DOMDocument;
                if (! $parsed->loadXML($xml, LIBXML_NONET)) {
                    throw new PaymentTransportException('Invalid payment provider XML.');
                }
                $xpath = new DOMXPath($parsed);
                $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
                $xpath->registerNamespace('bps', self::NS);
                $values = $xpath->query('/soap:Envelope/soap:Body/bps:'.$method.'Response/*[local-name()="return"]');
                if ($xpath->query('/soap:Envelope/soap:Body')->length !== 1
                    || $xpath->query('/soap:Envelope/soap:Body/soap:Fault')->length !== 0
                    || $values->length !== 1 || $values->item(0)->childElementCount > 0) {
                    throw new PaymentTransportException('Invalid payment provider envelope.');
                }
                $result = trim($values->item(0)->textContent);
                if (! preg_match('/^[0-9]{1,4}(?:,[A-Za-z0-9]{1,100})?$/D', $result)) {
                    throw new PaymentTransportException('Invalid payment provider result.');
                }

                return $result;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        } catch (Throwable) {
            // Never retain a cause or transport payload: it can contain merchant credentials.
            throw new PaymentTransportException('Payment provider outcome is uncertain.');
        }
    }
}

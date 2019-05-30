<?php declare(strict_types=1);

namespace NoGlitchYo\DoDoh\Client;

use GuzzleHttp\Psr7\Request;
use Http\Client\HttpClient;
use NoGlitchYo\DoDoh\DnsUpstream;
use NoGlitchYo\DoDoh\Factory\DnsMessageFactoryInterface;
use NoGlitchYo\DoDoh\Message\DnsMessageInterface;

class DohClient implements DnsClientInterface
{
    /** @var HttpClient */
    private $client;

    /** @var DnsMessageFactoryInterface */
    private $dnsMessageFactory;

    public function __construct(HttpClient $client, DnsMessageFactoryInterface $dnsMessageFactory)
    {
        $this->client = $client;
        $this->dnsMessageFactory = $dnsMessageFactory;
    }

    public function resolve(DnsUpstream $dnsUpstream, DnsMessageInterface $dnsRequestMessage): DnsMessageInterface
    {
        $dnsMessage = $this->dnsMessageFactory->createDnsWireMessageFromMessage($dnsRequestMessage);

        // TODO: should follow recommendations from https://tools.ietf.org/html/rfc8484#section-5.1 about cache
        $request = new Request(
            'POST',
            $dnsUpstream->getUri(),
            [
                'Content-Type' => 'application/dns-message',
                'Content-Length' => strlen($dnsMessage)
            ],
            $dnsMessage
        );

        $response = $this->client->sendRequest($request);

        return $this->dnsMessageFactory->createMessageFromDnsWireMessage((string) $response->getBody());
    }

    public function supports(DnsUpstream $dnsUpstream): bool
    {
        return strpos(strtolower($dnsUpstream->getScheme() ?? ''), 'https') !== false;
    }
}

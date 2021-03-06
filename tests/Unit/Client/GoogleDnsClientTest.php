<?php declare(strict_types=1);

namespace NoGlitchYo\Dealdoh\Tests\Unit\Client;

use Exception;
use Hamcrest\Core\IsEqual;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use NoGlitchYo\Dealdoh\Client\GoogleDnsClient;
use NoGlitchYo\Dealdoh\Entity\Dns\Message;
use NoGlitchYo\Dealdoh\Entity\Dns\Message\Section\Query;
use NoGlitchYo\Dealdoh\Entity\Dns\Message\Section\ResourceRecordInterface;
use NoGlitchYo\Dealdoh\Entity\DnsUpstream;
use NoGlitchYo\Dealdoh\Exception\DnsClientException;
use NoGlitchYo\Dealdoh\Mapper\GoogleDns\MessageMapper;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

class GoogleDnsClientTest extends TestCase
{
    private const UPSTREAM_ADDR = 'https://dns.google.com/resolve';

    use MockeryPHPUnitIntegration;

    /** @var MessageMapper|MockInterface */
    private $messageMapperMock;

    /** @var ClientInterface|MockInterface */
    private $clientMock;

    /** @var GoogleDnsClient */
    private $sut;

    protected function setUp(): void
    {
        $this->clientMock = Mockery::mock(ClientInterface::class);
        $this->messageMapperMock = Mockery::mock(MessageMapper::class);

        $this->sut = new GoogleDnsClient($this->clientMock, $this->messageMapperMock);
    }

    public function testResolveCheckNameLengthAndThrowExceptionIfTooShort(): void
    {
        $dnsUpstream = new DnsUpstream(static::UPSTREAM_ADDR);
        $dnsRequestMessage = (Message::createWithDefaultHeader())
            ->addQuestion(new Query('', ResourceRecordInterface::TYPE_A, ResourceRecordInterface::CLASS_IN));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query name length must be between 1 and 253');

        $this->sut->resolve($dnsUpstream, $dnsRequestMessage);
    }

    public function testResolveCheckNameLengthAndThrowExceptionIfTooLong(): void
    {
        $dnsUpstream = new DnsUpstream(static::UPSTREAM_ADDR);
        $dnsRequestMessage = Message::createWithDefaultHeader();

        $qname = str_repeat('a', 254);
        $query = new Query($qname, ResourceRecordInterface::TYPE_A, ResourceRecordInterface::CLASS_IN);
        $dnsRequestMessage->addQuestion($query);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query name length must be between 1 and 253');

        $this->sut->resolve($dnsUpstream, $dnsRequestMessage);
    }

    public function testResolveCheckQueryTypeAndThrowExceptionIfTooSmall(): void
    {
        $dnsUpstream = new DnsUpstream(static::UPSTREAM_ADDR);
        $dnsRequestMessage = (Message::createWithDefaultHeader())
            ->addQuestion(new Query('domain.com', 65636, ResourceRecordInterface::CLASS_IN));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query type must be in range [1, 65535]');

        $this->sut->resolve($dnsUpstream, $dnsRequestMessage);
    }

    public function testResolveCheckQueryTypeAndThrowExceptionIfTooBig(): void
    {
        $dnsUpstream = new DnsUpstream(static::UPSTREAM_ADDR);
        $dnsRequestMessage = Message::createWithDefaultHeader();

        $dnsRequestMessage->addQuestion(new Query('domain.com', 0, ResourceRecordInterface::CLASS_IN));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query type must be in range [1, 65535]');

        $this->sut->resolve($dnsUpstream, $dnsRequestMessage);
    }

    public function testResolveSendGetRequestAndReturnDnsResponse(): void
    {
        list($qname, $qtype) = ['domain.com', ResourceRecordInterface::TYPE_AAAA];
        $dnsUpstream = new DnsUpstream(static::UPSTREAM_ADDR);
        $httpRequest = new Request('GET', sprintf(static::UPSTREAM_ADDR . '?name=%s&type=%s', $qname, $qtype));
        $httpResponse = (new Response(200, [], '{}'));
        $dnsRequestMessage = (Message::createWithDefaultHeader())
            ->addQuestion(new Query($qname, $qtype, ResourceRecordInterface::CLASS_IN));
        $expectedDnsResponse = Message::createWithDefaultHeader(true);

        $this->clientMock->shouldReceive('sendRequest')
            ->with(IsEqual::equalTo($httpRequest))
            ->andReturn($httpResponse);

        $this->messageMapperMock->shouldReceive('map')
            ->with([])
            ->andReturn($expectedDnsResponse);

        $this->assertEquals($expectedDnsResponse, $this->sut->resolve($dnsUpstream, $dnsRequestMessage));
    }

    public function testResolveThrowDnsClientExceptionWhenSendingRequestFailed(): void
    {
        list($qname, $qtype) = ['domain.com', ResourceRecordInterface::TYPE_AAAA];
        $dnsUpstream = new DnsUpstream(static::UPSTREAM_ADDR);
        $httpRequest = new Request('GET', sprintf(static::UPSTREAM_ADDR . '?name=%s&type=%s', $qname, $qtype));
        $httpResponse = (new Response(200, [], '{}'));
        $dnsRequestMessage = (Message::createWithDefaultHeader())
            ->addQuestion(new Query($qname, $qtype, ResourceRecordInterface::CLASS_IN));

        $this->clientMock->shouldReceive('sendRequest')
            ->with(IsEqual::equalTo($httpRequest))
            ->andThrow(Exception::class);

        $this->expectException(DnsClientException::class);
        $this->expectExceptionMessage('Failed to send the request to Google DNS API');

        $this->sut->resolve($dnsUpstream, $dnsRequestMessage);
    }

    public function testResolveThrowDnsClientExceptionWhenMappingFailed(): void
    {
        list($qname, $qtype) = ['domain.com', ResourceRecordInterface::TYPE_AAAA];
        $dnsUpstream = new DnsUpstream(static::UPSTREAM_ADDR);
        $httpRequest = new Request('GET', sprintf(static::UPSTREAM_ADDR . '?name=%s&type=%s', $qname, $qtype));
        $httpResponse = (new Response(200, [], '{}'));
        $dnsRequestMessage = (Message::createWithDefaultHeader())
            ->addQuestion(new Query($qname, $qtype, ResourceRecordInterface::CLASS_IN));

        $this->clientMock->shouldReceive('sendRequest')
            ->with(IsEqual::equalTo($httpRequest))
            ->andReturn($httpResponse);

        $this->messageMapperMock->shouldReceive('map')
            ->with([])
            ->andThrow(Exception::class);

        $this->expectException(DnsClientException::class);
        $this->expectExceptionMessage('Failed to map the response from Google DNS API');

        $this->sut->resolve($dnsUpstream, $dnsRequestMessage);
    }

    public function testSupportsOnlyAcceptGoogleDnsApiUpstream(): void
    {
        $dnsUpstream = new DnsUpstream('udp://notgoogle.com:53');
        $this->assertFalse($this->sut->supports($dnsUpstream));

        $dnsUpstream = new DnsUpstream(static::UPSTREAM_ADDR);
        $this->assertTrue($this->sut->supports($dnsUpstream));
    }
}

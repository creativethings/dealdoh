<?php declare(strict_types=1);

namespace NoGlitchYo\Dealdoh\Tests\Unit\Client;

use Mockery;
use Mockery\MockInterface;
use NoGlitchYo\Dealdoh\Client\StdClient;
use NoGlitchYo\Dealdoh\Entity\Dns\Message;
use NoGlitchYo\Dealdoh\Entity\Dns\Message\Header;
use NoGlitchYo\Dealdoh\Entity\Dns\Message\HeaderInterface;
use NoGlitchYo\Dealdoh\Entity\DnsUpstream;
use NoGlitchYo\Dealdoh\Factory\Dns\MessageFactoryInterface;
use PHPUnit\Framework\TestCase;
use Socket\Raw\Factory;
use Socket\Raw\Socket;
use const MSG_WAITALL;
use const SO_RCVTIMEO;
use const SOL_SOCKET;

class StdClientTest extends TestCase
{
    /**
     * @var MockInterface|MessageFactoryInterface
     */
    private $dnsMessageFactoryMock;

    /**
     * @var MockInterface|Factory
     */
    private $socketFactoryMock;

    /**
     * @var StdClient
     */
    private $sut;

    protected function setUp(): void
    {
        $this->socketFactoryMock = Mockery::mock(Factory::class);
        $this->dnsMessageFactoryMock = Mockery::mock(MessageFactoryInterface::class);

        $this->sut = new StdClient($this->socketFactoryMock, $this->dnsMessageFactoryMock);

        parent::setUp();
    }

    public function testResolveCreateAndReturnDnsMessage()
    {
        $dnsUpstreamAddr = '8.8.8.8:53';
        $dnsUpstream = new DnsUpstream($dnsUpstreamAddr);
        $dnsRequestMessage = new Message(
            new Header(0, false, 0, false, false, true, false, 0, HeaderInterface::RCODE_OK)
        );

        $socketMock = Mockery::mock(Socket::class);
        $dnsWireRequestMessage = 'somebytesindnswireformat';
        $dnsWireResponseMessage = 'somemorebytesindnswireformat';
        $dnsResponseMessage = new Message(
            new Header(0, true, 0, false, false, false, false, 0, HeaderInterface::RCODE_OK)
        );

        $this->socketFactoryMock->shouldReceive('createClient')
            ->with('udp://' . $dnsUpstreamAddr)
            ->andReturn($socketMock);

        $this->dnsMessageFactoryMock->shouldReceive('createDnsWireMessageFromMessage')
            ->with($dnsRequestMessage)
            ->andReturn($dnsWireRequestMessage);

        $socketMock->shouldReceive('sendTo')
            ->with($dnsWireRequestMessage, 0, $dnsUpstreamAddr);

        $socketMock->shouldReceive('recvFrom')
            ->with(4096, MSG_WAITALL, $dnsUpstreamAddr)
            ->andReturn($dnsWireResponseMessage);

        $socketMock->shouldReceive('setOption')
            ->with(SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

        $this->dnsMessageFactoryMock->shouldReceive('createMessageFromDnsWireMessage')
            ->with($dnsWireResponseMessage)
            ->andReturn($dnsResponseMessage);

        $this->assertEquals($dnsResponseMessage, $this->sut->resolve($dnsUpstream, $dnsRequestMessage));
    }

    public function testSupportsAcceptUdpUpstream()
    {
        $dnsUpstreamAddr = 'udp://8.8.8.8:53';
        $dnsUpstream = new DnsUpstream($dnsUpstreamAddr);

        $this->assertTrue($this->sut->supports($dnsUpstream));
    }

    public function testSupportsAcceptUpstreamWithoutScheme()
    {
        $dnsUpstreamAddr = '8.8.8.8:53';
        $dnsUpstream = new DnsUpstream($dnsUpstreamAddr);

        $this->assertTrue($this->sut->supports($dnsUpstream));
    }

    public function testSupportsDeclineUpstreamWithScheme()
    {
        $dnsUpstreamAddr = 'http://8.8.8.8:53';
        $dnsUpstream = new DnsUpstream($dnsUpstreamAddr);

        $this->assertFalse($this->sut->supports($dnsUpstream));
    }
}

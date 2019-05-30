<?php declare(strict_types=1);

namespace NoGlitchYo\DoDoh;

use NoGlitchYo\DoDoh\Message\DnsMessageInterface;

interface DnsResolverInterface
{
    public function resolve(DnsMessageInterface $dnsMessage): DnsMessageInterface;
}
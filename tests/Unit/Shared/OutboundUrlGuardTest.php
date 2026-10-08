<?php

namespace Tests\Unit\Shared;

use App\Support\OutboundUrlGuard;
use Tests\TestCase;

/**
 * فحص: حارس الطلبات الخارجية (SSRF) يرفض العناوين الداخلية/الخاصة.
 */
class OutboundUrlGuardTest extends TestCase
{
    public function test_blocks_internal_and_private_targets(): void
    {
        foreach ([
            'http://169.254.169.254/latest/meta-data/',
            'http://localhost/x',
            'http://127.0.0.1/',
            'http://10.0.0.5/',
            'http://192.168.1.10/',
            'http://[::1]/',
            'http://service.internal/',
            'http://host.local/',
            'ftp://aqdi.sa/',
            'file:///etc/passwd',
            'not a url',
        ] as $url) {
            $this->assertFalse(OutboundUrlGuard::isPubliclyFetchable($url), $url);
        }
    }

    public function test_allows_public_https_hosts(): void
    {
        $this->assertTrue(OutboundUrlGuard::isPubliclyFetchable('https://aqdi.sa/'));
        $this->assertTrue(OutboundUrlGuard::isPubliclyFetchable('https://blogs.aqdi.sa/sitemap.xml'));
    }
}

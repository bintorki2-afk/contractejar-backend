<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\HtmlPageParser;
use Tests\TestCase;

class HtmlPageParserTest extends TestCase
{
    public function test_extracts_meta_headings_images_and_robots(): void
    {
        $html = <<<'HTML'
        <html>
          <head>
            <title>  عقدي  </title>
            <meta name="description" content="منصة توثيق">
            <meta name="robots" content="noindex, follow">
            <link rel="canonical" href="/about-us">
          </head>
          <body>
            <h1>مرحبا</h1>
            <img src="/a.png" alt="شعار">
            <img src="/b.png" alt="">
            <img src="/c.png">
            <a href="/commercial">تجاري</a>
            <a href="https://aqdi.sa/residential/">سكني</a>
            <a href="mailto:hi@aqdi.sa">mail</a>
            <a href="#top">skip</a>
          </body>
        </html>
        HTML;

        $parsed = (new HtmlPageParser)->parse($html, 'https://aqdi.sa/');

        $this->assertSame('عقدي', $parsed['title']);
        $this->assertSame('منصة توثيق', $parsed['description']);
        $this->assertSame(['مرحبا'], $parsed['h1s']);
        $this->assertSame(3, $parsed['image_count']);
        $this->assertSame(2, $parsed['images_missing_alt']);
        $this->assertTrue($parsed['noindex']);
        $this->assertSame('https://aqdi.sa/about-us', $parsed['canonical']);
        $this->assertContains('https://aqdi.sa/commercial', $parsed['hrefs']);
        $this->assertContains('https://aqdi.sa/residential/', $parsed['hrefs']);
        $this->assertNotContains('mailto:hi@aqdi.sa', $parsed['hrefs']);
    }

    public function test_resolve_url_handles_relative_and_parent_paths(): void
    {
        $parser = new HtmlPageParser;

        $this->assertSame(
            'https://aqdi.sa/blog/deed',
            $parser->resolveUrl('https://aqdi.sa/blog/old-guide', 'deed')
        );
        $this->assertSame(
            'https://aqdi.sa/pricing',
            $parser->resolveUrl('https://aqdi.sa/blog/old-guide', '/pricing')
        );
        $this->assertSame(
            'https://aqdi.sa/',
            $parser->resolveUrl('https://aqdi.sa/blog/old-guide', '../')
        );
        $this->assertNull($parser->resolveUrl('https://aqdi.sa/', 'javascript:void(0)'));
        $this->assertNull($parser->resolveUrl('https://aqdi.sa/', '#section'));
    }
}

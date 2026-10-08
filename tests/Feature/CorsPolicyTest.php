<?php

namespace Tests\Feature;

use Tests\TestCase;

/** فحص (CORS): نطاقات المنتج مسموحة، والنطاقات القديمة/الغريبة و localhost (خارج local) مرفوضة. */
class CorsPolicyTest extends TestCase
{
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v2/pricing', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
    }

    public function test_product_origins_allowed_and_others_denied(): void
    {
        foreach (['https://contractejar.com', 'https://www.contractejar.com', 'https://blogs.aqdi.sa'] as $ok) {
            $this->assertSame($ok, $this->preflight($ok)->headers->get('Access-Control-Allow-Origin'), $ok);
        }

        foreach ([
            'https://evil.example',
            'https://mosabnaim-aqdi-new-dashboard-main.vercel.app',
            'https://aqdi-new-frontend-main.vercel.app',
            'http://localhost:4444', // ليس APP_ENV=local
            'http://blogs.aqdi.sa',  // غير مشفّر
        ] as $denied) {
            $this->assertNull($this->preflight($denied)->headers->get('Access-Control-Allow-Origin'), $denied);
        }
    }
}

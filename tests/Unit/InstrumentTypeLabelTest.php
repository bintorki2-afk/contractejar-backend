<?php

namespace Tests\Unit;

use App\Models\Contract;
use Tests\TestCase;

class InstrumentTypeLabelTest extends TestCase
{
    public function test_admin_order_details_use_arabic_instrument_type_label(): void
    {
        $this->assertSame('صك ملكية إلكتروني من وزارة العدل', Contract::instrumentTypeLabel('electronic', 'ar'));
        $this->assertSame('صك ملكية إلكتروني من وزارة العدل', Contract::instrumentTypeLabel('electronic_deed', 'ar'));
        $this->assertSame('تجديد عقد إيجار', Contract::instrumentTypeLabel('lease_renewal', 'ar'));
        $this->assertSame('Electronic deed', Contract::instrumentTypeLabel('electronic', 'en'));
    }
}

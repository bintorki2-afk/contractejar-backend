<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (و) 2026-10-11 — قرارات المالك 2026-10-10:
 *  - D3: تفعيل مدد 3 و6 أشهر (ربع سنوي / نصف سنوي) للسكني والتجاري.
 *  - D6: سلة محذوفات العقارات والوحدات (trashed_at — 30 يوماً).
 *  - D7: جدول تقييمات العملاء + إعدادات الملخص (المتوسط/العدد/الإظهار) — بذرة من تقييمات الموقع الحالية.
 *  - D8: ساعات العمل الافتراضية.
 *  - D9: «الدفع بعد مشاهدة المسودة» (إعداد + مرفق مسودة العقد على الطلب).
 */
return new class extends Migration
{
    public const WORKING_HOURS = 'يومياً من 12 ظهراً حتى 12 منتصف الليل، والجمعة من 3 عصراً حتى 12 منتصف الليل';

    public function up(): void
    {
        // ── D9 + D7 + D8: الإعدادات ──
        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                if (! Schema::hasColumn('settings', 'pay_after_draft_enabled')) {
                    $table->boolean('pay_after_draft_enabled')->default(false);
                }
                if (! Schema::hasColumn('settings', 'reviews_enabled')) {
                    $table->boolean('reviews_enabled')->default(true);
                }
                if (! Schema::hasColumn('settings', 'reviews_average')) {
                    $table->decimal('reviews_average', 3, 2)->default(4.7);
                }
                if (! Schema::hasColumn('settings', 'reviews_count')) {
                    $table->unsignedInteger('reviews_count')->default(3000);
                }
            });
            DB::table('settings')->where(fn ($q) => $q->whereNull('working_hours')->orWhere('working_hours', ''))
                ->update(['working_hours' => self::WORKING_HOURS]);
        }

        // ── D7: التقييمات ──
        if (! Schema::hasTable('customer_reviews')) {
            Schema::create('customer_reviews', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('city', 120)->nullable();
                $table->text('text');
                $table->unsignedTinyInteger('rating')->default(5);
                $table->string('contract_type', 20)->nullable();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->boolean('is_visible')->default(true)->index();
                $table->timestamps();
            });
        }
        if (DB::table('customer_reviews')->count() === 0) {
            $path = database_path('data/customer_reviews_seed.json');
            $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
            $now = now();
            foreach (array_chunk(is_array($rows) ? $rows : [], 50) as $chunk) {
                DB::table('customer_reviews')->insert(array_map(static fn (array $r) => [
                    'name' => mb_substr((string) $r['name'], 0, 120),
                    'city' => $r['city'] ?? null,
                    'text' => (string) $r['text'],
                    'rating' => max(1, min(5, (int) ($r['rating'] ?? 5))),
                    'contract_type' => $r['contract_type'] ?? null,
                    'sort_order' => (int) ($r['sort_order'] ?? 0),
                    'is_visible' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        }

        // ── D6: سلة العقارات والوحدات ──
        foreach (['real_estates', 'real_units'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'trashed_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->timestamp('trashed_at')->nullable()->index();
                });
            }
        }

        // ── D9: مسودة العقد (مرفق) + اختيار العميل ──
        if (Schema::hasTable('contracts')) {
            Schema::table('contracts', function (Blueprint $table) {
                if (! Schema::hasColumn('contracts', 'draft_document_path')) {
                    $table->string('draft_document_path')->nullable();
                    $table->string('draft_document_name')->nullable();
                    $table->string('draft_document_mime', 100)->nullable();
                    $table->string('draft_document_note', 500)->nullable();
                    $table->timestamp('draft_document_uploaded_at')->nullable();
                    $table->unsignedBigInteger('draft_document_uploaded_by')->nullable();
                }
                if (! Schema::hasColumn('contracts', 'pay_after_draft')) {
                    $table->boolean('pay_after_draft')->default(false);
                    $table->timestamp('pay_after_draft_requested_at')->nullable();
                }
            });
        }

        // ── D3: مدد 3 و6 أشهر ──
        if (Schema::hasTable('contract_periods') && Schema::hasColumn('contract_periods', 'months')) {
            $hasActive = Schema::hasColumn('contract_periods', 'is_active');
            foreach (['housing', 'commercial'] as $type) {
                foreach ([['ربع سنوي', 3, 'عقد لمدة 3 أشهر', 'Three-month contract'], ['نصف سنوي', 6, 'عقد لمدة 6 أشهر', 'Six-month contract']] as [$label, $months, $noteAr, $noteEn]) {
                    $existing = DB::table('contract_periods')->where('contract_type', $type)
                        ->where(fn ($q) => $q->where('months', $months)->orWhere('period', $label))
                        ->orderBy('id')->first();
                    if ($existing) {
                        DB::table('contract_periods')->where('id', $existing->id)->update(array_merge(
                            ['months' => $months, 'updated_at' => now()],
                            $hasActive ? ['is_active' => true] : [],
                        ));
                    } else {
                        DB::table('contract_periods')->insert(array_merge([
                            'period' => $label,
                            'months' => $months,
                            'note_ar' => $noteAr,
                            'note_en' => $noteEn,
                            'contract_type' => $type,
                            'price' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ], $hasActive ? ['is_active' => true] : []));
                    }
                }
            }
        }

        try {
            \App\Support\PublicCache::flush();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_reviews');
        foreach (['real_estates', 'real_units'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'trashed_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('trashed_at'));
            }
        }
        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                foreach (['pay_after_draft_enabled', 'reviews_enabled', 'reviews_average', 'reviews_count'] as $c) {
                    if (Schema::hasColumn('settings', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('contracts')) {
            Schema::table('contracts', function (Blueprint $table) {
                foreach (['draft_document_path', 'draft_document_name', 'draft_document_mime', 'draft_document_note', 'draft_document_uploaded_at', 'draft_document_uploaded_by', 'pay_after_draft', 'pay_after_draft_requested_at'] as $c) {
                    if (Schema::hasColumn('contracts', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};

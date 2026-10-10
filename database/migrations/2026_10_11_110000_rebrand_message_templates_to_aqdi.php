<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * البند 2 (2026-10-11): تغيير اسم المنصة من «عقد إيجار» إلى «عقدي» في قوالب الرسائل المحفوظة
 * (واتساب/SMS/الإشعارات). يغيّر اسم العلامة فقط: «عقد إيجار» بين علامتي تنصيص، وبداية الرسالة
 * «عقد إيجار:». لا يلمس العبارات العامة مثل «توثيق عقد إيجار سكني».
 */
return new class extends Migration
{
    private const PAIRS = [
        ['«عقد إيجار»', '«عقدي»'],
    ];

    public function up(): void
    {
        $this->apply(false);
    }

    public function down(): void
    {
        $this->apply(true);
    }

    private function apply(bool $revert): void
    {
        if (! Schema::hasTable('message_templates')) {
            return;
        }

        $columns = array_values(array_filter(['title', 'body'], fn ($c) => Schema::hasColumn('message_templates', $c)));

        DB::table('message_templates')->orderBy('id')->each(function ($row) use ($columns, $revert) {
            $changes = [];
            foreach ($columns as $column) {
                $value = (string) ($row->{$column} ?? '');
                if ($value === '') {
                    continue;
                }
                $new = $value;
                foreach (self::PAIRS as [$from, $to]) {
                    $new = $revert ? str_replace($to, $from, $new) : str_replace($from, $to, $new);
                }
                $new = $revert
                    ? preg_replace('/^عقدي:/u', 'عقد إيجار:', $new)
                    : preg_replace('/^عقد إيجار:/u', 'عقدي:', $new);
                if ($new !== $value) {
                    $changes[$column] = $new;
                }
            }
            if ($changes !== []) {
                DB::table('message_templates')->where('id', $row->id)->update($changes);
            }
        });
    }
};

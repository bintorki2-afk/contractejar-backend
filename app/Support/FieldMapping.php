<?php

namespace App\Support;

/**
 * خريطة حقول العميل (دفعة د — ب24): مُدخل الموقع/التطبيق → عمود قاعدة البيانات → مفتاح تفاصيل الطلب في اللوحة
 * → مفتاح العميل. المصدر الوحيد لـ docs/field-mapping.md (php artisan docs:field-mapping) ولاختبار الانعكاس.
 *
 * `admin` / `customer` مسارات data_get داخل `data` لـ GET /api/admin/orders/{id} و GET /api/v2/contracts/{id}.
 * `customer: null` = لا يظهر في تفاصيل الطلب للعميل (يظهر فقط في رد الخطوة نفسها عند الاستكمال بنفس اسم المُدخل).
 */
final class FieldMapping
{
    /** @var list<array{step: string, input: string, label: string, column: string, admin: string, customer: string|null, fixture: mixed, expected: mixed}> */
    public const CONTRACT = [
        // ── الخطوة 0: بدء الطلب ──
        ['step' => 'start', 'input' => 'contract_type', 'label' => 'نوع العقد', 'column' => 'contracts.contract_type', 'admin' => 'contract_type_key', 'customer' => 'contract_type', 'fixture' => 'housing', 'expected' => 'housing'],
        ['step' => 'start', 'input' => 'instrument_type', 'label' => 'نوع الصك', 'column' => 'contracts.instrument_type', 'admin' => 'instrument_type_key', 'customer' => 'instrument_type', 'fixture' => 'electronic', 'expected' => 'electronic'],

        // ── الخطوة 1: الصك والعقار ──
        ['step' => 'step1', 'input' => 'instrument_number', 'label' => 'رقم الصك', 'column' => 'contracts.instrument_number', 'admin' => 'instrument_number', 'customer' => null, 'fixture' => '440123456789', 'expected' => '440123456789'],
        ['step' => 'step1', 'input' => 'instrument_history (+_day/_month/_year)', 'label' => 'تاريخ الصك', 'column' => 'contracts.instrument_history', 'admin' => 'instrument_history', 'customer' => null, 'fixture' => '10-05-1440', 'expected' => '1440-05-10'],
        ['step' => 'step1', 'input' => 'type_instrument_history', 'label' => 'نوع تاريخ الصك', 'column' => 'contracts.type_instrument_history', 'admin' => 'type_instrument_history', 'customer' => null, 'fixture' => 'hijri', 'expected' => 'hijri'],
        ['step' => 'step1', 'input' => 'real_estate_registry_number', 'label' => 'رقم السجل العقاري', 'column' => 'contracts.real_estate_registry_number', 'admin' => 'real_estate_registry_number', 'customer' => null, 'fixture' => 'R-1', 'expected' => 'R-1'],
        ['step' => 'step1', 'input' => 'property_type_id', 'label' => 'نوع العقار', 'column' => 'contracts.property_type_id', 'admin' => 'property_type_id', 'customer' => null, 'fixture' => '@property_type_id', 'expected' => '@property_type_id'],
        ['step' => 'step1', 'input' => 'property_usages_id', 'label' => 'استخدام العقار', 'column' => 'contracts.property_usages_id', 'admin' => 'property_usages_id', 'customer' => null, 'fixture' => '@property_usage_id', 'expected' => '@property_usage_id'],
        ['step' => 'step1', 'input' => 'number_of_floors', 'label' => 'عدد الأدوار', 'column' => 'contracts.number_of_floors', 'admin' => 'number_of_floors', 'customer' => null, 'fixture' => 2, 'expected' => '2'],
        ['step' => 'step1', 'input' => 'number_of_units_in_realestate', 'label' => 'عدد الوحدات', 'column' => 'contracts.number_of_units_in_realestate', 'admin' => 'number_of_units_in_realestate', 'customer' => 'number_of_units_in_realestate', 'fixture' => '4', 'expected' => '4'],
        ['step' => 'step1', 'input' => 'age_of_the_property', 'label' => 'عمر العقار', 'column' => 'contracts.age_of_the_property', 'admin' => 'age_of_the_property', 'customer' => 'age_of_the_property', 'fixture' => 5, 'expected' => 5],
        ['step' => 'step1', 'input' => 'image_instrument / image_instrument_pages[] / image_instrument_from_the_front / _back (ملفات)', 'label' => 'صورة الصك', 'column' => 'contracts.image_instrument (+_pages json)', 'admin' => 'image_instrument (رابط موقّع مؤقت) + image_instrument_pages[]', 'customer' => 'image_instrument', 'fixture' => null, 'expected' => null],

        // ── الخطوة 2: العنوان ──
        ['step' => 'step2', 'input' => 'property_place_id', 'label' => 'المنطقة', 'column' => 'contracts.property_place_id', 'admin' => 'property_place_id', 'customer' => null, 'fixture' => '@region_id', 'expected' => '@region_id'],
        ['step' => 'step2', 'input' => 'property_city_id', 'label' => 'المدينة', 'column' => 'contracts.property_city_id', 'admin' => 'property_city_id', 'customer' => null, 'fixture' => '@city_id', 'expected' => '@city_id'],
        ['step' => 'step2', 'input' => 'neighborhood', 'label' => 'الحي', 'column' => 'contracts.neighborhood', 'admin' => 'neighborhood', 'customer' => null, 'fixture' => 'النرجس', 'expected' => 'النرجس'],
        ['step' => 'step2', 'input' => 'street', 'label' => 'الشارع', 'column' => 'contracts.street', 'admin' => 'street', 'customer' => null, 'fixture' => 'الأمير', 'expected' => 'الأمير'],
        ['step' => 'step2', 'input' => 'building_number', 'label' => 'رقم المبنى', 'column' => 'contracts.building_number', 'admin' => 'building_number', 'customer' => null, 'fixture' => '1234', 'expected' => '1234'],
        ['step' => 'step2', 'input' => 'postal_code', 'label' => 'الرمز البريدي', 'column' => 'contracts.postal_code', 'admin' => 'postal_code', 'customer' => null, 'fixture' => '12345', 'expected' => '12345'],
        ['step' => 'step2', 'input' => 'extra_figure', 'label' => 'الرقم الإضافي', 'column' => 'contracts.extra_figure', 'admin' => 'extra_figure', 'customer' => null, 'fixture' => '6789', 'expected' => '6789'],
        ['step' => 'step2', 'input' => 'latitude (أو lat)', 'label' => 'خط العرض', 'column' => 'contracts.latitude', 'admin' => 'latitude', 'customer' => 'latitude', 'fixture' => 24.7, 'expected' => 24.7],
        ['step' => 'step2', 'input' => 'longitude (أو lng)', 'label' => 'خط الطول', 'column' => 'contracts.longitude', 'admin' => 'longitude', 'customer' => 'longitude', 'fixture' => 46.6, 'expected' => 46.6],
        ['step' => 'step2', 'input' => 'address_url', 'label' => 'رابط الموقع', 'column' => 'contracts.address_url', 'admin' => 'address_url', 'customer' => 'address_url', 'fixture' => 'https://maps.example/x', 'expected' => 'https://maps.example/x'],

        // ── الخطوة 3: المالك ──
        ['step' => 'step3', 'input' => 'name_owner', 'label' => 'اسم المالك', 'column' => 'contracts.name_owner', 'admin' => 'name_owner', 'customer' => null, 'fixture' => 'مالك تجريبي', 'expected' => 'مالك تجريبي'],
        ['step' => 'step3', 'input' => 'property_owner_id_num', 'label' => 'هوية المالك', 'column' => 'contracts.property_owner_id_num', 'admin' => 'property_owner_id_num', 'customer' => 'property_owner_id_num', 'fixture' => '1012345678', 'expected' => '1012345678'],
        ['step' => 'step3', 'input' => 'property_owner_dob_day/_month/_year', 'label' => 'ميلاد المالك', 'column' => 'contracts.property_owner_dob (DD-MM-YYYY)', 'admin' => 'property_owner_dob', 'customer' => null, 'fixture' => ['day' => 10, 'month' => 5, 'year' => 1400], 'expected' => '10-05-1400'],
        ['step' => 'step3', 'input' => 'type_dob_property_owner', 'label' => 'نوع تاريخ ميلاد المالك', 'column' => 'contracts.type_dob_property_owner', 'admin' => 'type_dob_property_owner', 'customer' => null, 'fixture' => 'hijri', 'expected' => 'hijri'],
        ['step' => 'step3', 'input' => 'property_owner_mobile (5XXXXXXXX)', 'label' => 'جوال المالك', 'column' => 'contracts.property_owner_mobile', 'admin' => 'property_owner_mobile', 'customer' => null, 'fixture' => '551234567', 'expected' => '551234567'],
        ['step' => 'step3', 'input' => 'property_owner_iban', 'label' => 'آيبان المالك', 'column' => 'contracts.property_owner_iban', 'admin' => 'property_owner_iban', 'customer' => null, 'fixture' => 'SA0380000000608010167519', 'expected' => 'SA0380000000608010167519'],
        ['step' => 'step3', 'input' => 'add_legal_agent_of_owner + id_num_of_property_owner_agent / dob_of_property_owner_agent_* / mobile_of_property_owner_agent / agency_number_in_instrument_of_property_owner / agency_instrument_date_of_property_owner', 'label' => 'وكيل المالك', 'column' => 'contracts.(نفس الأسماء؛ التاريخ dob_of_property_owner_agent)', 'admin' => 'نفس الأسماء + dob_of_property_owner_agent_day/_month/_year', 'customer' => null, 'fixture' => null, 'expected' => null],

        // ── الخطوة 4: المستأجر ──
        ['step' => 'step4', 'input' => 'tenant_entity (person|institution)', 'label' => 'نوع المستأجر', 'column' => 'contracts.tenant_entity', 'admin' => 'tenant_entity', 'customer' => null, 'fixture' => 'person', 'expected' => 'person'],
        ['step' => 'step4', 'input' => 'tenant_id_num', 'label' => 'هوية المستأجر', 'column' => 'contracts.tenant_id_num', 'admin' => 'tenant_id_num', 'customer' => 'tenant_id_num', 'fixture' => '1098765432', 'expected' => '1098765432'],
        ['step' => 'step4', 'input' => 'tenant_dob_day/_month/_year', 'label' => 'ميلاد المستأجر', 'column' => 'contracts.tenant_dob (DD-MM-YYYY)', 'admin' => 'tenant_dob', 'customer' => null, 'fixture' => ['day' => 15, 'month' => 5, 'year' => 1990], 'expected' => '15-05-1990'],
        ['step' => 'step4', 'input' => 'type_tenant_dob', 'label' => 'نوع تاريخ ميلاد المستأجر', 'column' => 'contracts.type_tenant_dob', 'admin' => 'type_tenant_dob', 'customer' => null, 'fixture' => 'gregorian', 'expected' => 'gregorian'],
        ['step' => 'step4', 'input' => 'tenant_mobile (5XXXXXXXX)', 'label' => 'جوال المستأجر', 'column' => 'contracts.tenant_mobile', 'admin' => 'tenant_mobile', 'customer' => null, 'fixture' => '559998877', 'expected' => '559998877'],
        ['step' => 'step4', 'input' => 'tenant_entity_unified_registry_number / authorization_type / copy_of_the_owner_record (منشأة)', 'label' => 'بيانات المنشأة', 'column' => 'contracts.(نفس الأسماء)', 'admin' => 'نفس الأسماء', 'customer' => null, 'fixture' => null, 'expected' => null],

        // ── الخطوة 5: الوحدات والعدادات (units[]) — جدول real_units عبر contract_units ──
        ['step' => 'step5', 'input' => 'units[].unit_number', 'label' => 'رقم الوحدة', 'column' => 'real_units.unit_number', 'admin' => 'units.0.unit_number', 'customer' => 'units.0.unit_number', 'fixture' => '12', 'expected' => '12'],
        ['step' => 'step5', 'input' => 'units[].floor_number', 'label' => 'رقم الدور', 'column' => 'real_units.floor_number', 'admin' => 'units.0.floor_number', 'customer' => 'units.0.floor_number', 'fixture' => 1, 'expected' => '1'],
        ['step' => 'step5', 'input' => 'units[].unit_area', 'label' => 'المساحة', 'column' => 'real_units.unit_area', 'admin' => 'units.0.unit_area', 'customer' => 'units.0.unit_area', 'fixture' => 150, 'expected' => '150'],
        ['step' => 'step5', 'input' => 'units[].unit_type_id', 'label' => 'نوع الوحدة', 'column' => 'real_units.unit_type_id', 'admin' => 'units.0.unit_type_id', 'customer' => 'units.0.unit_type_id', 'fixture' => '@unit_type_id', 'expected' => '@unit_type_id'],
        ['step' => 'step5', 'input' => 'units[].unit_usage_id', 'label' => 'استخدام الوحدة', 'column' => 'real_units.unit_usage_id', 'admin' => 'units.0.unit_usage_id', 'customer' => 'units.0.unit_usage_id', 'fixture' => '@unit_usage_id', 'expected' => '@unit_usage_id'],
        ['step' => 'step5', 'input' => 'units[].tootal_rooms', 'label' => 'الغرف', 'column' => 'real_units.tootal_rooms', 'admin' => 'units.0.tootal_rooms', 'customer' => 'units.0.tootal_rooms', 'fixture' => 3, 'expected' => '3'],
        ['step' => 'step5', 'input' => 'units[].The_number_of_halls', 'label' => 'الصالات', 'column' => 'real_units.The_number_of_halls', 'admin' => 'units.0.The_number_of_halls', 'customer' => 'units.0.The_number_of_halls', 'fixture' => 1, 'expected' => '1'],
        ['step' => 'step5', 'input' => 'units[].The_number_of_kitchens', 'label' => 'المطابخ', 'column' => 'real_units.The_number_of_kitchens', 'admin' => 'units.0.The_number_of_kitchens', 'customer' => 'units.0.The_number_of_kitchens', 'fixture' => 1, 'expected' => '1'],
        ['step' => 'step5', 'input' => 'units[].The_number_of_toilets', 'label' => 'دورات المياه', 'column' => 'real_units.The_number_of_toilets', 'admin' => 'units.0.The_number_of_toilets', 'customer' => 'units.0.The_number_of_toilets', 'fixture' => 2, 'expected' => '2'],
        ['step' => 'step5', 'input' => 'units[].split_ac', 'label' => 'مكيف سبليت', 'column' => 'real_units.split_ac', 'admin' => 'units.0.split_ac', 'customer' => 'units.0.split_ac', 'fixture' => 2, 'expected' => 2],
        ['step' => 'step5', 'input' => 'units[].window_ac', 'label' => 'مكيف شباك', 'column' => 'real_units.window_ac', 'admin' => 'units.0.window_ac', 'customer' => 'units.0.window_ac', 'fixture' => 1, 'expected' => 1],
        ['step' => 'step5', 'input' => 'units[].kitchen_tank', 'label' => 'خزان المطبخ', 'column' => 'real_units.kitchen_tank', 'admin' => 'units.0.kitchen_tank', 'customer' => 'units.0.kitchen_tank', 'fixture' => true, 'expected' => true],
        ['step' => 'step5', 'input' => 'units[].furnished', 'label' => 'مؤثثة', 'column' => 'real_units.furnished', 'admin' => 'units.0.furnished', 'customer' => 'units.0.furnished', 'fixture' => true, 'expected' => true],
        ['step' => 'step5', 'input' => 'units[].Number_parking_spaces', 'label' => 'المواقف', 'column' => 'real_units.Number_parking_spaces', 'admin' => 'units.0.Number_parking_spaces', 'customer' => 'units.0.Number_parking_spaces', 'fixture' => '1', 'expected' => '1'],
        ['step' => 'step5', 'input' => 'units[].electricity_meter', 'label' => 'عداد كهرباء', 'column' => 'real_units.electricity_meter', 'admin' => 'units.0.electricity_meter', 'customer' => 'units.0.electricity_meter', 'fixture' => true, 'expected' => true],
        ['step' => 'step5', 'input' => 'units[].electricity_meter_number', 'label' => 'رقم عداد الكهرباء', 'column' => 'real_units.electricity_meter_number', 'admin' => 'units.0.electricity_meter_number', 'customer' => 'units.0.electricity_meter_number', 'fixture' => 'E-1', 'expected' => 'E-1'],
        ['step' => 'step5', 'input' => 'units[].electricity_meter_ownership (owner|tenant|shared)', 'label' => 'ملكية عداد الكهرباء', 'column' => 'real_units.electricity_meter_ownership', 'admin' => 'units.0.electricity_meter_ownership', 'customer' => 'units.0.electricity_meter_ownership', 'fixture' => 'tenant', 'expected' => 'tenant'],
        ['step' => 'step5', 'input' => 'units[].water_meter', 'label' => 'عداد مياه', 'column' => 'real_units.water_meter', 'admin' => 'units.0.water_meter', 'customer' => 'units.0.water_meter', 'fixture' => true, 'expected' => true],
        ['step' => 'step5', 'input' => 'units[].water_meter_number', 'label' => 'رقم عداد المياه', 'column' => 'real_units.water_meter_number', 'admin' => 'units.0.water_meter_number', 'customer' => 'units.0.water_meter_number', 'fixture' => 'W-1', 'expected' => 'W-1'],
        ['step' => 'step5', 'input' => 'units[].water_meter_ownership', 'label' => 'ملكية عداد المياه', 'column' => 'real_units.water_meter_ownership', 'admin' => 'units.0.water_meter_ownership', 'customer' => 'units.0.water_meter_ownership', 'fixture' => 'shared', 'expected' => 'shared'],
        ['step' => 'step5', 'input' => 'units[].water_shared_monthly_fee', 'label' => 'مبلغ العداد المشترك (شهري)', 'column' => 'real_units.water_shared_monthly_fee', 'admin' => 'units.0.water_shared_monthly_fee', 'customer' => 'units.0.water_shared_monthly_fee', 'fixture' => 50, 'expected' => 50],

        // ── الخطوة 6: المدة والمالية والشروط ──
        ['step' => 'step6', 'input' => 'contract_starting_date_day/_month/_year', 'label' => 'تاريخ بداية العقد', 'column' => 'contracts.contract_starting_date (ميلادي YYYY-MM-DD)', 'admin' => 'contract_starting_date', 'customer' => null, 'fixture' => ['day' => 1, 'month' => 11, 'year' => 2026], 'expected' => '2026-11-01'],
        ['step' => 'step6', 'input' => 'type_contract_starting_date', 'label' => 'نوع تاريخ البداية', 'column' => 'contracts.type_contract_starting_date', 'admin' => 'type_contract_starting_date', 'customer' => null, 'fixture' => 'gregorian', 'expected' => 'gregorian'],
        ['step' => 'step6', 'input' => 'contract_term_in_years (معرّف contract_periods)', 'label' => 'مدة العقد', 'column' => 'contracts.contract_term_in_years', 'admin' => 'contract_term_in_years.id', 'customer' => null, 'fixture' => '@period_id', 'expected' => '@period_id'],
        ['step' => 'step6', 'input' => 'duration_preset=other + duration_years + duration_months', 'label' => 'مدة أخرى', 'column' => 'contracts.duration_preset / duration_years / duration_months / total_months', 'admin' => 'duration_preset / duration_years / duration_months / total_months', 'customer' => 'duration_preset / duration_years / duration_months / total_months', 'fixture' => null, 'expected' => null],
        ['step' => 'step6', 'input' => 'annual_rent_amount_for_the_unit', 'label' => 'الإيجار السنوي', 'column' => 'contracts.annual_rent_amount_for_the_unit', 'admin' => 'annual_rent_amount_for_the_unit', 'customer' => null, 'fixture' => 30000, 'expected' => '30000'],
        ['step' => 'step6', 'input' => 'Guarantee_amount', 'label' => 'مبلغ الضمان', 'column' => 'contracts.Guarantee_amount', 'admin' => 'Guarantee_amount', 'customer' => null, 'fixture' => 1000, 'expected' => '1000'],
        ['step' => 'step6', 'input' => 'deposit', 'label' => 'العربون', 'column' => 'contracts.deposit', 'admin' => 'deposit', 'customer' => null, 'fixture' => 500, 'expected' => '500'],
        ['step' => 'step6', 'input' => 'daily_fine', 'label' => 'غرامة التأخير', 'column' => 'contracts.daily_fine', 'admin' => 'daily_fine', 'customer' => null, 'fixture' => 50, 'expected' => '50'],
        ['step' => 'step6', 'input' => 'payment_type_id', 'label' => 'دورية الدفع', 'column' => 'contracts.payment_type_id', 'admin' => 'payment_type_id', 'customer' => null, 'fixture' => '@payment_type_id', 'expected' => '@payment_type_id'],
        ['step' => 'step6', 'input' => 'conditions + other_conditions_list[]', 'label' => 'الشروط الأخرى', 'column' => 'contracts.other_conditions_list (json)', 'admin' => 'other_conditions_list', 'customer' => null, 'fixture' => ['شرط أول'], 'expected' => ['شرط أول']],
        ['step' => 'step6', 'input' => 'tenant_role_ids[] / tenant_role_values', 'label' => 'صفات المستأجر', 'column' => 'contracts.tenant_role_ids (json) / tenant_role_values', 'admin' => 'tenant_role_ids / tenant_role_names', 'customer' => 'tenant_role_ids / tenant_role_values', 'fixture' => null, 'expected' => null],
    ];

    /** طلب تغيير المؤجر — POST /api/v2/lessor-change (multipart). */
    public const LESSOR_CHANGE = [
        ['input' => 'old_deed_image (ملف)', 'label' => 'صك المالك القديم', 'column' => 'lessor_change_requests.old_deed_image', 'admin' => 'old_deed_image_url (رابط موقّع، في GET /api/admin/lessor-change/{id})', 'customer' => '—'],
        ['input' => 'new_deed_image (ملف)', 'label' => 'صك المالك الجديد', 'column' => 'lessor_change_requests.new_deed_image', 'admin' => 'new_deed_image_url', 'customer' => '—'],
        ['input' => 'new_owner_id_number', 'label' => 'هوية المالك الجديد', 'column' => 'lessor_change_requests.new_owner_id_number', 'admin' => 'new_owner_id_number', 'customer' => 'new_owner_id_number'],
        ['input' => 'new_owner_dob_day/_month/_year', 'label' => 'ميلاد المالك الجديد', 'column' => 'lessor_change_requests.new_owner_dob', 'admin' => 'new_owner_dob', 'customer' => 'new_owner_dob'],
        ['input' => 'new_owner_dob_type (hijri|gregorian)', 'label' => 'نوع التاريخ', 'column' => 'lessor_change_requests.new_owner_dob_type', 'admin' => 'new_owner_dob_type', 'customer' => 'new_owner_dob_type'],
        ['input' => 'mobile', 'label' => 'الجوال', 'column' => 'lessor_change_requests.mobile', 'admin' => 'mobile', 'customer' => '—'],
        ['input' => 'notes', 'label' => 'ملاحظات', 'column' => 'lessor_change_requests.notes', 'admin' => 'notes', 'customer' => '—'],
        ['input' => 'platform (web|app)', 'label' => 'المنصة', 'column' => 'lessor_change_requests.platform', 'admin' => 'platform', 'customer' => '—'],
    ];

    public static function markdown(): string
    {
        $out = [];
        $out[] = '# 🗺️ خريطة الحقول — من العميل إلى قاعدة البيانات إلى اللوحة';
        $out[] = '';
        $out[] = '> مولَّد آلياً من `app/Support/FieldMapping.php` — لا تعدّل يدوياً؛ شغّل `php artisan docs:field-mapping`.';
        $out[] = '> اختبار `tests/Feature/BatchD/FieldMappingReflectionTest.php` يمرّر كل مُدخل عبر الخطوات ويتأكد أنه يظهر في تفاصيل الطلب باللوحة.';
        $out[] = '';
        $out[] = '- **اللوحة:** `GET /api/admin/orders/{id}` → `data.<المفتاح>` (الوحدات: `data.units[]`).';
        $out[] = '- **العميل:** `GET /api/v2/contracts/{id}` → `data.<المفتاح>`؛ «—» = لا يظهر في تفاصيل الطلب، لكنه يرجع باسم المُدخل نفسه في رد الخطوة وعند الاستكمال (`POST /api/v2/contract/uncompleted-contract`).';
        $out[] = '- التواريخ الهجرية تُخزَّن `DD-MM-YYYY`، وتاريخ الصك `YYYY-MM-DD`، وتاريخ بداية العقد الميلادي `YYYY-MM-DD`. الجوالات تُرسل `5XXXXXXXX`.';
        $out[] = '';
        $out[] = '## طلب العقد (start + step1..6)';
        $out[] = '';
        $out[] = '| الخطوة | المُدخل (API) | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل |';
        $out[] = '|---|---|---|---|---|---|';
        foreach (self::CONTRACT as $row) {
            $out[] = sprintf('| %s | `%s` | %s | `%s` | `%s` | %s |', $row['step'], $row['input'], $row['label'], $row['column'], $row['admin'], $row['customer'] ? '`'.$row['customer'].'`' : '—');
        }
        $out[] = '';
        $out[] = '## طلب تغيير المؤجر (POST /api/v2/lessor-change)';
        $out[] = '';
        $out[] = '| المُدخل | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل (GET /api/v2/lessor-change/{uuid}) |';
        $out[] = '|---|---|---|---|---|';
        foreach (self::LESSOR_CHANGE as $row) {
            $out[] = sprintf('| `%s` | %s | `%s` | `%s` | %s |', $row['input'], $row['label'], $row['column'], $row['admin'], $row['customer'] === '—' ? '—' : '`'.$row['customer'].'`');
        }
        $out[] = '';
        foreach (self::batchESections() as $section) {
            $out[] = '## '.$section['title'];
            $out[] = '';
            $out[] = '| المصدر | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل |';
            $out[] = '|---|---|---|---|---|';
            foreach ($section['rows'] as $row) {
                $out[] = sprintf('| `%s` | %s | `%s` | `%s` | %s |', $row['input'], $row['label'], $row['column'], $row['admin'], $row['customer'] === '—' ? '—' : '`'.$row['customer'].'`');
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * دفعة (هـ): الرسوم بعد الدفع، الحوالة البنكية، طلبات المرفق الناقص، إدخال إيجار.
     *
     * @return list<array{title: string, rows: list<array{input: string, label: string, column: string, admin: string, customer: string}>}>
     */
    public static function batchESections(): array
    {
        return [
            ['title' => 'الرسوم بعد الدفع — دفعة (هـ) (POST /api/admin/orders/{id}/charges · فرق السعر تلقائي بعد PATCH)', 'rows' => [
                ['input' => 'amount', 'label' => 'مبلغ الرسم', 'column' => 'contract_charges.amount', 'admin' => 'charges[].amount / payment_details.charges[].amount', 'customer' => 'charges[].amount'],
                ['input' => 'message (يراه العميل كما هو)', 'label' => 'رسالة الرسم', 'column' => 'contract_charges.message', 'admin' => 'charges[].message', 'customer' => 'charges[].message'],
                ['input' => 'kind (price_difference|extra_fee)', 'label' => 'نوع الرسم', 'column' => 'contract_charges.kind', 'admin' => 'charges[].kind', 'customer' => 'charges[].kind'],
                ['input' => 'status (pending|paid|cancelled)', 'label' => 'حالة الرسم', 'column' => 'contract_charges.status', 'admin' => 'charges[].status / payment_state.pending_charges_count', 'customer' => 'charges[].status / charges[].payment_url'],
                ['input' => 'دفعة Moyasar (webhook بمفتاح chg-{uuid}-{id})', 'label' => 'دفعة الرسم', 'column' => 'payments (kind, charge_id, contract_id, contract_uuid=chg-…)', 'admin' => 'payment_details.transactions[] (kind=extra_fee|price_difference)', 'customer' => 'payment_details.transactions[] / invoice items[].kind'],
            ]],
            ['title' => 'الحوالة البنكية — دفعة (هـ) (POST /api/admin/orders/{id}/payments/bank-transfer)', 'rows' => [
                ['input' => 'amount', 'label' => 'مبلغ الحوالة', 'column' => 'payments.amount (payment_method=bank_transfer, kind=bank_transfer)', 'admin' => 'payment_details.transactions[].amount / payments[].amount', 'customer' => 'payment_details.transactions[].amount'],
                ['input' => 'receipt (ملف)', 'label' => 'صورة الإيصال', 'column' => 'payments.receipt_path (القرص الخاص payments/receipts/{contract})', 'admin' => 'payment_details.transactions[].receipt_url (رابط موقّع 30 دقيقة)', 'customer' => '—'],
                ['input' => 'reference', 'label' => 'مرجع الحوالة', 'column' => 'payments.reference', 'admin' => 'payment_details.transactions[].reference', 'customer' => 'payment_details.transactions[].reference'],
                ['input' => 'paid_at', 'label' => 'تاريخ الحوالة', 'column' => 'payments.payment_date', 'admin' => 'payment_details.transactions[].paid_at', 'customer' => 'payment_details.transactions[].paid_at'],
                ['input' => 'note', 'label' => 'ملاحظة', 'column' => 'payments.note', 'admin' => 'payments[].note', 'customer' => '—'],
                ['input' => 'الموظف المسجِّل', 'label' => 'من سجّل', 'column' => 'payments.employee_id', 'admin' => 'payment_details.transactions[].employee', 'customer' => '—'],
            ]],
            ['title' => 'طلب مرفق ناقص / تصحيح — دفعة (هـ) (POST /api/admin/orders/{id}/data-requests)', 'rows' => [
                ['input' => 'section (lessor|property|tenant)', 'label' => 'القسم', 'column' => 'contract_data_requests.section', 'admin' => 'data_requests[].section / data_request_pending.section', 'customer' => 'pending_data_requests[].section'],
                ['input' => 'items[] (مفاتيح من config/data_requests.php)', 'label' => 'البنود', 'column' => 'contract_data_requests.items (json {key,label,step,fields})', 'admin' => 'data_requests[].items / data_request_pending.items', 'customer' => 'pending_data_requests[].items / banner'],
                ['input' => 'note', 'label' => 'ملاحظة حرة', 'column' => 'contract_data_requests.note', 'admin' => 'data_requests[].note', 'customer' => 'pending_data_requests[].note'],
                ['input' => 'الحل (تلقائي عند تغيّر أي حقل من fields عبر step1..6، أو يدوي)', 'label' => 'الحالة', 'column' => 'contract_data_requests.status / resolved_by / resolved_fields', 'admin' => 'data_requests[].status / resolved_by / resolved_fields', 'customer' => 'fix.resolved_request_ids (رد الخطوة)'],
                ['input' => 'الرابط العميق', 'label' => 'رابط التصحيح', 'column' => '— (محسوب)', 'admin' => 'data_requests[].deep_link ({smart_link}?fix={id}&step={n})', 'customer' => 'pending_data_requests[].deep_link / إشعار data_missing (data.deep_link)'],
            ]],
            ['title' => 'إدخال إيجار — دفعة (هـ) (PUT /api/admin/orders/{id}/ejar-entry-progress)', 'rows' => [
                ['input' => 'section (lessor|property|unit|tenant|financial|conditions) + done', 'label' => 'أدخلتها في إيجار', 'column' => 'contracts.ejar_entry_progress (json)', 'admin' => 'ejar_entry_progress.{section}.{done,by,by_name,at}', 'customer' => '—'],
            ]],
        ];
    }
}

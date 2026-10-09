# 🗺️ خريطة الحقول — من العميل إلى قاعدة البيانات إلى اللوحة

> مولَّد آلياً من `app/Support/FieldMapping.php` — لا تعدّل يدوياً؛ شغّل `php artisan docs:field-mapping`.
> اختبار `tests/Feature/BatchD/FieldMappingReflectionTest.php` يمرّر كل مُدخل عبر الخطوات ويتأكد أنه يظهر في تفاصيل الطلب باللوحة.

- **اللوحة:** `GET /api/admin/orders/{id}` → `data.<المفتاح>` (الوحدات: `data.units[]`).
- **العميل:** `GET /api/v2/contracts/{id}` → `data.<المفتاح>`؛ «—» = لا يظهر في تفاصيل الطلب، لكنه يرجع باسم المُدخل نفسه في رد الخطوة وعند الاستكمال (`POST /api/v2/contract/uncompleted-contract`).
- التواريخ الهجرية تُخزَّن `DD-MM-YYYY`، وتاريخ الصك `YYYY-MM-DD`، وتاريخ بداية العقد الميلادي `YYYY-MM-DD`. الجوالات تُرسل `5XXXXXXXX`.

## طلب العقد (start + step1..6)

| الخطوة | المُدخل (API) | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل |
|---|---|---|---|---|---|
| start | `contract_type` | نوع العقد | `contracts.contract_type` | `contract_type_key` | `contract_type` |
| start | `instrument_type` | نوع الصك | `contracts.instrument_type` | `instrument_type_key` | `instrument_type` |
| step1 | `instrument_number` | رقم الصك | `contracts.instrument_number` | `instrument_number` | — |
| step1 | `instrument_history (+_day/_month/_year)` | تاريخ الصك | `contracts.instrument_history` | `instrument_history` | — |
| step1 | `type_instrument_history` | نوع تاريخ الصك | `contracts.type_instrument_history` | `type_instrument_history` | — |
| step1 | `real_estate_registry_number` | رقم السجل العقاري | `contracts.real_estate_registry_number` | `real_estate_registry_number` | — |
| step1 | `property_type_id` | نوع العقار | `contracts.property_type_id` | `property_type_id` | — |
| step1 | `property_usages_id` | استخدام العقار | `contracts.property_usages_id` | `property_usages_id` | — |
| step1 | `number_of_floors` | عدد الأدوار | `contracts.number_of_floors` | `number_of_floors` | — |
| step1 | `number_of_units_in_realestate` | عدد الوحدات | `contracts.number_of_units_in_realestate` | `number_of_units_in_realestate` | `number_of_units_in_realestate` |
| step1 | `age_of_the_property` | عمر العقار | `contracts.age_of_the_property` | `age_of_the_property` | `age_of_the_property` |
| step1 | `image_instrument / image_instrument_pages[] / image_instrument_from_the_front / _back (ملفات)` | صورة الصك | `contracts.image_instrument (+_pages json)` | `image_instrument (رابط موقّع مؤقت) + image_instrument_pages[]` | `image_instrument` |
| step2 | `property_place_id` | المنطقة | `contracts.property_place_id` | `property_place_id` | — |
| step2 | `property_city_id` | المدينة | `contracts.property_city_id` | `property_city_id` | — |
| step2 | `neighborhood` | الحي | `contracts.neighborhood` | `neighborhood` | — |
| step2 | `street` | الشارع | `contracts.street` | `street` | — |
| step2 | `building_number` | رقم المبنى | `contracts.building_number` | `building_number` | — |
| step2 | `postal_code` | الرمز البريدي | `contracts.postal_code` | `postal_code` | — |
| step2 | `extra_figure` | الرقم الإضافي | `contracts.extra_figure` | `extra_figure` | — |
| step2 | `latitude (أو lat)` | خط العرض | `contracts.latitude` | `latitude` | `latitude` |
| step2 | `longitude (أو lng)` | خط الطول | `contracts.longitude` | `longitude` | `longitude` |
| step2 | `address_url` | رابط الموقع | `contracts.address_url` | `address_url` | `address_url` |
| step3 | `name_owner` | اسم المالك | `contracts.name_owner` | `name_owner` | — |
| step3 | `property_owner_id_num` | هوية المالك | `contracts.property_owner_id_num` | `property_owner_id_num` | `property_owner_id_num` |
| step3 | `property_owner_dob_day/_month/_year` | ميلاد المالك | `contracts.property_owner_dob (DD-MM-YYYY)` | `property_owner_dob` | — |
| step3 | `type_dob_property_owner` | نوع تاريخ ميلاد المالك | `contracts.type_dob_property_owner` | `type_dob_property_owner` | — |
| step3 | `property_owner_mobile (5XXXXXXXX)` | جوال المالك | `contracts.property_owner_mobile` | `property_owner_mobile` | — |
| step3 | `property_owner_iban` | آيبان المالك | `contracts.property_owner_iban` | `property_owner_iban` | — |
| step3 | `add_legal_agent_of_owner + id_num_of_property_owner_agent / dob_of_property_owner_agent_* / mobile_of_property_owner_agent / agency_number_in_instrument_of_property_owner / agency_instrument_date_of_property_owner` | وكيل المالك | `contracts.(نفس الأسماء؛ التاريخ dob_of_property_owner_agent)` | `نفس الأسماء + dob_of_property_owner_agent_day/_month/_year` | — |
| step4 | `tenant_entity (person|institution)` | نوع المستأجر | `contracts.tenant_entity` | `tenant_entity` | — |
| step4 | `tenant_id_num` | هوية المستأجر | `contracts.tenant_id_num` | `tenant_id_num` | `tenant_id_num` |
| step4 | `tenant_dob_day/_month/_year` | ميلاد المستأجر | `contracts.tenant_dob (DD-MM-YYYY)` | `tenant_dob` | — |
| step4 | `type_tenant_dob` | نوع تاريخ ميلاد المستأجر | `contracts.type_tenant_dob` | `type_tenant_dob` | — |
| step4 | `tenant_mobile (5XXXXXXXX)` | جوال المستأجر | `contracts.tenant_mobile` | `tenant_mobile` | — |
| step4 | `tenant_entity_unified_registry_number / authorization_type / copy_of_the_owner_record (منشأة)` | بيانات المنشأة | `contracts.(نفس الأسماء)` | `نفس الأسماء` | — |
| step5 | `units[].unit_number` | رقم الوحدة | `real_units.unit_number` | `units.0.unit_number` | `units.0.unit_number` |
| step5 | `units[].floor_number` | رقم الدور | `real_units.floor_number` | `units.0.floor_number` | `units.0.floor_number` |
| step5 | `units[].unit_area` | المساحة | `real_units.unit_area` | `units.0.unit_area` | `units.0.unit_area` |
| step5 | `units[].unit_type_id` | نوع الوحدة | `real_units.unit_type_id` | `units.0.unit_type_id` | `units.0.unit_type_id` |
| step5 | `units[].unit_usage_id` | استخدام الوحدة | `real_units.unit_usage_id` | `units.0.unit_usage_id` | `units.0.unit_usage_id` |
| step5 | `units[].tootal_rooms` | الغرف | `real_units.tootal_rooms` | `units.0.tootal_rooms` | `units.0.tootal_rooms` |
| step5 | `units[].The_number_of_halls` | الصالات | `real_units.The_number_of_halls` | `units.0.The_number_of_halls` | `units.0.The_number_of_halls` |
| step5 | `units[].The_number_of_kitchens` | المطابخ | `real_units.The_number_of_kitchens` | `units.0.The_number_of_kitchens` | `units.0.The_number_of_kitchens` |
| step5 | `units[].The_number_of_toilets` | دورات المياه | `real_units.The_number_of_toilets` | `units.0.The_number_of_toilets` | `units.0.The_number_of_toilets` |
| step5 | `units[].split_ac` | مكيف سبليت | `real_units.split_ac` | `units.0.split_ac` | `units.0.split_ac` |
| step5 | `units[].window_ac` | مكيف شباك | `real_units.window_ac` | `units.0.window_ac` | `units.0.window_ac` |
| step5 | `units[].kitchen_tank` | خزان المطبخ | `real_units.kitchen_tank` | `units.0.kitchen_tank` | `units.0.kitchen_tank` |
| step5 | `units[].furnished` | مؤثثة | `real_units.furnished` | `units.0.furnished` | `units.0.furnished` |
| step5 | `units[].Number_parking_spaces` | المواقف | `real_units.Number_parking_spaces` | `units.0.Number_parking_spaces` | `units.0.Number_parking_spaces` |
| step5 | `units[].electricity_meter` | عداد كهرباء | `real_units.electricity_meter` | `units.0.electricity_meter` | `units.0.electricity_meter` |
| step5 | `units[].electricity_meter_number` | رقم عداد الكهرباء | `real_units.electricity_meter_number` | `units.0.electricity_meter_number` | `units.0.electricity_meter_number` |
| step5 | `units[].electricity_meter_ownership (owner|tenant|shared)` | ملكية عداد الكهرباء | `real_units.electricity_meter_ownership` | `units.0.electricity_meter_ownership` | `units.0.electricity_meter_ownership` |
| step5 | `units[].water_meter` | عداد مياه | `real_units.water_meter` | `units.0.water_meter` | `units.0.water_meter` |
| step5 | `units[].water_meter_number` | رقم عداد المياه | `real_units.water_meter_number` | `units.0.water_meter_number` | `units.0.water_meter_number` |
| step5 | `units[].water_meter_ownership` | ملكية عداد المياه | `real_units.water_meter_ownership` | `units.0.water_meter_ownership` | `units.0.water_meter_ownership` |
| step5 | `units[].water_shared_monthly_fee` | مبلغ العداد المشترك (شهري) | `real_units.water_shared_monthly_fee` | `units.0.water_shared_monthly_fee` | `units.0.water_shared_monthly_fee` |
| step6 | `contract_starting_date_day/_month/_year` | تاريخ بداية العقد | `contracts.contract_starting_date (ميلادي YYYY-MM-DD)` | `contract_starting_date` | — |
| step6 | `type_contract_starting_date` | نوع تاريخ البداية | `contracts.type_contract_starting_date` | `type_contract_starting_date` | — |
| step6 | `contract_term_in_years (معرّف contract_periods)` | مدة العقد | `contracts.contract_term_in_years` | `contract_term_in_years.id` | — |
| step6 | `duration_preset=other + duration_years + duration_months` | مدة أخرى | `contracts.duration_preset / duration_years / duration_months / total_months` | `duration_preset / duration_years / duration_months / total_months` | `duration_preset / duration_years / duration_months / total_months` |
| step6 | `annual_rent_amount_for_the_unit` | الإيجار السنوي | `contracts.annual_rent_amount_for_the_unit` | `annual_rent_amount_for_the_unit` | — |
| step6 | `Guarantee_amount` | مبلغ الضمان | `contracts.Guarantee_amount` | `Guarantee_amount` | — |
| step6 | `deposit` | العربون | `contracts.deposit` | `deposit` | — |
| step6 | `daily_fine` | غرامة التأخير | `contracts.daily_fine` | `daily_fine` | — |
| step6 | `payment_type_id` | دورية الدفع | `contracts.payment_type_id` | `payment_type_id` | — |
| step6 | `conditions + other_conditions_list[]` | الشروط الأخرى | `contracts.other_conditions_list (json)` | `other_conditions_list` | — |
| step6 | `tenant_role_ids[] / tenant_role_values` | صفات المستأجر | `contracts.tenant_role_ids (json) / tenant_role_values` | `tenant_role_ids / tenant_role_names` | `tenant_role_ids / tenant_role_values` |

## طلب تغيير المؤجر (POST /api/v2/lessor-change)

| المُدخل | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل (GET /api/v2/lessor-change/{uuid}) |
|---|---|---|---|---|
| `old_deed_image (ملف)` | صك المالك القديم | `lessor_change_requests.old_deed_image` | `old_deed_image_url (رابط موقّع، في GET /api/admin/lessor-change/{id})` | — |
| `new_deed_image (ملف)` | صك المالك الجديد | `lessor_change_requests.new_deed_image` | `new_deed_image_url` | — |
| `new_owner_id_number` | هوية المالك الجديد | `lessor_change_requests.new_owner_id_number` | `new_owner_id_number` | `new_owner_id_number` |
| `new_owner_dob_day/_month/_year` | ميلاد المالك الجديد | `lessor_change_requests.new_owner_dob` | `new_owner_dob` | `new_owner_dob` |
| `new_owner_dob_type (hijri|gregorian)` | نوع التاريخ | `lessor_change_requests.new_owner_dob_type` | `new_owner_dob_type` | `new_owner_dob_type` |
| `mobile` | الجوال | `lessor_change_requests.mobile` | `mobile` | — |
| `notes` | ملاحظات | `lessor_change_requests.notes` | `notes` | — |
| `platform (web|app)` | المنصة | `lessor_change_requests.platform` | `platform` | — |

## الرسوم بعد الدفع — دفعة (هـ) (POST /api/admin/orders/{id}/charges · فرق السعر تلقائي بعد PATCH)

| المصدر | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل |
|---|---|---|---|---|
| `amount` | مبلغ الرسم | `contract_charges.amount` | `charges[].amount / payment_details.charges[].amount` | `charges[].amount` |
| `message (يراه العميل كما هو)` | رسالة الرسم | `contract_charges.message` | `charges[].message` | `charges[].message` |
| `kind (price_difference|extra_fee)` | نوع الرسم | `contract_charges.kind` | `charges[].kind` | `charges[].kind` |
| `status (pending|paid|cancelled)` | حالة الرسم | `contract_charges.status` | `charges[].status / payment_state.pending_charges_count` | `charges[].status / charges[].payment_url` |
| `دفعة Moyasar (webhook بمفتاح chg-{uuid}-{id})` | دفعة الرسم | `payments (kind, charge_id, contract_id, contract_uuid=chg-…)` | `payment_details.transactions[] (kind=extra_fee|price_difference)` | `payment_details.transactions[] / invoice items[].kind` |

## الحوالة البنكية — دفعة (هـ) (POST /api/admin/orders/{id}/payments/bank-transfer)

| المصدر | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل |
|---|---|---|---|---|
| `amount` | مبلغ الحوالة | `payments.amount (payment_method=bank_transfer, kind=bank_transfer)` | `payment_details.transactions[].amount / payments[].amount` | `payment_details.transactions[].amount` |
| `receipt (ملف)` | صورة الإيصال | `payments.receipt_path (القرص الخاص payments/receipts/{contract})` | `payment_details.transactions[].receipt_url (رابط موقّع 30 دقيقة)` | — |
| `reference` | مرجع الحوالة | `payments.reference` | `payment_details.transactions[].reference` | `payment_details.transactions[].reference` |
| `paid_at` | تاريخ الحوالة | `payments.payment_date` | `payment_details.transactions[].paid_at` | `payment_details.transactions[].paid_at` |
| `note` | ملاحظة | `payments.note` | `payments[].note` | — |
| `الموظف المسجِّل` | من سجّل | `payments.employee_id` | `payment_details.transactions[].employee` | — |

## طلب مرفق ناقص / تصحيح — دفعة (هـ) (POST /api/admin/orders/{id}/data-requests)

| المصدر | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل |
|---|---|---|---|---|
| `section (lessor|property|tenant)` | القسم | `contract_data_requests.section` | `data_requests[].section / data_request_pending.section` | `pending_data_requests[].section` |
| `items[] (مفاتيح من config/data_requests.php)` | البنود | `contract_data_requests.items (json {key,label,step,fields})` | `data_requests[].items / data_request_pending.items` | `pending_data_requests[].items / banner` |
| `note` | ملاحظة حرة | `contract_data_requests.note` | `data_requests[].note` | `pending_data_requests[].note` |
| `الحل (تلقائي عند تغيّر أي حقل من fields عبر step1..6، أو يدوي)` | الحالة | `contract_data_requests.status / resolved_by / resolved_fields` | `data_requests[].status / resolved_by / resolved_fields` | `fix.resolved_request_ids (رد الخطوة)` |
| `الرابط العميق` | رابط التصحيح | `— (محسوب)` | `data_requests[].deep_link ({smart_link}?fix={id}&step={n})` | `pending_data_requests[].deep_link / إشعار data_missing (data.deep_link)` |

## إدخال إيجار — دفعة (هـ) (PUT /api/admin/orders/{id}/ejar-entry-progress)

| المصدر | الحقل | عمود قاعدة البيانات | مفتاح اللوحة | مفتاح العميل |
|---|---|---|---|---|
| `section (lessor|property|unit|tenant|financial|conditions) + done` | أدخلتها في إيجار | `contracts.ejar_entry_progress (json)` | `ejar_entry_progress.{section}.{done,by,by_name,at}` | — |

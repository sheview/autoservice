<?php

return [
    'customers' => [
        'created' => 'เพิ่มลูกค้าเรียบร้อยแล้ว',
        'updated' => 'บันทึกข้อมูลลูกค้าเรียบร้อยแล้ว',
        'deleted' => 'ลบลูกค้าเรียบร้อยแล้ว',
        'in_use' => 'ลบไม่ได้ เพราะลูกค้ารายนี้ยังมีสัญญาหรือทรัพย์สินอยู่',
    ],

    'contracts' => [
        'created' => 'เพิ่มสัญญาเรียบร้อยแล้ว',
        'updated' => 'บันทึกสัญญาเรียบร้อยแล้ว',
        'deleted' => 'ลบสัญญาเรียบร้อยแล้ว',
    ],

    'assets' => [
        'added' => 'เพิ่มทรัพย์สินเข้าสัญญา :count รายการ',
        'removed' => 'นำทรัพย์สินออกจากสัญญาแล้ว',
        'not_found' => 'ไม่พบทรัพย์สินที่เลือก',
        'other_customer' => 'เพิ่มได้เฉพาะทรัพย์สินของลูกค้าในสัญญานี้',
    ],

    'members' => [
        'saved' => 'บันทึกทีมโครงการแล้ว',
        'not_staff' => 'เพิ่มได้เฉพาะพนักงานของบริษัทที่ยังใช้งานอยู่',
        'not_in_team' => 'เลือกได้เฉพาะโครงการที่คุณอยู่ในทีม',
    ],

    'documents' => [
        'file' => 'ไฟล์',
        'added' => 'แนบไฟล์เรียบร้อยแล้ว',
        'deleted' => 'ลบไฟล์เรียบร้อยแล้ว',
    ],

    // Field names for validation messages.
    'fields' => [
        'code' => 'รหัสลูกค้า',
        'name' => 'ชื่อลูกค้า',
        'short_name' => 'ชื่อย่อ',
        'tax_id' => 'เลขประจำตัวผู้เสียภาษี',
        'contact_name' => 'ผู้ติดต่อ',
        'phone' => 'เบอร์โทรศัพท์',
        'email' => 'อีเมล',
        'address' => 'ที่อยู่',
        'notes' => 'หมายเหตุ',
        'customer_id' => 'ลูกค้า',
        'contract_no' => 'เลขที่สัญญา',
        'title' => 'ชื่อสัญญา',
        'status' => 'สถานะ',
        'starts_on' => 'วันเริ่มสัญญา',
        'ends_on' => 'วันสิ้นสุดสัญญา',
        'value' => 'มูลค่าสัญญา',
        'service_window' => 'ช่วงเวลาให้บริการ',
        'pm_interval_months' => 'รอบ PM',
        'notify_days_before' => 'แจ้งเตือนล่วงหน้า',
        'response_hours' => 'เวลาตอบสนอง',
        'resolve_hours' => 'เวลาแก้ไข',
    ],

    'statuses' => [
        'draft' => 'ร่าง',
        'active' => 'ใช้งาน',
        'cancelled' => 'ยกเลิก',
    ],

    // Headings of the contract Excel file (ContractSheet).
    'columns' => [
        'contract_no' => 'เลขที่สัญญา',
        'customer_code' => 'รหัสลูกค้า',
        'title' => 'ชื่อสัญญา',
        'status' => 'สถานะ',
        'starts_on' => 'วันเริ่มสัญญา',
        'ends_on' => 'วันสิ้นสุดสัญญา',
        'value' => 'มูลค่าสัญญา (บาท)',
        'service_window' => 'ช่วงเวลาให้บริการ',
        'pm_interval_months' => 'รอบ PM (เดือน)',
        'notify_days_before' => 'แจ้งเตือนก่อนหมดอายุ (วัน)',
        'notes' => 'หมายเหตุ',
        'sla_response' => 'ตอบสนอง :priority (ชม.)',
        'sla_resolve' => 'แก้ไข :priority (ชม.)',
    ],

    'imports' => [
        'file' => 'ไฟล์ Excel',
        'unreadable' => 'อ่านไฟล์ไม่ได้ กรุณาตรวจสอบว่าเป็นไฟล์ Excel หรือ CSV ที่ถูกต้อง',
        'missing_headings' => 'ไม่พบหัวคอลัมน์ "เลขที่สัญญา" ในแถวแรก กรุณาใช้ไฟล์ต้นแบบ',
        'too_many_rows' => 'ไฟล์มีข้อมูลเกิน :max แถว กรุณาแบ่งเป็นหลายไฟล์',
        'number_deleted' => 'เลขที่สัญญา ":no" เป็นของสัญญาที่ถูกลบไปแล้ว ใช้ซ้ำไม่ได้',
        'not_allowed' => 'คุณไม่มีสิทธิ์แก้ไขหรือเพิ่มสัญญา ":no"',
        'unknown_customer' => 'ไม่พบลูกค้ารหัส ":code"',
        'customer_not_allowed' => 'คุณไม่มีสิทธิ์ทำสัญญาให้ลูกค้ารหัส ":code"',
        'unknown_status' => 'ไม่รู้จักสถานะ ":value" (ใช้ ร่าง / ใช้งาน / ยกเลิก)',
        'unknown_service_window' => 'ไม่รู้จักช่วงเวลาให้บริการ ":value" (ใช้ 8x5 / 12x6 / 24x7)',
    ],

    'priorities' => [
        'critical' => 'วิกฤต',
        'high' => 'สูง',
        'medium' => 'ปานกลาง',
        'low' => 'ต่ำ',
    ],

    'sites' => [
        'name' => 'ชื่อ Site',
        'address' => 'ที่อยู่',
        'created' => 'เพิ่ม Site เรียบร้อยแล้ว',
        'updated' => 'บันทึก Site เรียบร้อยแล้ว',
        'deleted' => 'ลบ Site เรียบร้อยแล้ว',
    ],

    'expiring_mail' => [
        'subject' => 'สัญญาใกล้หมดอายุ :count ฉบับ — :tenant',
        'greeting' => 'เรียน คุณ:name',
        'intro' => 'สัญญาต่อไปนี้ใกล้ถึงวันสิ้นสุด กรุณาติดต่อลูกค้าเพื่อต่ออายุสัญญา',
        'item' => ':no :title (:customer) สิ้นสุด :date — อีก :days วัน',
        'action' => 'ดูสัญญาที่ใกล้หมดอายุ',
    ],
];

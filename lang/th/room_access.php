<?php

return [
    // Added after every room's rules, until the company writes its own.
    'default_company_terms' => [
        'ผู้ขอรับผิดชอบแจ้งเงื่อนไขนี้ให้ผู้ร่วมเข้าพื้นที่ทุกคนทราบ',
        'พบเหตุผิดปกติในห้อง (ไฟ ควัน น้ำรั่ว อุณหภูมิสูง อุปกรณ์เสียหาย) ต้องแจ้งเจ้าหน้าที่ของลูกค้าและหัวหน้างานทันที',
        'นำอุปกรณ์เข้า-ออกได้เฉพาะรายการที่ระบุในคำขอ',
    ],
    'settings_saved' => 'บันทึกการตั้งค่าห้อง Server แล้ว',
    'rooms' => [
        'created' => 'เพิ่มห้องแล้ว',
        'updated' => 'บันทึกข้อมูลห้องแล้ว',
        'deleted' => 'ลบห้องแล้ว',
        'name_taken' => 'ลูกค้ารายนี้มีห้องชื่อนี้อยู่แล้ว',
    ],
    'rules' => [
        'published' => 'บันทึกเงื่อนไขเวอร์ชัน :version แล้ว',
        'summary_required' => 'กรุณากรอกข้อสรุปของเงื่อนไขอย่างน้อย 1 ข้อ',
    ],
    'log' => [
        'settings_updated' => 'แก้ไขข้อความกลางและการเก็บข้อมูลบัตร (ห้อง Server)',
        'rules_published' => 'บันทึกเงื่อนไขการเข้าห้อง :room เวอร์ชัน :version',
    ],
    // Field names for validation messages.
    'fields' => [
        'customer_id' => 'ลูกค้า',
        'site_id' => 'สถานที่',
        'name' => 'ชื่อห้อง',
        'location' => 'ที่ตั้ง',
        'missing_rules' => 'ถ้ายังไม่มีเงื่อนไข',
        'accept_mode' => 'การยอมรับเงื่อนไข',
        'freeze_periods.*.from' => 'ช่วงห้ามเข้า ตั้งแต่',
        'freeze_periods.*.to' => 'ช่วงห้ามเข้า ถึง',
        'guard_contacts.*.name' => 'ชื่อผู้รับลิงก์',
        'guard_contacts.*.email' => 'อีเมลผู้รับลิงก์',
        'manager_ids.*' => 'ผู้ดูแลห้อง',
        'approver_user_id' => 'ผู้อนุมัติ',
        'summary' => 'ข้อสรุปเงื่อนไข',
        'summary.*' => 'ข้อสรุปเงื่อนไข',
        'effective_on' => 'วันที่มีผล',
        'received_from' => 'รับกฎจาก',
        'received_on' => 'วันที่ได้รับ',
        'file' => 'ไฟล์ฉบับเต็ม',
        'company_terms' => 'ข้อความกลาง',
        'id_retention_days' => 'จำนวนวันเก็บเลขบัตร',
    ],
];

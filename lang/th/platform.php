<?php

return [
    'modules' => [
        'updated' => 'บันทึกโมดูลของ :tenant เรียบร้อยแล้ว',
    ],

    'tenants' => [
        'created' => 'เพิ่มบริษัท :tenant และบัญชีผู้ดูแลเรียบร้อยแล้ว',
        'updated' => 'บันทึกข้อมูลบริษัท :tenant เรียบร้อยแล้ว',
        'subdomain_reserved' => 'Subdomain นี้สงวนไว้สำหรับระบบ',
    ],

    'subscription' => [
        'read_only' => 'บริษัทหมดอายุการใช้งานแล้ว ดูข้อมูลได้อย่างเดียวจนถึงวันที่ :date กรุณาติดต่อผู้ให้บริการเพื่อต่ออายุ',
        'not_started' => 'บริษัทเริ่มใช้งานได้ตั้งแต่วันที่ :date ระหว่างนี้ดูข้อมูลได้อย่างเดียว',
    ],

    // Field names for validation messages.
    'fields' => [
        'name' => 'ชื่อบริษัท',
        'subdomain' => 'Subdomain',
        'status' => 'สถานะ',
        'subscription_starts_on' => 'วันเริ่มใช้งาน',
        'subscription_ends_on' => 'วันสิ้นสุดการใช้งาน',
        'admin_name' => 'ชื่อผู้ดูแล',
        'admin_email' => 'อีเมลผู้ดูแล',
        'admin_password' => 'รหัสผ่านผู้ดูแล',
    ],
];

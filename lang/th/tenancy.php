<?php

// Server-side texts of the Tenancy module.
return [
    'company' => [
        'updated' => 'บันทึกข้อมูลบริษัทเรียบร้อยแล้ว',
        // Field names for validation messages.
        'fields' => [
            'service_phone' => 'เบอร์แจ้งบริการ',
            'service_email' => 'อีเมลแจ้งบริการ',
            'logo' => 'โลโก้',
            'auto_approve_limit' => 'วงเงินอนุมัติอัตโนมัติ',
        ],
    ],
    'branches' => [
        'created' => 'เพิ่มสาขาเรียบร้อยแล้ว',
        'updated' => 'บันทึกสาขาเรียบร้อยแล้ว',
        'deleted' => 'ลบสาขาเรียบร้อยแล้ว',
        'in_use' => 'ลบไม่ได้ เพราะยังมีผู้ใช้ ทรัพย์สิน หรือใบงานอยู่ในสาขานี้',
        'fields' => [
            'code' => 'รหัสสาขา',
            'name' => 'ชื่อสาขา',
            'address' => 'ที่อยู่',
            'province' => 'จังหวัด',
        ],
    ],
];

<?php

return [
    'assets' => [
        'created' => 'เพิ่มทรัพย์สิน :code เรียบร้อยแล้ว',
        'updated' => 'บันทึกข้อมูลทรัพย์สินเรียบร้อยแล้ว',
        'deleted' => 'ลบทรัพย์สิน :code เรียบร้อยแล้ว',
        'branch_not_allowed' => 'คุณเพิ่มหรือแก้ไขทรัพย์สินได้เฉพาะสาขาของตัวเอง',
    ],

    'categories' => [
        'created' => 'เพิ่มหมวดทรัพย์สินเรียบร้อยแล้ว',
        'updated' => 'บันทึกหมวดทรัพย์สินเรียบร้อยแล้ว',
        'deleted' => 'ลบหมวดทรัพย์สินเรียบร้อยแล้ว',
        'in_use' => 'ลบไม่ได้ เพราะยังมีทรัพย์สินอยู่ในหมวดนี้',
        'fields' => [
            'name' => 'ชื่อหมวด',
            'code_prefix' => 'รหัสนำหน้า',
            'spec_key' => 'รหัสฟิลด์',
            'spec_label' => 'ชื่อฟิลด์',
            'spec_type' => 'ชนิดข้อมูล',
            'spec_options' => 'ตัวเลือก',
        ],
    ],

    'imports' => [
        'file' => 'ไฟล์ Excel',
        'queued' => 'อัปโหลดแล้ว ระบบกำลังนำเข้าข้อมูล',
        'unreadable' => 'อ่านไฟล์ไม่ได้ กรุณาตรวจสอบว่าเป็นไฟล์ Excel หรือ CSV ที่ถูกต้อง',
        'missing_headings' => 'ไม่พบหัวคอลัมน์ "ชื่อ" และ "หมวด" ในแถวแรก กรุณาใช้ไฟล์ต้นแบบ',
        'too_many_rows' => 'ไฟล์มีข้อมูลเกิน :max แถว กรุณาแบ่งเป็นหลายไฟล์',
        'unknown_category' => 'ไม่พบหมวด ":value"',
        'unknown_branch' => 'ไม่พบสาขา ":value"',
        'unknown_customer' => 'ไม่พบลูกค้า ":value"',
        'branch_not_allowed' => 'คุณนำเข้าทรัพย์สินได้เฉพาะสาขาของตัวเอง',
        'code_deleted' => 'รหัส :code เป็นของทรัพย์สินที่ถูกลบไปแล้ว ใช้ซ้ำไม่ได้',
    ],

    // Excel headings (export, template and import).
    'columns' => [
        'asset_code' => 'รหัสทรัพย์สิน',
        'name' => 'ชื่อ',
        'category' => 'หมวด',
        'branch' => 'สาขา',
        'customer' => 'ลูกค้า (รหัส)',
        'brand' => 'ยี่ห้อ',
        'model' => 'รุ่น',
        'serial_number' => 'Serial Number',
        'status' => 'สถานะ',
        'location' => 'ตำแหน่งที่ตั้ง',
        'purchased_at' => 'วันที่ซื้อ',
        'purchase_price' => 'ราคาซื้อ (บาท)',
        'warranty_expires_at' => 'วันหมดประกัน',
        'notes' => 'หมายเหตุ',
    ],

    'statuses' => [
        'in_use' => 'ใช้งานอยู่',
        'spare' => 'สำรอง',
        'in_repair' => 'ส่งซ่อม',
        'retired' => 'ปลดระวาง',
    ],
];

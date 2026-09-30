<?php

return [
    'checklists' => [
        'created' => 'สร้าง checklist เรียบร้อยแล้ว',
        'updated' => 'บันทึก checklist เรียบร้อยแล้ว',
        'deleted' => 'ลบ checklist เรียบร้อยแล้ว',
        'category_taken' => 'หมวดนี้มี checklist อยู่แล้ว',
        'general_taken' => 'มี checklist ทั่วไปอยู่แล้ว',
    ],

    'plans' => [
        'created' => 'สร้างแผน PM และรอบ PM :count รอบเรียบร้อยแล้ว',
        'updated' => 'บันทึกแผน PM เรียบร้อยแล้ว',
        'deleted' => 'ลบแผน PM เรียบร้อยแล้ว',
        'contract_has_plan' => 'สัญญานี้มีแผน PM อยู่แล้ว',
        'interval_locked' => 'มีรอบที่เริ่มทำแล้ว จึงเปลี่ยนความถี่ไม่ได้',
        'has_work' => 'แผนนี้มีรอบที่เริ่มทำแล้ว ลบไม่ได้ ให้ยกเลิกรอบที่เหลือแทน',
    ],

    'visits' => [
        'updated' => 'บันทึกนัดหมายเรียบร้อยแล้ว',
        'started' => 'เริ่มรอบ PM แล้ว',
        'item_saved' => 'บันทึกผลตรวจเรียบร้อยแล้ว',
        'completed' => 'ปิดรอบ PM เรียบร้อยแล้ว',
        'cancelled' => 'ยกเลิกรอบ PM แล้ว',
        'ticket_opened' => 'เปิดใบงาน :no เรียบร้อยแล้ว',
        'ticket_title' => 'พบปัญหาจากงาน PM :no (:asset)',
        'not_open' => 'รอบนี้เสร็จหรือยกเลิกแล้ว แก้ไขไม่ได้',
        'not_allowed' => 'ทำรายการนี้กับรอบในสถานะปัจจุบันไม่ได้',
        'not_in_progress' => 'รอบนี้ยังไม่เริ่มหรือปิดไปแล้ว',
        'items_pending' => 'ยังมี :count เครื่องที่ยังไม่บันทึกผล',
        'cannot_open_ticket' => 'เปิดใบงานได้เฉพาะเครื่องที่พบปัญหาและยังไม่มีใบงาน',
    ],

    'mail' => [
        'greeting' => 'เรียน คุณ:name',
        'item' => ':no — :plan (:customer) :kind :date',
        'appointment' => 'นัดวันที่',
        'due' => 'ครบกำหนดวันที่',
        'action' => 'เปิดรายการรอบ PM',
        'assigned' => [
            'subject' => 'รอบ PM ของคุณที่ใกล้ถึงกำหนด :count รอบ',
            'line' => 'รอบ PM ต่อไปนี้ใกล้ถึงวันนัดหรือวันครบกำหนดแล้ว (บางรอบอาจเลยกำหนด) กรุณาเตรียมเข้าหน้างาน',
        ],
        'unassigned' => [
            'subject' => 'รอบ PM ที่ยังไม่มีช่าง :count รอบ',
            'line' => 'รอบ PM ต่อไปนี้ใกล้ถึงกำหนดแต่ยังไม่มีผู้รับผิดชอบ กรุณามอบหมายช่าง',
        ],
    ],

    'photos' => [
        'added' => 'แนบรูปเรียบร้อยแล้ว',
        'deleted' => 'ลบรูปเรียบร้อยแล้ว',
        'too_many' => 'แนบรูปได้ไม่เกิน :max รูปต่อเครื่อง',
    ],

    // Field names for validation messages.
    'fields' => [
        'name' => 'ชื่อ checklist',
        'asset_category_id' => 'หมวดทรัพย์สิน',
        'items' => 'รายการตรวจ',
        'items.*.key' => 'รหัสรายการตรวจ',
        'items.*.label' => 'หัวข้อที่ตรวจ',
        'items.*.type' => 'ชนิดคำตอบ',
        'contract_id' => 'สัญญา',
        'title' => 'ชื่อแผน',
        'interval_months' => 'ความถี่',
        'assignee_id' => 'ช่าง',
        'notes' => 'หมายเหตุ',
        'scheduled_on' => 'วันนัด',
        'result' => 'ผล',
        'note' => 'หมายเหตุ',
        'summary' => 'สรุปผล',
        'reason' => 'เหตุผล',
        'priority' => 'ความเร่งด่วน',
        'photo' => 'รูปภาพ',
    ],
];

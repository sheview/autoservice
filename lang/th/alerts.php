<?php

// Alerts on LINE / Telegram / e-mail (App\Modules\Platform\Actions\SendAlert).
return [
    'open' => 'เปิดดูในระบบ',
    'saved' => 'บันทึกการตั้งค่าการแจ้งเตือนแล้ว',
    'not_ready' => 'ยังตั้งค่า :channel ไม่ครบ (เปิดใช้ ใส่ข้อมูลให้ครบ แล้วกดบันทึกก่อนทดสอบ)',
    'unreachable' => 'เชื่อมต่อ :channel ไม่ได้ ลองใหม่อีกครั้ง',
    'channels' => [
        'line' => 'LINE',
        'telegram' => 'Telegram',
        'mail' => 'อีเมล',
    ],
    'fields' => [
        'line.to' => 'LINE User ID / Group ID',
        'line.token' => 'Channel access token',
        'telegram.chat_id' => 'Chat ID',
        'telegram.token' => 'Bot token',
        'mail.recipients' => 'อีเมลผู้รับ',
        'mail.recipients.*' => 'อีเมลผู้รับ',
    ],
    'test' => [
        'title' => 'ทดสอบการแจ้งเตือน',
        'body' => 'ถ้าเห็นข้อความนี้ แปลว่าตั้งค่าการแจ้งเตือนถูกต้องแล้ว',
        'sent' => 'ส่งข้อความทดสอบทาง :channel แล้ว',
        'failed' => 'ส่งไม่สำเร็จ: :message',
    ],
    'events' => [
        'checkout_requested' => [
            'title' => 'ใบเบิก/ยืมใหม่รออนุมัติ :no',
            'body' => 'ผู้รับ: :borrower
รายการ: :items
ต้องใช้วันที่: :needed_by
ขอโดย: :actor',
        ],
        'checkout_approved' => [
            'title' => 'อนุมัติใบเบิก/ยืม :no',
            'body' => 'ผู้รับ: :borrower
รายการ: :items
อนุมัติโดย: :actor',
        ],
        'checkout_rejected' => [
            'title' => 'ไม่อนุมัติใบเบิก/ยืม :no',
            'body' => 'ผู้รับ: :borrower
รายการ: :items
โดย: :actor
เหตุผล: :note',
        ],
        'checkout_returned' => [
            'title' => 'รับคืนแล้ว :no',
            'body' => 'รายการ: :items
ผู้คืน: :borrower
รับคืนโดย: :actor
สภาพ: :note',
        ],
        'checkout_restocked' => [
            'title' => 'ของที่ค้างจ่ายเข้าสต็อกแล้ว :no',
            'body' => 'รายการ: :items
ผู้รับ: :borrower
พร้อมจ่ายแล้ว',
        ],
        'checkout_approval_overdue' => [
            'title' => 'ใบเบิก/ยืมรออนุมัตินานเกินกำหนด :no',
            'body' => 'ผู้รับ: :borrower
รายการ: :items
ต้องใช้วันที่: :needed_by',
        ],
        'checkout_backorder_overdue' => [
            'title' => 'มีรายการค้างจ่าย :no',
            'body' => 'รายการ: :items
ผู้รับ: :borrower
ต้องใช้วันที่: :needed_by',
        ],
        'checkout_return_overdue' => [
            'title' => 'ของยืมเลยกำหนดคืน :no',
            'body' => 'รายการ: :items
ผู้ยืม: :borrower
กำหนดคืน: :note',
        ],
        'ticket_reported' => [
            'title' => 'ลูกค้าแจ้งปัญหาผ่าน QR :no (รอตรวจสอบ)',
            'body' => "เครื่อง: :device\nลูกค้า: :customer\nอาการ: :symptoms\nผู้แจ้ง: :reporter\nติดต่อ: :contact",
        ],
        'ticket_opened' => [
            'title' => 'แจ้งซ่อมใหม่ :no',
            'body' => "เรื่อง: :title\nความเร่งด่วน: :priority\nผู้ติดต่อ: :contact\nแจ้งโดย: :actor",
        ],
        'ticket_field_reported' => [
            'title' => 'ช่างนอก/ลูกค้าส่งข้อมูลใบงาน :no ผ่านลิงก์',
            'body' => "เรื่อง: :title\nโดย: :actor\nรอ Helpdesk ตรวจและปิดงาน",
        ],
        'ticket_resolved' => [
            'title' => 'ซ่อมเสร็จแล้ว :no',
            'body' => "เรื่อง: :title\nโดย: :actor",
        ],
        'asset_in_repair' => [
            'title' => 'ทรัพย์สินส่งซ่อม :code',
            'body' => "ทรัพย์สิน: :name\nสถานที่: :location",
        ],
        'room_access_requested' => [
            'title' => 'คำขอเข้าห้อง Server รออนุมัติ :no',
            'body' => 'ลูกค้า: :customer
ห้อง: :room
วันเวลา: :when
ผู้ขอ: :requester (ผู้เข้า :people คน)
วัตถุประสงค์: :purpose',
        ],
        'room_access_approved' => [
            'title' => 'อนุมัติคำขอเข้าห้อง Server :no',
            'body' => 'ลูกค้า: :customer
ห้อง: :room
วันเวลา: :when
ผู้ขอ: :requester
อนุมัติโดย: :actor',
        ],
        'room_access_rejected' => [
            'title' => 'ไม่อนุมัติคำขอเข้าห้อง Server :no',
            'body' => 'ห้อง: :room (:customer)
วันเวลา: :when
ผู้ขอ: :requester
โดย: :actor
เหตุผล: :note',
        ],
        'room_access_info_requested' => [
            'title' => 'ขอข้อมูลเพิ่มสำหรับคำขอเข้าห้อง :no',
            'body' => 'ห้อง: :room (:customer)
ผู้ขอ: :requester
โดย: :actor
ข้อมูลที่ต้องการ: :note',
        ],
        'purchase_requested' => [
            'title' => 'ใบขอซื้อใหม่ :no',
            'body' => "ผู้ขอ: :requester\nรายการ: :item\nจำนวน: :qty\nโครงการ: :project\nต้องการใช้ภายใน: :needed_by",
        ],
        'purchase_approved' => [
            'title' => 'อนุมัติใบขอซื้อ :no รอสั่งซื้อ',
            'body' => "ผู้ขอ: :requester\nรายการ: :item\nจำนวน: :qty\nโครงการ: :project\nต้องการใช้ภายใน: :needed_by\nอนุมัติโดย: :actor",
        ],
        'purchase_rejected' => [
            'title' => 'ไม่อนุมัติใบขอซื้อ :no',
            'body' => "ผู้ขอ: :requester\nรายการ: :item\nจำนวน: :qty\nโดย: :actor\nเหตุผล: :note",
        ],
        'purchase_ordered' => [
            'title' => 'สั่งซื้อแล้ว :no',
            'body' => "ผู้ขอ: :requester\nรายการ: :item\nจำนวน: :qty\nโครงการ: :project\nต้องการใช้ภายใน: :needed_by\nสั่งซื้อโดย: :actor",
        ],
        'purchase_received' => [
            'title' => 'ได้รับของตามใบขอซื้อ :no',
            'body' => "ผู้ขอ: :requester\nรายการ: :item\nรับครั้งนี้: :qty\nโครงการ: :project\nรับโดย: :actor",
        ],
        'purchase_cancelled' => [
            'title' => 'ยกเลิกใบขอซื้อ :no',
            'body' => "ผู้ขอ: :requester\nรายการ: :item\nจำนวน: :qty\nโดย: :actor\nเหตุผล: :note",
        ],
    ],
];

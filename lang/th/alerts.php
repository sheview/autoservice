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
            'title' => 'มีคำขอ:typeใหม่ :no',
            'body' => "ทรัพย์สิน: :asset\nจำนวน: :quantity\nผู้:type: :borrower\nขอโดย: :actor",
        ],
        'checkout_approved' => [
            'title' => 'อนุมัติ:type :no',
            'body' => "ทรัพย์สิน: :asset\nผู้:type: :borrower\nอนุมัติโดย: :actor",
        ],
        'checkout_rejected' => [
            'title' => 'ไม่อนุมัติ:type :no',
            'body' => "ทรัพย์สิน: :asset\nผู้:type: :borrower\nโดย: :actor\nเหตุผล: :note",
        ],
        'checkout_returned' => [
            'title' => 'คืนทรัพย์สินแล้ว :no',
            'body' => "ทรัพย์สิน: :asset\nผู้คืน: :borrower\nรับคืนโดย: :actor",
        ],
        'ticket_opened' => [
            'title' => 'แจ้งซ่อมใหม่ :no',
            'body' => "เรื่อง: :title\nความเร่งด่วน: :priority\nผู้ติดต่อ: :contact\nแจ้งโดย: :actor",
        ],
        'ticket_resolved' => [
            'title' => 'ซ่อมเสร็จแล้ว :no',
            'body' => "เรื่อง: :title\nโดย: :actor",
        ],
        'asset_in_repair' => [
            'title' => 'ทรัพย์สินส่งซ่อม :code',
            'body' => "ทรัพย์สิน: :name\nสถานที่: :location",
        ],
    ],
];

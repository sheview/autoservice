<?php

return [
    'linked_staff' => [
        'enabled' => 'เปิดให้ :name ทำงานที่ :company แล้ว',
        'disabled' => 'ปิดการเข้า :company ของ :name แล้ว',
        'home_company' => 'นี่คือบริษัทหลักของคนนี้ เปิด/ปิดจากหน้านี้ไม่ได้ (แก้ได้ที่หน้าผู้ใช้ของบริษัทนั้น)',
    ],

    'staff_pools' => [
        'saved' => 'บันทึกการใช้ช่างร่วมกันแล้ว ระบบเปิดบัญชีให้ทุกคนในกลุ่มเรียบร้อย',
        'deleted' => 'ยกเลิกการใช้ช่างร่วมกันแล้ว บัญชีในบริษัทปลายทางถูกปิดใช้งาน (งานและประวัติยังอยู่)',
        'exists' => 'มีการตั้งค่าคู่บริษัทนี้อยู่แล้ว แก้ไขจากรายการด้านบนได้',
        'fields' => [
            'from_tenant_id' => 'ช่างของบริษัท',
            'to_tenant_id' => 'ทำงานให้บริษัท',
            'roles' => 'บทบาท',
            'is_active' => 'เปิดใช้งาน',
        ],
    ],

    'modules' => [
        'updated' => 'บันทึกโมดูลของ :tenant เรียบร้อยแล้ว',
    ],

    'tenants' => [
        'created' => 'เพิ่มบริษัท :tenant และบัญชีผู้ดูแลเรียบร้อยแล้ว',
        'updated' => 'บันทึกข้อมูลบริษัท :tenant เรียบร้อยแล้ว',
        'subdomain_reserved' => 'Subdomain นี้สงวนไว้สำหรับระบบ',
    ],

    'settings' => [
        'updated' => 'บันทึกการตั้งค่าระบบเรียบร้อยแล้ว',
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
        'session_timeout_minutes' => 'เวลาออกจากระบบอัตโนมัติ',
    ],

    // Sharing data between companies
    'shares' => [
        'fields' => [
            'abilities' => 'ข้อมูลที่แชร์',
            'roles' => 'บทบาทที่เห็นได้',
            'user_ids' => 'พนักงานที่เห็นได้',
            'branch_ids' => 'สาขาที่แชร์',
            'reason' => 'เหตุผล',
            'expires_on' => 'วันหมดอายุ',
            'company' => 'บริษัท',
            'ticket_id' => 'ใบงาน',
            'items' => 'รายการ',
            'items.*.qty' => 'จำนวน',
            'items.*.due_return_date' => 'วันกำหนดคืน',
        ],
        'central' => 'ส่วนกลาง',
        'central_roles' => [
            'central_technician' => 'ช่างส่วนกลาง',
            'central_helpdesk' => 'Helpdesk ส่วนกลาง',
        ],
        'saved' => 'บันทึกการแชร์ :from → :to เรียบร้อยแล้ว',
        'revoked' => 'ยกเลิกการแชร์เรียบร้อยแล้ว',
        'accepted' => 'ยอมรับการแชร์เรียบร้อยแล้ว',
        'not_pending' => 'การแชร์นี้ไม่ได้รอการยอมรับแล้ว',
        'requested' => 'ส่งคำขอ :no ไปที่ :company แล้ว รอผู้อนุมัติของ :company',
        'for_ticket' => '[ใบงาน :no ของ :company]',
        'forwarded_to' => 'ส่งต่อใบงานไปที่ :company เป็นใบงาน :no',
        'forward_moved' => ':company: ใบงาน :no เปลี่ยนสถานะเป็น ":status" (โดย :by)',
        'ticket_unknown' => 'ไม่พบใบงานนี้ หรือใบงานปิดไปแล้ว',
    ],

    'security' => [
        'lookup_misses' => 'พบการค้นหาสถานะงานซ่อมที่ไม่พบข้อมูล :count ครั้งภายใน :minutes นาที จาก IP :ip (อาจเป็นการไล่เดาเลขที่)',
        'captcha_failed' => 'กรุณายืนยันว่าไม่ใช่โปรแกรมอัตโนมัติ แล้วลองอีกครั้ง',
    ],
];

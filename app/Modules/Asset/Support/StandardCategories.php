<?php

namespace App\Modules\Asset\Support;

/**
 * The standard asset categories of an MA company (Network / PC / Data Center): what a new company
 * starts with (CreateTenant) and what an existing one may add when missing (AddStandardCategories).
 * Each company then changes or adds its own; nothing here is kept in step afterwards.
 */
class StandardCategories
{
    /**
     * @return list<array{name: string, code_prefix: string, service_line: string, asset_type: string, requires_serial: bool,
     *     spec_fields: list<array{key: string, label: string, type: string, options: list<string>, required: bool}>}>
     */
    public static function all(): array
    {
        $cpu = self::field('cpu', 'CPU');
        $ram = self::field('ram_gb', 'RAM (GB)', 'number');
        $storage = self::field('storage', 'หน่วยเก็บข้อมูล (เช่น SSD 512GB)');
        $os = self::field('os', 'ระบบปฏิบัติการ', 'select', ['Windows 10', 'Windows 11', 'macOS', 'Linux', 'อื่น ๆ']);
        $ip = self::field('mgmt_ip', 'IP สำหรับจัดการ');
        $firmware = self::field('firmware', 'เวอร์ชัน Firmware');

        return [
            self::category('คอมพิวเตอร์ตั้งโต๊ะ', 'PC', 'pc', [$cpu, $ram, $storage, $os]),
            self::category('โน้ตบุ๊ก', 'NB', 'pc', [$cpu, $ram, $storage, $os]),
            self::category('จอภาพ', 'MON', 'pc', [self::field('size_inch', 'ขนาด (นิ้ว)', 'number')]),
            self::category('เครื่องพิมพ์', 'PR', 'pc', [
                self::field('print_type', 'ประเภทการพิมพ์', 'select', ['เลเซอร์ขาวดำ', 'เลเซอร์สี', 'อิงค์เจ็ท', 'ดอตเมทริกซ์', 'เครื่องพิมพ์ฉลาก']),
                self::field('connection', 'การเชื่อมต่อ', 'select', ['USB', 'LAN', 'Wi-Fi']),
            ]),
            self::category('อุปกรณ์สำนักงาน', 'OE', 'pc', [
                self::field('equipment_type', 'ประเภทอุปกรณ์', 'select', ['เครื่องถ่ายเอกสาร', 'โปรเจกเตอร์', 'สแกนเนอร์', 'โทรศัพท์ IP', 'อื่น ๆ'], true),
            ]),
            self::category('เครื่องสำรองไฟ (UPS)', 'UPS', 'datacenter', [
                self::field('capacity_va', 'ขนาด (VA)', 'number', required: true),
                self::field('battery_changed_on', 'วันที่เปลี่ยนแบตเตอรี่ล่าสุด', 'date'),
            ]),
            self::category('สวิตช์', 'SW', 'network', [
                self::field('ports', 'จำนวนพอร์ต', 'number', required: true),
                self::field('layer', 'Layer', 'select', ['L2', 'L3']),
                self::field('poe', 'PoE', 'select', ['มี', 'ไม่มี']),
                $ip,
                $firmware,
            ]),
            self::category('เราเตอร์', 'RT', 'network', [$ip, $firmware]),
            self::category('ไฟร์วอลล์', 'FW', 'network', [$ip, $firmware, self::field('license_expires_on', 'วันหมดอายุ License', 'date')]),
            self::category('Access Point', 'AP', 'network', [
                self::field('wifi_standard', 'มาตรฐาน Wi-Fi', 'select', ['Wi-Fi 5', 'Wi-Fi 6', 'Wi-Fi 6E', 'Wi-Fi 7']),
                $ip,
            ]),
            self::category('เซิร์ฟเวอร์', 'SV', 'datacenter', [$cpu, $ram, $storage, self::field('rack', 'ตำแหน่ง Rack (เช่น R1-U12)'), $ip]),
            self::category('อุปกรณ์จัดเก็บและสำรองข้อมูล (Backup & Storage)', 'STO', 'datacenter', [
                self::field('capacity_tb', 'ความจุ (TB)', 'number'),
                self::field('raid', 'RAID', 'select', ['RAID 0', 'RAID 1', 'RAID 5', 'RAID 6', 'RAID 10', 'อื่น ๆ']),
                self::field('backup_software', 'ซอฟต์แวร์สำรองข้อมูล'),
                $ip,
            ]),
            self::category('ตู้ Rack', 'RK', 'datacenter', [self::field('size_u', 'ขนาด (U)', 'number')]),
            self::category('ห้อง Data Center', 'DC', 'datacenter', [
                self::field('area_sqm', 'พื้นที่ (ตร.ม.)', 'number'),
                self::field('racks', 'จำนวนตู้ Rack', 'number'),
            ]),
            self::category('ชุดแบตเตอรี่สำรอง (Battery Bank)', 'BAT', 'datacenter', [
                self::field('nominal_voltage', 'แรงดันระบบ (V)', 'number', required: true),
                self::field('capacity_ah', 'ความจุ (Ah)', 'number'),
                self::field('cells', 'จำนวนลูก', 'number'),
                self::field('installed_on', 'วันที่ติดตั้ง', 'date'),
            ]),
            self::category('เครื่องกำเนิดไฟฟ้า (Generator)', 'GEN', 'datacenter', [
                self::field('capacity_kva', 'ขนาด (kVA)', 'number', required: true),
                self::field('fuel', 'เชื้อเพลิง', 'select', ['ดีเซล', 'ก๊าซ']),
                self::field('run_hours', 'ชั่วโมงเดินเครื่องสะสม', 'number'),
            ]),
            self::category('สวิตช์สลับแหล่งจ่ายไฟอัตโนมัติ (ATS)', 'ATS', 'datacenter', [
                self::field('rating_a', 'พิกัดกระแส (A)', 'number', required: true),
                self::field('poles', 'จำนวนขั้ว', 'select', ['2P', '3P', '4P']),
            ]),
            self::category('เครื่องปรับอากาศควบคุมความแม่นยำ (Precision Air)', 'PAC', 'datacenter', [
                self::field('capacity_kw', 'ขนาดทำความเย็น (kW)', 'number'),
                self::field('refrigerant', 'สารทำความเย็น'),
            ]),
            self::category('ระบบแจ้งเหตุเพลิงไหม้ (Fire Alarm)', 'FA', 'datacenter', [
                self::field('zones', 'จำนวนโซน', 'number'),
                self::field('panel', 'รุ่นตู้ควบคุม'),
            ]),
            self::category('อุปกรณ์ตรวจจับควัน (Smoke Detector)', 'SD', 'datacenter', [
                self::field('detector_type', 'ชนิด', 'select', ['Photoelectric', 'Ionization', 'Heat', 'Multi-sensor']),
                self::field('zone', 'โซน / ตำแหน่ง'),
            ]),
            self::category('กล้องวงจรปิด (CCTV)', 'CCTV', 'network', [
                self::field('camera_type', 'ชนิดกล้อง', 'select', ['Dome', 'Bullet', 'PTZ', 'Fisheye', 'NVR / DVR']),
                self::field('resolution', 'ความละเอียด', 'select', ['2MP', '4MP', '5MP', '8MP']),
                $ip,
            ]),
            self::category('ระบบควบคุมการเข้าออก (Access Control)', 'ACS', 'network', [
                self::field('method', 'วิธียืนยันตัวตน', 'select', ['บัตร', 'ลายนิ้วมือ', 'ใบหน้า', 'รหัส', 'หลายแบบ']),
                self::field('doors', 'จำนวนประตู', 'number'),
                $ip,
            ]),
            self::category('ระบบสายสัญญาณ (Cabling)', 'CAB', 'network', [
                self::field('cable_category', 'ชนิดสาย', 'select', ['Cat5e', 'Cat6', 'Cat6A', 'Fiber OM3/OM4', 'Fiber Single-mode']),
                self::field('points', 'จำนวนจุด', 'number'),
            ]),
            self::category('ระบบตรวจวัดสภาพแวดล้อม (Environmental Monitoring)', 'EMS', 'datacenter', [
                self::field('sensors', 'จำนวนเซนเซอร์', 'number'),
                $ip,
            ]),
            self::category('ตู้ไฟฟ้า / PDU (MDB, PDU, ESDB, UDB)', 'EDB', 'datacenter', [
                self::field('board_type', 'ชนิดตู้', 'select', ['MDB', 'PDU', 'ESDB', 'UDB', 'DB']),
                self::field('rating', 'พิกัด (เช่น 320AT/400AF)'),
            ]),
            self::category('ระบบตรวจจับน้ำรั่ว (Water Leak)', 'WLD', 'datacenter', [
                self::field('cable_length_m', 'ความยาวสายตรวจจับ (เมตร)', 'number'),
                self::field('zones', 'จำนวนโซน', 'number'),
                $ip,
            ]),
            self::category('ระบบดับเพลิงอัตโนมัติ (Fire Suppression)', 'FSS', 'datacenter', [
                self::field('agent', 'สารดับเพลิง', 'select', ['Novec 1230', 'FM-200', 'CO2', 'Inergen', 'อื่น ๆ']),
                self::field('panel', 'รุ่นตู้ควบคุม'),
            ]),
            self::category('ห้องควบคุม (Control Room)', 'CTR', 'datacenter', [
                self::field('displays', 'จำนวนจอแสดงผล', 'number'),
            ]),
            self::category('ซอฟต์แวร์ลิขสิทธิ์', 'LIC', 'pc', [
                self::field('seats', 'จำนวนสิทธิ์ใช้งาน', 'number', required: true),
                self::field('license_key', 'License Key'),
                self::field('license_expires_on', 'วันหมดอายุ License', 'date'),
            ], 'software'),
        ];
    }

    private static function category(string $name, string $prefix, string $serviceLine, array $fields, string $type = 'hardware'): array
    {
        return [
            'name' => $name,
            'code_prefix' => $prefix,
            'service_line' => $serviceLine,
            'asset_type' => $type,
            'requires_serial' => false,
            'spec_fields' => $fields,
        ];
    }

    private static function field(string $key, string $label, string $type = 'text', array $options = [], bool $required = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'options' => $options, 'required' => $required];
    }
}

<?php

namespace App\Modules\Maintenance\Support;

/**
 * The standard PM checklists of an MA company, one per standard equipment category
 * (Asset\Support\StandardCategories, matched by code prefix) and one general checklist for
 * equipment whose category has none. What a new company starts with, and what an existing one
 * may add when missing (AddStandardChecklists); each company then changes its own.
 * Questions follow ChecklistItems.
 */
final class StandardChecklists
{
    /**
     * @return array<string, array{name: string, items: list<array<string, mixed>>}> by category code prefix ('' = general)
     */
    public static function all(): array
    {
        $outside = 'สภาพภายนอก';
        $power = 'ระบบไฟฟ้า';
        $system = 'ระบบ / ซอฟต์แวร์';
        $env = 'สภาพแวดล้อม';
        $test = 'การทดสอบ';

        $computer = [
            self::passFail('clean', 'ทำความสะอาดภายนอกและภายในเครื่อง', $outside, true),
            self::passFail('fan', 'พัดลมระบายความร้อนทำงานปกติ', $outside),
            self::measure('cpu_temp', 'อุณหภูมิ CPU ขณะทำงาน', '°C', null, 80, 'ฮาร์ดแวร์'),
            self::passFail('disk_health', 'สุขภาพฮาร์ดดิสก์ / SSD (S.M.A.R.T.)', 'ฮาร์ดแวร์', true),
            self::measure('disk_free', 'พื้นที่ว่างไดรฟ์ระบบ', '%', 15, null, 'ฮาร์ดแวร์'),
            self::passFail('os_update', 'อัปเดตระบบปฏิบัติการ', $system, true),
            self::passFail('antivirus', 'Antivirus อัปเดตและทำงานอยู่', $system, true),
            self::passFail('backup', 'ข้อมูลผู้ใช้มีการสำรองไว้', $system),
        ];
        $network = fn (array $extra = []) => [
            self::passFail('fan', 'พัดลมและการระบายความร้อน', $outside, true),
            self::passFail('led', 'ไฟสถานะปกติ ไม่มีไฟเตือน', $outside),
            self::measure('cpu', 'CPU utilization', '%', null, 80, $system),
            self::measure('memory', 'Memory utilization', '%', null, 85, $system),
            self::passFail('log', 'ตรวจ log ไม่พบ error ร้ายแรง', $system, true),
            self::passFail('port_errors', 'พอร์ตไม่มี CRC / error สะสมผิดปกติ', $system),
            self::text('firmware', 'เวอร์ชัน Firmware', $system),
            self::passFail('config_backup', 'สำรอง Configuration แล้ว', $system, true),
            ...$extra,
        ];

        return [
            '' => ['name' => 'PM ทั่วไป', 'items' => [
                self::passFail('look', 'ตรวจสภาพภายนอก', $outside, true),
                self::passFail('clean', 'ทำความสะอาด', $outside),
                self::passFail('function', 'ทดสอบการทำงาน', $test, true),
                self::text('remark', 'ข้อสังเกต', $test),
            ]],
            'PC' => ['name' => 'PM คอมพิวเตอร์ตั้งโต๊ะ', 'items' => $computer],
            'NB' => ['name' => 'PM โน้ตบุ๊ก', 'items' => [
                ...$computer,
                self::measure('battery_health', 'สุขภาพแบตเตอรี่', '%', 60, null, 'ฮาร์ดแวร์'),
                self::passFail('adapter', 'Adapter และสายชาร์จสภาพดี', $outside),
            ]],
            'SV' => ['name' => 'PM เซิร์ฟเวอร์', 'items' => [
                self::passFail('psu', 'Power supply ทั้งสองชุดทำงาน', $power, true),
                self::passFail('fan', 'พัดลมทุกตัวทำงานปกติ', 'ฮาร์ดแวร์', true),
                self::measure('inlet_temp', 'อุณหภูมิอากาศขาเข้า (Inlet)', '°C', 18, 27, 'ฮาร์ดแวร์'),
                self::passFail('raid', 'สถานะ RAID / ดิสก์ปกติ', 'ฮาร์ดแวร์', true),
                self::passFail('bmc_log', 'ตรวจ log iLO / iDRAC / IPMI ไม่พบ error', 'ฮาร์ดแวร์'),
                self::measure('cpu', 'CPU utilization เฉลี่ย', '%', null, 80, $system),
                self::measure('memory', 'Memory utilization', '%', null, 85, $system),
                self::measure('disk_used', 'พื้นที่ดิสก์ที่ใช้', '%', null, 85, $system),
                self::passFail('os_update', 'อัปเดต OS / Patch ความปลอดภัย', $system, true),
                self::passFail('backup', 'ตรวจผลการสำรองข้อมูลล่าสุดสำเร็จ', $system, true),
                self::text('firmware', 'เวอร์ชัน BIOS / Firmware', $system),
            ]],
            'PR' => ['name' => 'PM เครื่องพิมพ์', 'items' => [
                self::passFail('clean', 'ทำความสะอาดเส้นทางกระดาษและลูกยาง', $outside, true),
                self::passFail('rollers', 'ลูกยางดึงกระดาษสภาพดี', $outside),
                self::passFail('print_test', 'พิมพ์หน้าทดสอบ คุณภาพปกติ', $test, true),
                self::measure('toner', 'ระดับหมึก / โทนเนอร์คงเหลือ', '%', 10, null, 'วัสดุสิ้นเปลือง'),
                self::number('counter', 'ตัวนับจำนวนหน้า', 'วัสดุสิ้นเปลือง'),
                self::passFail('network', 'เชื่อมต่อเครือข่าย / พิมพ์ผ่านเครือข่ายได้', $test),
            ]],
            'SW' => ['name' => 'PM สวิตช์', 'items' => $network([
                self::passFail('poe', 'จ่ายไฟ PoE ปกติ (ถ้ามี)', $power),
                self::passFail('uplink', 'Uplink / Trunk ทำงานปกติ', $test, true),
            ])],
            'RT' => ['name' => 'PM เราเตอร์', 'items' => $network([
                self::passFail('wan', 'การเชื่อมต่อ WAN / ISP ปกติ', $test, true),
                self::passFail('routing', 'ตาราง Routing ถูกต้อง', $test),
            ])],
            'FW' => ['name' => 'PM ไฟร์วอลล์', 'items' => $network([
                self::passFail('license', 'License / Subscription ยังไม่หมดอายุ', $system, true),
                self::passFail('ha', 'สถานะ HA / Cluster ปกติ (ถ้ามี)', $system),
                self::passFail('policy_review', 'ทบทวน Policy ที่ไม่ใช้งาน', $system),
            ])],
            'AP' => ['name' => 'PM Access Point', 'items' => [
                self::passFail('led', 'ไฟสถานะปกติ', $outside, true),
                self::passFail('controller', 'เชื่อมต่อ Controller / Cloud ปกติ', $system, true),
                self::measure('signal', 'ความแรงสัญญาณ ณ จุดใช้งาน', 'dBm', -67, null, $test),
                self::number('clients', 'จำนวนผู้ใช้ขณะตรวจ', $test),
                self::text('firmware', 'เวอร์ชัน Firmware', $system),
            ]],
            'DC' => ['name' => 'PM ห้อง Data Center', 'items' => [
                self::measure('room_temp', 'อุณหภูมิห้อง', '°C', 18, 27, $env, true),
                self::measure('humidity', 'ความชื้นสัมพัทธ์', '%RH', 40, 60, $env, true),
                self::passFail('clean', 'ความสะอาดห้องและพื้นยก', $env),
                self::passFail('cable', 'การจัดเก็บสายสัญญาณเป็นระเบียบ', $env),
                self::passFail('access_log', 'ตรวจบันทึกการเข้าออกห้อง', 'ความปลอดภัย'),
                self::passFail('extinguisher', 'ถังดับเพลิงแรงดันปกติ ไม่หมดอายุ', 'ความปลอดภัย', true),
                self::passFail('lighting', 'ไฟส่องสว่าง / ไฟฉุกเฉินทำงาน', 'ความปลอดภัย'),
            ]],
            'RK' => ['name' => 'PM ตู้ Rack', 'items' => [
                self::passFail('door', 'ประตูและกุญแจตู้ใช้งานได้', $outside),
                self::passFail('fan', 'พัดลมตู้ทำงาน', $outside),
                self::measure('pdu_load', 'กระแสโหลด PDU', 'A', null, 16, $power),
                self::passFail('blanking', 'มี Blanking panel ช่องว่าง', $env),
                self::passFail('ground', 'สายกราวด์ต่อแน่น', $power, true),
            ]],
            'UPS' => ['name' => 'PM เครื่องสำรองไฟ (UPS)', 'items' => [
                self::measure('input_v', 'แรงดันไฟขาเข้า', 'V', 200, 240, $power, true),
                self::measure('output_v', 'แรงดันไฟขาออก', 'V', 210, 230, $power, true),
                self::measure('load', 'โหลดใช้งาน', '%', null, 80, $power, true),
                self::measure('runtime', 'เวลาสำรองไฟโดยประมาณ', 'นาที', 10, null, 'แบตเตอรี่'),
                self::passFail('battery_test', 'ทดสอบแบตเตอรี่ (Self-test) ผ่าน', 'แบตเตอรี่', true),
                self::measure('temp', 'อุณหภูมิบริเวณเครื่อง', '°C', null, 30, $env),
                self::passFail('fan', 'พัดลมระบายความร้อนทำงาน', $outside),
                self::passFail('alarm', 'ไม่มี Alarm / ตรวจ Event log แล้ว', $system),
            ]],
            'BAT' => ['name' => 'PM ชุดแบตเตอรี่สำรอง', 'items' => [
                self::measure('string_v', 'แรงดันรวมของชุด', 'V', null, null, 'การวัด', true),
                self::measure('cell_v_min', 'แรงดันลูกที่ต่ำที่สุด', 'V', 12.0, null, 'การวัด'),
                self::measure('resistance', 'ค่าความต้านทานภายในสูงสุด', 'mΩ', null, null, 'การวัด'),
                self::passFail('swelling', 'ไม่บวม ไม่รั่วซึม', $outside, true),
                self::passFail('terminal', 'ขั้วแน่น ไม่มีคราบเกลือ', $outside, true),
                self::measure('ambient', 'อุณหภูมิห้องแบตเตอรี่', '°C', null, 25, $env),
            ]],
            'GEN' => ['name' => 'PM เครื่องกำเนิดไฟฟ้า', 'items' => [
                self::passFail('oil', 'ระดับน้ำมันเครื่องปกติ', 'เครื่องยนต์', true),
                self::passFail('coolant', 'ระดับน้ำหล่อเย็นปกติ', 'เครื่องยนต์', true),
                self::measure('fuel', 'ระดับน้ำมันเชื้อเพลิง', '%', 50, null, 'เครื่องยนต์', true),
                self::measure('battery_v', 'แรงดันแบตเตอรี่สตาร์ท', 'V', 24, 28.5, $power),
                self::passFail('belts', 'สายพานและท่อยางสภาพดี ไม่รั่ว', 'เครื่องยนต์'),
                self::measure('run_test', 'ทดลองเดินเครื่อง', 'นาที', 15, null, $test, true),
                self::measure('output_v', 'แรงดันไฟขาออกขณะเดินเครื่อง', 'V', 380, 420, $test),
                self::measure('frequency', 'ความถี่', 'Hz', 49.5, 50.5, $test),
                self::number('run_hours', 'ชั่วโมงเดินเครื่องสะสม', $test),
            ]],
            'ATS' => ['name' => 'PM ATS', 'items' => [
                self::passFail('transfer', 'ทดสอบการสลับแหล่งจ่าย (Transfer) สำเร็จ', $test, true),
                self::measure('transfer_time', 'เวลาในการสลับ', 'วินาที', null, 10, $test),
                self::passFail('retransfer', 'สลับกลับไฟหลักอัตโนมัติ', $test, true),
                self::passFail('terminal', 'ขั้วต่อแน่น ไม่มีรอยไหม้', $power, true),
                self::passFail('indicator', 'ไฟแสดงสถานะและ Controller ปกติ', $power),
            ]],
            'PAC' => ['name' => 'PM Precision Air', 'items' => [
                self::measure('supply_temp', 'อุณหภูมิลมจ่าย', '°C', 13, 20, 'การวัด', true),
                self::measure('return_temp', 'อุณหภูมิลมกลับ', '°C', null, 30, 'การวัด'),
                self::measure('humidity', 'ความชื้นสัมพัทธ์', '%RH', 40, 60, 'การวัด', true),
                self::passFail('filter', 'ไส้กรองอากาศสะอาด / เปลี่ยนแล้ว', 'การบำรุงรักษา', true),
                self::passFail('drain', 'ท่อน้ำทิ้งไม่อุดตัน', 'การบำรุงรักษา'),
                self::measure('compressor_a', 'กระแสคอมเพรสเซอร์', 'A', null, null, 'การวัด'),
                self::passFail('alarm', 'ไม่มี Alarm ค้าง', $system),
            ]],
            'FA' => ['name' => 'PM ระบบแจ้งเหตุเพลิงไหม้', 'items' => [
                self::passFail('panel', 'ตู้ควบคุมสถานะปกติ ไม่มี Fault', 'ตู้ควบคุม', true),
                self::measure('battery_v', 'แรงดันแบตเตอรี่สำรองของตู้', 'V', 24, 28, 'ตู้ควบคุม'),
                self::passFail('call_point', 'ทดสอบ Manual call point', $test, true),
                self::passFail('sounder', 'กระดิ่ง / ไซเรน / ไฟกระพริบทำงาน', $test, true),
                self::passFail('zone', 'ทดสอบสัญญาณแต่ละโซน', $test),
                self::passFail('log', 'ตรวจบันทึกเหตุการณ์ย้อนหลัง', 'ตู้ควบคุม'),
            ]],
            'SD' => ['name' => 'PM อุปกรณ์ตรวจจับควัน', 'items' => [
                self::passFail('smoke_test', 'ทดสอบด้วยสเปรย์ควัน ตู้รับสัญญาณ', $test, true),
                self::passFail('led', 'ไฟแสดงสถานะกะพริบปกติ', $outside),
                self::passFail('clean', 'ทำความสะอาดหัวตรวจจับ', $outside),
                self::passFail('obstruction', 'ไม่มีสิ่งกีดขวาง / ไม่ถูกปิดคลุม', $outside, true),
            ]],
            'CCTV' => ['name' => 'PM กล้องวงจรปิด', 'items' => [
                self::passFail('image', 'ภาพคมชัด มุมกล้องถูกต้อง', $test, true),
                self::passFail('ir', 'ภาพกลางคืน (IR) ใช้งานได้', $test),
                self::passFail('lens', 'ทำความสะอาดเลนส์ / โดม', $outside),
                self::passFail('recording', 'บันทึกภาพต่อเนื่อง ไม่ขาดช่วง', 'เครื่องบันทึก', true),
                self::measure('retention', 'ภาพย้อนหลังที่เก็บได้', 'วัน', 30, null, 'เครื่องบันทึก'),
                self::passFail('hdd', 'ฮาร์ดดิสก์เครื่องบันทึกปกติ', 'เครื่องบันทึก', true),
                self::passFail('time_sync', 'เวลาในระบบตรงกับเวลาจริง', $system),
            ]],
            'ACS' => ['name' => 'PM ระบบควบคุมการเข้าออก', 'items' => [
                self::passFail('reader', 'เครื่องอ่านบัตร / สแกนทำงานทุกจุด', $test, true),
                self::passFail('lock', 'กลอนแม่เหล็ก / Electric strike ทำงาน', $test, true),
                self::passFail('exit', 'ปุ่มออกและ Emergency release ทำงาน', $test, true),
                self::passFail('controller', 'Controller ออนไลน์ เวลาตรง', $system),
                self::passFail('user_review', 'ทบทวนรายชื่อผู้มีสิทธิ์เข้า', $system),
                self::passFail('backup_power', 'แบตเตอรี่สำรองของ Controller ปกติ', $power),
            ]],
            'CAB' => ['name' => 'PM ระบบสายสัญญาณ', 'items' => [
                self::passFail('label', 'ป้ายระบุสายและ Patch panel ครบ', 'การจัดการ', true),
                self::passFail('management', 'จัดเก็บสายเป็นระเบียบ ไม่ตึง / ไม่หักงอ', 'การจัดการ'),
                self::passFail('link_test', 'ทดสอบ Link จุดสุ่มตรวจผ่าน', $test, true),
                self::number('faulty_points', 'จำนวนจุดที่เสีย', $test),
                self::passFail('docs', 'ปรับปรุงแผนผัง / เอกสารแล้ว', 'การจัดการ'),
            ]],
            'EMS' => ['name' => 'PM ระบบตรวจวัดสภาพแวดล้อม', 'items' => [
                self::passFail('online', 'เซนเซอร์ทุกตัวออนไลน์', $system, true),
                self::measure('temp_diff', 'ค่าอุณหภูมิต่างจากเครื่องวัดอ้างอิง', '°C', -1, 1, 'การสอบเทียบ'),
                self::measure('humidity_diff', 'ค่าความชื้นต่างจากเครื่องวัดอ้างอิง', '%RH', -5, 5, 'การสอบเทียบ'),
                self::passFail('leak', 'ทดสอบเซนเซอร์น้ำรั่ว', $test),
                self::passFail('alert', 'ทดสอบการแจ้งเตือน (อีเมล / SMS / LINE)', $test, true),
            ]],
            'STO' => ['name' => 'PM อุปกรณ์จัดเก็บและสำรองข้อมูล', 'items' => [
                self::passFail('health', 'สถานะดิสก์ / RAID ปกติ', 'ฮาร์ดแวร์', true),
                self::measure('used', 'พื้นที่ที่ใช้', '%', null, 85, 'ความจุ', true),
                self::measure('job_success', 'งานสำรองข้อมูลสำเร็จในรอบ', '%', 95, null, 'การสำรองข้อมูล', true),
                self::passFail('restore', 'ทดสอบกู้คืนข้อมูลตัวอย่างสำเร็จ', 'การสำรองข้อมูล', true),
                self::passFail('replication', 'การทำสำเนาไปปลายทาง / Offsite ปกติ', 'การสำรองข้อมูล'),
                self::text('firmware', 'เวอร์ชัน Firmware / ซอฟต์แวร์', $system),
            ]],
        ];
    }

    private static function passFail(string $key, string $label, string $section, bool $required = false): array
    {
        return array_filter(['key' => $key, 'label' => $label, 'type' => 'pass_fail', 'section' => $section, 'required' => $required ?: null]);
    }

    private static function measure(string $key, string $label, string $unit, ?float $min, ?float $max, string $section, bool $required = false): array
    {
        return array_filter(
            ['key' => $key, 'label' => $label, 'type' => 'measure', 'section' => $section, 'unit' => $unit, 'min' => $min, 'max' => $max, 'required' => $required ?: null],
            fn ($value) => $value !== null,
        );
    }

    private static function number(string $key, string $label, string $section): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'number', 'section' => $section];
    }

    private static function text(string $key, string $label, string $section): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'text', 'section' => $section];
    }
}

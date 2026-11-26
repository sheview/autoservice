<?php

namespace App\Modules\Service\Support;

/**
 * The 77 provinces of Thailand (Bangkok first, then by name), for where outside technicians work.
 */
class ThaiProvinces
{
    public const ALL = [
        'กรุงเทพมหานคร',
        'กระบี่', 'กาญจนบุรี', 'กาฬสินธุ์', 'กำแพงเพชร', 'ขอนแก่น', 'จันทบุรี', 'ฉะเชิงเทรา', 'ชลบุรี', 'ชัยนาท', 'ชัยภูมิ',
        'ชุมพร', 'เชียงราย', 'เชียงใหม่', 'ตรัง', 'ตราด', 'ตาก', 'นครนายก', 'นครปฐม', 'นครพนม', 'นครราชสีมา',
        'นครศรีธรรมราช', 'นครสวรรค์', 'นนทบุรี', 'นราธิวาส', 'น่าน', 'บึงกาฬ', 'บุรีรัมย์', 'ปทุมธานี', 'ประจวบคีรีขันธ์', 'ปราจีนบุรี',
        'ปัตตานี', 'พระนครศรีอยุธยา', 'พะเยา', 'พังงา', 'พัทลุง', 'พิจิตร', 'พิษณุโลก', 'เพชรบุรี', 'เพชรบูรณ์', 'แพร่',
        'ภูเก็ต', 'มหาสารคาม', 'มุกดาหาร', 'แม่ฮ่องสอน', 'ยโสธร', 'ยะลา', 'ร้อยเอ็ด', 'ระนอง', 'ระยอง', 'ราชบุรี',
        'ลพบุรี', 'ลำปาง', 'ลำพูน', 'เลย', 'ศรีสะเกษ', 'สกลนคร', 'สงขลา', 'สตูล', 'สมุทรปราการ', 'สมุทรสงคราม',
        'สมุทรสาคร', 'สระแก้ว', 'สระบุรี', 'สิงห์บุรี', 'สุโขทัย', 'สุพรรณบุรี', 'สุราษฎร์ธานี', 'สุรินทร์', 'หนองคาย', 'หนองบัวลำภู',
        'อ่างทอง', 'อำนาจเจริญ', 'อุดรธานี', 'อุตรดิตถ์', 'อุทัยธานี', 'อุบลราชธานี',
    ];

    /**
     * The province a free-typed place is in ("สาขาขอนแก่น", "กรุงเทพฯ", "จ.เชียงใหม่"), or null.
     */
    public static function find(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        if (in_array($text, self::ALL, true)) {
            return $text;
        }
        if (str_contains($text, 'กรุงเทพ') || str_contains($text, 'กทม')) {
            return 'กรุงเทพมหานคร';
        }
        // Longest name first: "นครราชสีมา" is not taken for a shorter name inside it.
        $names = self::ALL;
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($names as $name) {
            if (str_contains($text, $name)) {
                return $name;
            }
        }

        return null;
    }
}

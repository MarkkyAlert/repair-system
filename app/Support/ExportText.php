<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ทำเครื่องหมายให้เซลล์ export เป็นตัวระบุที่เป็นข้อความ (asset_code, ticket_no, …) เพื่อให้ตัวเขียน XLSX เก็บเป็นข้อความ
 * ตามเดิมทุกตัว — ไม่เดาว่าเป็นตัวเลขจากรูปร่างของมัน ถ้าไม่มีสิ่งนี้ รหัสอย่าง "00547790.25" หรือ "0028712749"
 * จะถูกบังคับให้เป็นตัวเลขใน Excel (เลข 0 นำหน้าหายไป รหัสยาว ๆ เสียความแม่นยำ) ทำให้ไฟล์
 * ไม่ตรงกับหน้าจอ/CSV อีกต่อไป และการ lookup/join พัง (audit R17) ส่วน CSV/PDF ไม่ได้รับผลกระทบ — มันแค่แสดง
 * ข้อความผ่าน __toString
 */
final readonly class ExportText implements \Stringable
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

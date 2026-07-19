<?php

declare(strict_types=1);

namespace App\Support;

/**
 * มาร์กเซลล์ export ให้เป็นตัวระบุที่เป็นข้อความ (asset_code, ticket_no, …) ให้ตัวเขียน XLSX เก็บเป็นข้อความ
 * ตามเดิมทุกตัว ไม่เดาว่าเป็นตัวเลขจากหน้าตาของมัน ถ้าไม่มีตัวนี้ รหัสอย่าง "00547790.25" หรือ "0028712749"
 * จะโดนบังคับเป็นตัวเลขใน Excel (เลข 0 นำหน้าหาย รหัสยาว ๆ เสียความแม่นยำ) ทำให้ไฟล์
 * ไม่ตรงกับหน้าจอ/CSV อีกต่อไป แล้วการ lookup/join ก็พัง ส่วน CSV/PDF ไม่โดนผลกระทบ เพราะมันแค่แสดง
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

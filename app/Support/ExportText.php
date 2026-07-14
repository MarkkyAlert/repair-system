<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Marks an export cell as a TEXTUAL IDENTIFIER (asset_code, ticket_no, …) so the XLSX writer stores it as text
 * verbatim — never inferring a number from its shape. Without this, a code like "00547790.25" or "0028712749"
 * would be coerced to a number in Excel (leading zeros dropped, long codes losing precision), so the file no
 * longer matches the screen/CSV and lookups/joins break (audit R17). CSV/PDF are unaffected — they just render
 * the string via __toString.
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

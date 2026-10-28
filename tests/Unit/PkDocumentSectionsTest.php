<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkDocumentSections;
use PHPUnit\Framework\TestCase;

class PkDocumentSectionsTest extends TestCase
{
    public function test_pages_split_at_heading_lines_and_text_joins_selected_sections(): void
    {
        $sections = PkDocumentSections::split([
            "Firma antedi\n## 1. GENEL BİLGİLER\n| Rapor No | 12 |",
            "## 8. KUSUR AÇIKLAMALARI\n- Dolap kilidi yok.\n## 9. SONUÇ\nUygun değildir.",
        ]);

        $this->assertSame(['', '1. GENEL BİLGİLER', '8. KUSUR AÇIKLAMALARI', '9. SONUÇ'], array_column($sections, 'heading'));
        $this->assertSame([1, 1, 2, 2], array_column($sections, 'page'));
        $this->assertSame("## 8. KUSUR AÇIKLAMALARI\n- Dolap kilidi yok.", PkDocumentSections::text($sections, [2]));
        $this->assertNull(PkDocumentSections::text($sections, []));
    }
}

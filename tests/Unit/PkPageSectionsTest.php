<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkPageSections;
use PHPUnit\Framework\TestCase;

class PkPageSectionsTest extends TestCase
{
    // Kesme ajanın satır numaralarından: sayfa başındaki satırlar önceki bölümün devamı, ilk bölüm başından öncesi rapor bilgisi.
    public function test_pages_are_cut_at_agent_line_numbers(): void
    {
        $sections = new class extends PkPageSections {
            public array $answers = [];

            protected function startsFor(array $lines, ?string $provider): array
            {
                return array_shift($this->answers);
            }
        };
        $reader = new \ReflectionMethod(PkPageSections::class, 'read');
        $this->assertTrue($reader->isPublic());

        $sections->answers = [[3 => 'kontrol_listesi'], [2 => 'kusur_notlar', 4 => 'sonuc']];
        $out = $sections->read(["Rapor No: 1\nFirma: X\nTESTLER\n1 Madde | U", "2 Madde | UD\nNOTLAR:\n-Eksik\nSONUÇ:\nUygun değil"]);

        $this->assertSame(['rapor_bilgisi', 'kontrol_listesi', 'kusur_notlar', 'sonuc'], array_column($out, 'label'));
        $this->assertSame("TESTLER\n1 Madde | U\n2 Madde | UD\n", $out[1]['text']);
        $this->assertSame("NOTLAR:\n-Eksik\n", $out[2]['text']);
        $this->assertSame([2, 4], [$out[3]['page'], $out[3]['line']]);
    }

    // Harita bölümü öncelikli; yedek önce Docling'in kusur bölümü, yoksa yalnız kısa raporda bütün metin.
    public function test_map_overrides_docling_and_fallback_is_bounded(): void
    {
        [$parts, $fallback] = PkPageSections::merge(['findings' => 'docling', 'by_system' => ['A' => 'x']], ['findings' => 'harita'], 'kısa metin');
        $this->assertSame(['findings' => 'harita', 'by_system' => ['A' => 'x']], $parts);
        $this->assertSame('docling', $fallback);

        $this->assertSame('kısa metin', PkPageSections::merge([], ['findings' => 'harita'], 'kısa metin')[1]);
        $this->assertNull(PkPageSections::merge([], ['findings' => 'harita'], str_repeat('a', 15001))[1]);
    }
}

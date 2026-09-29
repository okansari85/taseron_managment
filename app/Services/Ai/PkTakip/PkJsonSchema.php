<?php

namespace App\Services\Ai\PkTakip;

/**
 * Rapor çıktı şemasının (PkGeminiReportClient::responseSchema) katı hali: her nesnede additionalProperties=false
 * ve tüm alanlar required (OpenAI katı mod, NVIDIA guided_json). Gemini şemasında zorunlu olmayan alan (varsa)
 * null alabilir hale getirilir; anlam değişmez.
 */
final class PkJsonSchema
{
    public static function strict(array $schema): array
    {
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::strict($schema['items']);
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $required = (array) ($schema['required'] ?? []);
            foreach ($schema['properties'] as $key => $property) {
                $property = self::strict((array) $property);
                $schema['properties'][$key] = in_array($key, $required, true) ? $property : self::nullable($property);
            }
            $schema['required'] = array_keys($schema['properties']);
            $schema['additionalProperties'] = false;
        }

        return $schema;
    }

    private static function nullable(array $schema): array
    {
        $types = (array) ($schema['type'] ?? []);
        if ($types && !in_array('null', $types, true)) {
            if (array_intersect($types, ['object', 'array'])) {
                return ['anyOf' => [$schema, ['type' => 'null']]];
            }
            $schema['type'] = [...$types, 'null'];
            if (isset($schema['enum']) && !in_array(null, $schema['enum'], true)) {
                $schema['enum'][] = null;
            }
        }

        return $schema;
    }
}

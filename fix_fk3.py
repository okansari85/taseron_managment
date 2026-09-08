f = 'database/migrations/2026_08_20_220000_create_location_business_entity_brands_table.php'
s = open(f, encoding='utf-8').read()
old = """            $table->foreignId('location_business_entity_id')
                ->constrained('location_business_entities')
                ->cascadeOnDelete();
"""
new = """            $table->foreignId('location_business_entity_id');
            $table->foreign('location_business_entity_id', 'lbe_brands_business_entity_fk')
                ->references('id')->on('location_business_entities')
                ->cascadeOnDelete();
"""
assert old in s, 'pattern not found'
s = s.replace(old, new)
open(f, 'w', encoding='utf-8').write(s)
print('OK')

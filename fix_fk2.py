f = 'database/migrations/2026_09_06_000004_create_emergency_equipment_inspections_table.php'
s = open(f, encoding='utf-8').read()
old = "            $table->foreignId('location_emergency_equipment_id')->constrained('location_emergency_equipment')->cascadeOnDelete();\n"
new = ("            $table->foreignId('location_emergency_equipment_id');\n"
       "            $table->foreign('location_emergency_equipment_id', 'eei_location_equipment_fk')\n"
       "                ->references('id')->on('location_emergency_equipment')\n"
       "                ->cascadeOnDelete();\n")
assert old in s, 'pattern not found'
s = s.replace(old, new)
open(f, 'w', encoding='utf-8').write(s)
print('OK')

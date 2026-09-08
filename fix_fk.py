f = 'database/migrations/2026_09_06_000002_create_emergency_equipment_type_checklist_items_table.php'
s = open(f, encoding='utf-8').read()
old = "            $table->foreignId('equipment_type_id')->constrained('emergency_equipment_types')->cascadeOnDelete();\n"
new = ("            $table->foreignId('equipment_type_id');\n"
       "            $table->foreign('equipment_type_id', 'eetci_equipment_type_id_fk')\n"
       "                ->references('id')->on('emergency_equipment_types')\n"
       "                ->cascadeOnDelete();\n")
assert old in s, 'pattern not found'
s = s.replace(old, new)
open(f, 'w', encoding='utf-8').write(s)
print('OK')

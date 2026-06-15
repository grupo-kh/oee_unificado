const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/view_mant_proximas.js'), 'utf8');

const context = {
  console,
  URL,
  URLSearchParams,
  history: { replaceState() {} },
  window: { location: { href: 'http://localhost/views/mant_proximas.php', search: '' } },
  document: { addEventListener() {} },
  setTimeout() {},
};
context.global = context;

vm.runInNewContext(source, context, { filename: 'view_mant_proximas.js' });

assert.strictEqual(typeof context.buildMantTableItems, 'function');
assert.strictEqual(typeof context.mantCurrentWeekRangeFrom, 'function');
assert.strictEqual(typeof context.mantNextWeekRangeFrom, 'function');
assert.strictEqual(typeof context.buildMantOperatorAutoPayloads, 'function');
assert.strictEqual(typeof context.renderMantMachineGroup, 'function');

function assertCurrentWeek(base, desde, hasta) {
  const range = context.mantCurrentWeekRangeFrom(base);
  assert.deepStrictEqual({ desde: range.desde, hasta: range.hasta }, { desde, hasta });
}

function assertNextWeek(base, desde, hasta) {
  const range = context.mantNextWeekRangeFrom(base);
  assert.deepStrictEqual({ desde: range.desde, hasta: range.hasta }, { desde, hasta });
}

function plain(value) {
  return JSON.parse(JSON.stringify(value));
}

assertCurrentWeek('2026-06-09', '2026-06-08', '2026-06-14');
assertCurrentWeek('2026-06-08', '2026-06-08', '2026-06-14');
assertCurrentWeek('2026-06-14', '2026-06-08', '2026-06-14');
assertCurrentWeek('2027-01-01', '2026-12-28', '2027-01-03');

assertNextWeek('2026-06-09', '2026-06-15', '2026-06-21');
assertNextWeek('2026-06-08', '2026-06-15', '2026-06-21');
assertNextWeek('2026-06-14', '2026-06-15', '2026-06-21');
assertNextWeek('2026-12-30', '2027-01-04', '2027-01-10');

assert.deepStrictEqual(plain(context.buildMantOperatorAutoPayloads({
  orden: 'O1',
  tarea: 'T1',
  fecha_proxima_original: '2026-06-10',
})), [
  {
    orden: 'O1',
    tarea: 'T1',
    fecha_proxima_original: '2026-06-10',
    tipo: 'completada',
  },
]);
assert.deepStrictEqual(plain(context.buildMantOperatorAutoPayloads({
  consolidada: true,
  sub_tareas: [
    { orden: 'O1', tarea: 'T1', proxima_revision: '2026-06-10' },
    { orden: 'O2', tarea: 'T2', proxima_revision: '2026-06-11' },
  ],
})), [
  { orden: 'O1', tarea: 'T1', fecha_proxima_original: '2026-06-10', tipo: 'completada' },
  { orden: 'O2', tarea: 'T2', fecha_proxima_original: '2026-06-11', tipo: 'completada' },
]);

const rows = [
  {
    cod_maquina_mant: 'RACK01',
    desc_maquina: 'RACKS Puertas',
    estado: 'vencida',
    ya_marcada: false,
    marca_completada: false,
    tipo_marca: null,
    proxima_revision: '2026-03-10',
  },
  {
    cod_maquina_mant: 'RACK01',
    desc_maquina: 'RACKS Puertas',
    estado: 'vencida',
    ya_marcada: true,
    marca_completada: true,
    tipo_marca: 'completada',
    proxima_revision: '2026-03-11',
  },
  {
    cod_maquina_mant: 'M1',
    desc_maquina: 'Maquina 1',
    estado: 'urgente',
    ya_marcada: false,
    marca_completada: false,
    tipo_marca: null,
    proxima_revision: '2026-03-12',
  },
  {
    cod_maquina_mant: 'M1',
    desc_maquina: 'Maquina 1',
    estado: 'en_plazo',
    ya_marcada: true,
    marca_completada: false,
    tipo_marca: 'no_realizada',
    proxima_revision: '2026-03-13',
  },
];

const items = context.buildMantTableItems(rows);

assert.strictEqual(items.length, 3);
assert.strictEqual(items[0].type, 'group');
assert.strictEqual(items[0].group.cod_maquina_mant, 'RACK01');
assert.strictEqual(items[0].group.total, 2);
assert.strictEqual(items[0].group.hechas, 1);
assert.strictEqual(items[0].group.pendientes, 1);
assert.strictEqual(items[0].group.vencidas, 1);
assert.strictEqual(items[0].group.estado, 'vencida');
assert.strictEqual(items[0].group.proxima_revision, '2026-03-10');
assert.deepStrictEqual(Array.from(items[0].group.rowIndexes), [0, 1]);

const bulkGroupHtml = context.renderMantMachineGroup(context.buildMantTableItems([
  {
    cod_maquina_mant: 'RACK02',
    desc_maquina: 'RACKS Lunetas',
    estado: 'vencida',
    ya_marcada: false,
    marca_completada: false,
    tipo_marca: null,
    proxima_revision: '2026-03-10',
    periodicidad: 'SEMANAL',
    orden: 'O1',
    tarea: 'T1',
    desc_tarea: 'Limpieza rack',
  },
  {
    cod_maquina_mant: 'RACK02',
    desc_maquina: 'RACKS Lunetas',
    estado: 'urgente',
    ya_marcada: false,
    marca_completada: false,
    tipo_marca: null,
    proxima_revision: '2026-03-11',
    periodicidad: 'MENSUAL',
    orden: 'O2',
    tarea: 'T2',
    desc_tarea: 'Revision estructura',
  },
])[0].group);
assert.strictEqual((bulkGroupHtml.match(/class="mant-action-btn/g) || []).length, 1);
assert.match(bulkGroupHtml, /Marcar las 2 hechas/);

assert.strictEqual(items[1].type, 'row');
assert.strictEqual(items[1].row.cod_maquina_mant, 'M1');
assert.strictEqual(items[1].rowIndex, 2);

assert.strictEqual(items[2].type, 'row');
assert.strictEqual(items[2].row.cod_maquina_mant, 'M1');
assert.strictEqual(items[2].rowIndex, 3);

[
  ['PLAT01', 'Platform superior'],
  ['TRL01', 'Troley linea'],
  ['TRLY01', 'Trolley auxiliar'],
].forEach(([cod, desc]) => {
  const variantItems = context.buildMantTableItems([
    { ...rows[0], cod_maquina_mant: cod, desc_maquina: desc },
    { ...rows[1], cod_maquina_mant: cod, desc_maquina: desc },
  ]);
  assert.strictEqual(variantItems.length, 1, desc);
  assert.strictEqual(variantItems[0].type, 'group', desc);
});

console.log('OK mant_proximas_grouping');

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const test = require('node:test');

// Run the shipped webpack modules, including the real form serializer. Only
// React state, generator plumbing, and HTTP are supplied by this test harness.
function adminBundle(filename) {
  const source = fs.readFileSync(path.join(__dirname, '../public/assets/admin', filename), 'utf8');
  const boundaries = [...source.matchAll(/\n    (?:"[^"\n]+"|\w+): function\(e, t, n\)/g)];
  const requests = [];
  let config, transport;
  function asyncToGenerator(fn) {
    return function(...args) {
      const generator = fn.apply(this, args);
      return new Promise((resolve, reject) => {
        function step(method, value) {
          let result;
          try { result = generator[method](value); } catch (error) { reject(error); return; }
          if (result.done) { resolve(result.value); return; }
          Promise.resolve(result.value).then(value => step('next', value), error => step('throw', error));
        }
        step('next');
      });
    };
  }
  function requireStub(name) {
    if (name === 'p0pE') return Object.assign;
    if (name === '1l/V') return asyncToGenerator;
    if (name === '/xke') return {};
    if (name === 'TeRw') return {a: {error() {}}};
    if (name === 'Hg0r') return {b: async (url, options) => {
      requests.push({url, ...options});
      return {status: 200, headers: {get: () => 'application/json'}, json: async () => ({data: true})};
    }};
    if (name === '20nU') return {a: {serviceHost: 'https://panel.test/api/v1'}};
    if (name === 'yWgo') return {c: () => null, g() {}};
    if (name === 't3Un') return transport;
    if (name === 'tI4l') return {a: config};
    throw new Error('Unexpected bundle dependency: ' + name);
  }
  requireStub.r = exports => Object.defineProperty(exports, '__esModule', {value: true});
  requireStub.n = value => () => value;
  requireStub.d = (exports, name, getter) => Object.defineProperty(exports, name, {get: getter});
  function loadModule(name) {
    const index = boundaries.findIndex(m => m[0].startsWith('\n    ' + name + ':'));
    assert(index >= 0, 'webpack module ' + name);
    const text = source.slice(boundaries[index].index, boundaries[index + 1].index).trim().replace(/,$/, '');
    const module = {exports: {}};
    const wrapper = vm.runInNewContext('({' + text + '})', {
      window: {settings: {secure_path: 'admin'}},
      Promise, Object, Math, Array, encodeURIComponent,
    });
    wrapper[name](module, module.exports, requireStub);
    return module.exports;
  }
  config = loadModule('tI4l').a;
  transport = loadModule('t3Un');
  const model = loadModule('GmDa').default;
  const handlerSource = source.match(/priceOnChange\(e, t\) \{[\s\S]*?\n            \}\n            save\(\)/)[0].replace(/\n            save\(\)$/, '');
  const handler = vm.runInNewContext('({' + handlerSource + '})', {d: () => Object.assign});
  const editorStart = source.lastIndexOf('class T extends m.a.Component {', source.indexOf('priceOnChange(e, t) {'));
  const editorConstructor = source.slice(editorStart, source.indexOf('            componentDidMount()', editorStart));
  const Editor = vm.runInNewContext('(' + editorConstructor + '})', {
    m: {a: {Component: class {constructor(props) {this.props = props;}}}},
    d: () => Object.assign, y: {a: config},
  });
  const column = source.slice(source.indexOf('dataIndex: "month_price"', editorStart));
  const render = vm.runInNewContext('(' + column.match(/render:\s*(e=>\{[\s\S]*?\n                    \})/)[1] + ')', {y: {a: config}});
  return {
    config,
    open: record => new Editor({record}).state.record,
    render,
    async save(field, input) {
      const record = Object.fromEntries(Object.keys(config.periodText).flatMap(p => [[p, null], [p + '_usd', null]]));
      const state = {state: {record}, setState(value) {Object.assign(this.state, value);}};
      handler.priceOnChange.call(state, field, input);
      assert.equal(state.state.record[field], input, 'visible input must keep its separators');
      const iterator = model.effects.save({params: {...state.state.record}}, {put: async () => undefined});
      let next = iterator.next();
      while (!next.done) next = iterator.next(await next.value);
      return new URLSearchParams(requests.at(-1).body).get(field);
    },
  };
}

for (const filename of ['umi.js', 'umi-fa.js']) {
  test(filename + ': valid thousands reach the API as the same numeric price as unformatted input', async () => {
    const bundle = adminBundle(filename);
    for (const [grouped, plain] of [['1,500,000', '1500000'], ['150,000', '150000'], ['15,000', '15000'], ['۱۵۰٬۰۰۰', '150000']]) {
      const result = await bundle.save('month_price', grouped);
      assert.equal(result, await bundle.save('month_price', plain), grouped);
      // Preserve the existing web UI's display /100 and submit x100 scale.
      assert.equal(result, String(Number(plain) * 100));
    }
  });

  test(filename + ': grouped USD values preserve their fractional amount without Toman scaling', async () => {
    const bundle = adminBundle(filename);
    assert.equal(await bundle.save('month_price_usd', '1,500.25'), '1500.25');
    assert.equal(await bundle.save('month_price_usd', '۱٬۵۰۰٫۲۵'), '1500.25');
  });

  test(filename + ': display keeps thousands and fractional zeros; commas are never decimal marks', () => {
    const {config} = adminBundle(filename);
    assert.equal(config.formatPrice(1500000), '1,500,000');
    assert.equal(config.formatPrice('150000.00'), '150,000.00');
    assert.equal(config.formatPrice('۱٬۵۰۰٫۲۵'), '1,500.25');
    assert.equal(config.formatPrice('1,5'), '1,5');
    assert.equal(config.formatPrice(''), '');
  });

  test(filename + ': the shipped editor and price column display grouped existing prices', () => {
    const bundle = adminBundle(filename);
    const original = {month_price: 150000, month_price_usd: '1500.25'};
    const displayed = bundle.open(original);
    assert.equal(displayed.month_price, '150,000');
    assert.equal(displayed.month_price_usd, '1,500.25');
    assert.equal(original.month_price, 150000, 'opening the editor must not mutate the table record');
    assert.equal(bundle.render(150000), '150,000.00');
  });

  test(filename + ': malformed thousands remain invalid rather than changing the amount', async () => {
    const bundle = adminBundle(filename);
    for (const input of ['1,5', '1,50', '15,00', '1,,500']) {
      assert.equal(await bundle.save('month_price', input), 'NaN');
    }
  });
}

test('both admin language bundles remain identical', () => {
  const root = path.join(__dirname, '../public/assets/admin');
  assert.equal(fs.readFileSync(path.join(root, 'umi.js'), 'utf8'), fs.readFileSync(path.join(root, 'umi-fa.js'), 'utf8'));
});

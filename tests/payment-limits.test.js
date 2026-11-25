// Run with: node --test tests/payment-limits.test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

// Execute each theme's actual calculation without starting its page controller.
function loadCalculation(file, name) {
    const source = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
    const start = source.indexOf(`function ${name}(`);
    assert.ok(start >= 0, `${file}: calculation not found`);
    let depth = 0;
    for (let i = source.indexOf('{', start); i < source.length; i++) {
        if (source[i] === '{') depth++;
        if (source[i] === '}' && --depth === 0) {
            return vm.runInNewContext(`(${source.slice(start, i + 1)})`);
        }
    }
    throw new Error(`${file}: incomplete calculation`);
}

for (const [file, name] of [
    ['assets/user/controller/index/item.js', '_PayBreach'],
    ['assets/user/controller/user/recharge.js', 'payBreach'],
    ['assets/static/acg.js', 'acgPayBreach'],
]) {
    const breach = loadCalculation(file, name);
    const limits = {min: 10, max: 100, cost: 0, percent: false};
    test(`${file}: inclusive minimum and maximum; free and unlimited payments`, () => {
        for (const amount of [10, 50, 100, 0]) assert.equal(breach(limits, amount), null);
        assert.equal(breach(null, 1000), null);
        assert.equal(breach(limits, 9.99).over, false);
        assert.equal(breach(limits, 100.01).over, true);
    });
    test(`${file}: percentage fee truncates to cents`, () => {
        const percent = {min: 101.51, max: 101.51, cost: 0.015, percent: true};
        assert.equal(breach(percent, 100.01), null);
        assert.equal(breach({...percent, max: 101.50}, 100.01).amount, 101.51);
    });
    test(`${file}: three-decimal fixed fee matches backend truncation`, () => {
        assert.equal(breach({min: 11, max: 11, cost: 1.005, percent: false}, 10), null);
        assert.equal(breach({min: 11.01, max: 0, cost: 1.005, percent: false}, 10).over, false);
        assert.equal(breach({min: 0, max: 10, cost: 0.009, percent: false}, 10), null);
    });
    test(`${file}: recharge principal truncates to cents`, () => {
        assert.equal(breach({min: 10, max: 10, cost: 0, percent: false}, 10.009), null);
    });
}

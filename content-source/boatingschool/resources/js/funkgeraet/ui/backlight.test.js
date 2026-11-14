import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createBacklight } from './backlight.js';

function fakeNode(attrs = {}) {
    const a = { ...attrs };
    return {
        attrs: a,
        setAttribute(name, value) { a[name] = String(value); },
        removeAttribute(name) { delete a[name]; },
    };
}

test('Beleuchtung schaltet die angemeldeten Attribute zwischen an und aus', () => {
    const light = createBacklight();
    const label = light.add(fakeNode({ fill: '#ffe9bf', filter: 'url(#glow)' }), { fill: '#ffe9bf', filter: 'url(#glow)' }, { fill: '#8d9198', filter: null });
    assert.equal(light.lit, true);

    light.set(false);
    assert.equal(light.lit, false);
    assert.deepEqual(label.attrs, { fill: '#8d9198' });

    light.set(true);
    assert.deepEqual(label.attrs, { fill: '#ffe9bf', filter: 'url(#glow)' });
});

test('Nach dem Ausschalten angemeldete Teile werden sofort dunkel', () => {
    const light = createBacklight();
    light.set(false);
    const rim = light.add(fakeNode({ stroke: '#f3d9a0' }), { stroke: '#f3d9a0' }, { stroke: '#2a2c30' });
    assert.equal(rim.attrs.stroke, '#2a2c30');
});

test('Gleicher Zustand löst kein erneutes Setzen aus', () => {
    const light = createBacklight();
    let calls = 0;
    const node = fakeNode();
    node.setAttribute = () => { calls++; };
    light.add(node, { fill: 'a' }, { fill: 'b' });
    light.set(true);
    assert.equal(calls, 0);
    light.set(false);
    light.set(false);
    assert.equal(calls, 1);
});

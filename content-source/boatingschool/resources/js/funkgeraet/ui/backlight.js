// Hintergrundbeleuchtung der Gerätezeichnungen: Ist das Gerät eingeschaltet,
// leuchten Tastenbeschriftungen, Tastenränder und Display wie bei den
// Originalgeräten; ausgeschaltet bleiben sie dunkel. Jeder Renderer meldet
// die betroffenen Teile mit ihren Attributen für „an“ und „aus“ an; set()
// schaltet nur bei einer Änderung um. Gezeichnet wird im Zustand „an“.

export function createBacklight() {
    const parts = [];
    let lit = true;

    const apply = (node, attrs) => {
        for (const [name, value] of Object.entries(attrs)) {
            if (value === null) node.removeAttribute(name);
            else node.setAttribute(name, value);
        }
    };

    return {
        add(node, on, off) {
            parts.push({ node, on, off });
            if (! lit) apply(node, off);

            return node;
        },
        set(value) {
            const next = Boolean(value);
            if (next === lit) return;
            lit = next;
            for (const { node, on, off } of parts) apply(node, lit ? on : off);
        },
        get lit() {
            return lit;
        },
    };
}

// Weicher Lichtschein um leuchtende Beschriftungen.
export function glowFilter(defs, id, NS = 'http://www.w3.org/2000/svg', strength = 1.6) {
    const filter = document.createElementNS(NS, 'filter');
    for (const [k, v] of Object.entries({ id, x: '-40%', y: '-40%', width: '180%', height: '180%' })) filter.setAttribute(k, v);
    const blur = document.createElementNS(NS, 'feGaussianBlur');
    blur.setAttribute('in', 'SourceGraphic');
    blur.setAttribute('stdDeviation', strength);
    blur.setAttribute('result', 'glow');
    const merge = document.createElementNS(NS, 'feMerge');
    for (const input of ['glow', 'SourceGraphic']) {
        const node = document.createElementNS(NS, 'feMergeNode');
        node.setAttribute('in', input);
        merge.appendChild(node);
    }
    filter.append(blur, merge);
    defs.appendChild(filter);

    return filter;
}

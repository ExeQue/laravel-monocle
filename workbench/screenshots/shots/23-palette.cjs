// The command palette (Ctrl K): quick actions, the open capture's tabs, then captures by kind.
const captures = require('./_captures.cjs');
const { openPalette } = require('./_helpers.cjs');

module.exports = [
    {
        name: '23-palette',
        capture: captures.mixed,
        tab: 'overview',
        prepare: (page) => openPalette(page),
    },
    {
        name: '24-palette-search',
        capture: captures.mixed,
        tab: 'overview',
        prepare: (page) => openPalette(page, '#req 404 | #job'),
    },
];

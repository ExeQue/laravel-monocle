// A query folded out: SQL with bindings, origin and call chain.
const captures = require('./_captures.cjs');
const { expandRow } = require('./_helpers.cjs');

module.exports = {
    name: '15-query-details',
    capture: captures.showcase,
    tab: 'queries',
    prepare: (page) => expandRow(page, 'WITH RECURSIVE'),
};

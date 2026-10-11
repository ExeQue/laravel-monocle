// A reported exception folded out: message, frames and where it was thrown.
const captures = require('./_captures.cjs');
const { expandRow } = require('./_helpers.cjs');

module.exports = {
    name: '19-exception-details',
    capture: captures.showcase,
    tab: 'exceptions',
    prepare: (page) => expandRow(page, 'Search index'),
};

// A Prism call folded out: the text call with a tool, its prompt, response, tokens and the tools it offered.
const captures = require('./_captures.cjs');
const { expandRow } = require('./_helpers.cjs');

module.exports = {
    name: '57-lens-prism-details',
    capture: captures.showcase,
    tab: 'prism',
    prepare: (page) => expandRow(page, 'What should I pack'),
};

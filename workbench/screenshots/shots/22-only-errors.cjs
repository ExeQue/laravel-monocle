// The capture list filtered to captures with errors.
const captures = require('./_captures.cjs');

module.exports = {
    name: '22-only-errors',
    capture: captures.showcase,
    tab: 'overview',
    prepare: (page) => page.click('#monocle-errors'),
};

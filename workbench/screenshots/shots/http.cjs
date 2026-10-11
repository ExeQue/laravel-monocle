// Outgoing HTTP calls, including failures.
const captures = require('./_captures.cjs');

module.exports = {
    name: 'http',
    capture: captures.showcase,
    tab: 'http',
};

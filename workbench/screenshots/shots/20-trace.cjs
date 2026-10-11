// A request and the jobs it set off.
const captures = require('./_captures.cjs');

module.exports = {
    name: '20-trace',
    capture: captures.countdown,
    tab: 'trace',
};

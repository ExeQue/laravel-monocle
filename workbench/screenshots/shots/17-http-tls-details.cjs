// A call that fails on an expired certificate (expired.badssl.com) folded out: the cURL error and what was sent.
const captures = require('./_captures.cjs');
const { expandRow } = require('./_helpers.cjs');

module.exports = {
    name: '17-http-tls-details',
    capture: captures.showcase,
    tab: 'http',
    prepare: (page) => expandRow(page, 'badssl'),
};

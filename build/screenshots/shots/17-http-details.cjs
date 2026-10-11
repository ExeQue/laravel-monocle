// A real call (httpbin.org) folded out: request, response and the timing phases of the connection.
const captures = require('./_captures.cjs');
const { expandRow } = require('./_helpers.cjs');

module.exports = {
    name: '17-http-details',
    capture: captures.showcase,
    tab: 'http',
    prepare: (page) => expandRow(page, 'httpbin'),
};

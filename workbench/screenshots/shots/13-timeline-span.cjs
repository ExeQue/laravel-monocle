// The popover of a span in the timeline: its meta, origin and timing.
const captures = require('./_captures.cjs');
const { clickText } = require('./_helpers.cjs');

module.exports = {
    name: '13-timeline-span',
    capture: captures.showcase,
    tab: 'timeline',
    prepare: (page) => clickText(page, 'publish post'),
};

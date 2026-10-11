// An agent run folded out: the trip planner with its prompt, tokens, the error that ended it and its tool calls, a sub-agent among them.
const captures = require('./_captures.cjs');
const { expandRow } = require('./_helpers.cjs');

module.exports = {
    name: '56-lens-ai-details',
    capture: captures.showcase,
    tab: 'ai',
    prepare: (page) => expandRow(page, 'TripPlanner'),
};

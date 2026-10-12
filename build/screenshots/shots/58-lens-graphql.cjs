// A GraphQL operation: the GraphQL tab, the operation folded out (fields with their resolvers and
// definitions, the coloured query, variables and errors), and the Request tab's payload and response cards.
const captures = require('./_captures.cjs');
const { expandRow, scrollToHeading } = require('./_helpers.cjs');

module.exports = [
    {
        name: '58-lens-graphql',
        capture: captures.graphql,
        tab: 'graphql',
    },
    {
        name: '58-lens-graphql-details',
        capture: captures.graphql,
        tab: 'graphql',
        prepare: (page) => expandRow(page, 'Feed'),
    },
    {
        name: '58-lens-graphql-payload',
        capture: captures.graphql,
        tab: 'request',
        prepare: (page) => scrollToHeading(page, 'Payload'),
    },
    {
        name: '58-lens-graphql-response',
        capture: captures.graphql,
        tab: 'request',
        prepare: (page) => scrollToHeading(page, 'Response'),
    },
];

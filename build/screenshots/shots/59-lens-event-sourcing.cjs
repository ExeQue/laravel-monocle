// spatie/laravel-event-sourcing: the Event sourcing tab with its aggregates, and a stored event folded out.
const captures = require('./_captures.cjs');
const { expandRow } = require('./_helpers.cjs');

module.exports = [
    {
        name: '59-lens-event-sourcing',
        capture: captures.eventSourcing,
        tab: 'event_sourcing',
    },
    {
        name: '59-lens-event-sourcing-details',
        capture: captures.eventSourcing,
        tab: 'event_sourcing',
        prepare: (page) => expandRow(page, 'MoneyDeposited'),
    },
];

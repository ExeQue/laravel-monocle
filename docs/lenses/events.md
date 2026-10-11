<!-- Part of the Monocle documentation - see README.md -->

# Events

Part of [what gets recorded](../lenses.md).

![The Events tab](../images/36-lens-events-light.webp#gh-light-mode-only)
![The Events tab](../images/36-lens-events-dark.webp#gh-dark-mode-only)

Section `events`: your application's events with their payload, listeners and whether they broadcast. Framework events are left out.

Option: `ignore` - event names or patterns to leave out. The default is `Illuminate\*`, `Laravel\*`, `Livewire\*`, `eloquent.*`, `composing:*`, `creating:*`, `bootstrapping:*`, `bootstrapped:*` and `locale.*`. Setting the option replaces this list, so include the defaults you want to keep.

<!-- Part of the Monocle documentation - see README.md -->

# Livewire

Part of [what gets recorded](../lenses.md).

![A Livewire update](../images/21-livewire-light.webp#gh-light-mode-only)
![A Livewire update](../images/21-livewire-dark.webp#gh-dark-mode-only)

Section `livewire`: every component in the request - whether it was mounted or updated, the methods called, properties changed, render time and any exception. A component that threw an exception is reported as an error.

Needs `livewire/livewire`.

The response of a Livewire update is shown as one card per component on the Request tab: the methods it called with their parameters, the properties that changed, the events it dispatched and what it returned.

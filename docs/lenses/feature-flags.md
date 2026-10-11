<!-- Part of the Monocle documentation - see README.md -->

# Feature flags

Part of [what gets recorded](../lenses.md).

![The Features tab](../images/53-lens-pennant-light.webp#gh-light-mode-only)
![The Features tab](../images/53-lens-pennant-dark.webp#gh-dark-mode-only)

Section `features`: every feature check - the feature, its scope, the value and whether it came from storage or was resolved just now - plus updates and deletions. A feature that is not defined gives a warning, since it quietly resolves to false.

Needs `laravel/pennant`.

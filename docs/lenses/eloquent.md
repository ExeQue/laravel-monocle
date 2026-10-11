<!-- Part of the Monocle documentation - see README.md -->

# Eloquent

Part of [what gets recorded](../lenses.md).

![The Models tab](../images/34-lens-eloquent-light.webp#gh-light-mode-only)
![The Models tab](../images/34-lens-eloquent-dark.webp#gh-dark-mode-only)

Sections `models` and `model_changes`: how many models of each class were retrieved, created, updated, deleted, restored and force deleted, and every write with each changed attribute as `field`, `from` and `to`. Hidden attributes are left out. A write made in a transaction that was rolled back is marked `rolled_back`.

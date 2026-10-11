<!-- Part of the Monocle documentation - see README.md -->

# Dumps

Part of [what gets recorded](../lenses.md).

![The Dumps tab](../images/47-lens-dumps-light.webp#gh-light-mode-only)
![The Dumps tab](../images/47-lens-dumps-dark.webp#gh-dark-mode-only)

Section `dumps`: every `dump()` and `dd()` with the value and where it was called. You still see the output as usual. A capture ended by `dd()` is still stored, marked as ended at shutdown.

A dump folds out to its value, coloured, with every class it names linked to your editor. Passwords and tokens of users are masked, and so is anything listed under `mask.objects` (see [Configuration](../configuration.md#masking)).

Warning: `dd()` ended the process before the response was sent.

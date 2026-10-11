<!-- Part of the Monocle documentation - see README.md -->

# Views

Part of [what gets recorded](../lenses.md).

![The Views tab](../images/39-lens-views-light.webp#gh-light-mode-only)
![The Views tab](../images/39-lens-views-dark.webp#gh-dark-mode-only)

Section `views`: every rendered view and component with its duration (including the views it renders) and the names of the variables passed to it. Nested views appear nested in the timeline, so a partial rendered in a loop is easy to spot.

Warning: a view rendered many times.

Options: `engines` (`['blade', 'php', 'file']`) - the view engines to time; `data` (false) - also record the variable values; `repeat_threshold` (10) and `repeat_ms` (20) - warn when a view is rendered at least this often and takes at least this long in all.

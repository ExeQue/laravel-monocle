<!-- Part of the Monocle documentation - see README.md -->

# Session

Part of [what gets recorded](../lenses.md).

![The Session tab](../images/31-lens-session-light.webp#gh-light-mode-only)
![The Session tab](../images/31-lens-session-dark.webp#gh-dark-mode-only)

Section `session`: what the session held once the request was handled - the data (secret-looking keys and the CSRF token masked), what the request changed in it (each key with its value before and after), the keys flashed for the next request, the driver and a short hash of the session id.

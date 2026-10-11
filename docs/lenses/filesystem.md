<!-- Part of the Monocle documentation - see README.md -->

# Filesystem

Part of [what gets recorded](../lenses.md).

![The Filesystem tab](../images/46-lens-filesystem-light.webp#gh-light-mode-only)
![The Filesystem tab](../images/46-lens-filesystem-dark.webp#gh-dark-mode-only)

Section `filesystem`: operations on `Storage` disks - reads, writes, deletes, copies, moves, listings and so on - with path, size, duration and failures.

Nothing is recorded until you choose the disks:

```php
// config/monocle.php
FilesystemLens::class => ['disks' => ['media', 'uploads-*']],   // or ['*'] for every disk
```

Plain PHP file functions like `file_put_contents()` are not recorded.

Error: an operation that failed.

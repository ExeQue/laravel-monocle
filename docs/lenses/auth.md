<!-- Part of the Monocle documentation - see README.md -->

# Auth

Part of [what gets recorded](../lenses.md).

![The Request tab with the auth events](../images/48-lens-auth-light.webp#gh-light-mode-only)
![The Request tab with the auth events](../images/48-lens-auth-dark.webp#gh-dark-mode-only)

Section `auth`: who the request was authenticated as and how - the guard that resolved the user, logins, logouts, failed attempts and lockouts, registrations, verifications and password resets. Credentials are recorded as the keys that were tried, never their values. The summary shows the user, failed attempts and lockouts. With [Fortify](auth.md#fortify), [Sanctum](auth.md#sanctum) or an [impersonation](auth.md#impersonation) package installed, their rows are added to this section too.

Warning: a login locked out after too many attempts.

## Impersonation

With lab404/laravel-impersonate, stechstudio/filament-impersonate, Laravel Nova or franbarbalopez/mirror, section `auth` also records an impersonation starting and ending, with the impersonated user and who impersonated them. While an impersonation lasts, the authenticated user is marked with the id and guard of the user impersonating them, the summary shows it, and the Request tab shows it next to the user. No user is loaded from the database to find out. The capture is tagged "impersonated by ..." in the dashboard's header and list.

Lenses: `Lab404\ImpersonateLens`, `STS\FilamentImpersonateLens`, `Laravel\NovaLens` and `Mirror\MirrorLens`.

## Fortify

Section `auth` also records Fortify's two-factor login: when a user is asked for a code, wrong codes and the code that let them in, plus two-factor being turned on, confirmed or off, recovery codes being generated or used, and password changes. The summary counts failed codes. Recovery codes themselves are never recorded.

Needs `laravel/fortify`. Lens: `Laravel\FortifyLens`.

## Sanctum

Section `auth` also records the Sanctum token a request authenticated with: its id, name, abilities and owner.

Needs `laravel/sanctum`. Lens: `Laravel\SanctumLens`.

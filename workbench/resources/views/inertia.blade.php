<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Inertia</title>
</head>
<body
    style="
        font:
            15px/1.5 system-ui,
            sans-serif;
        max-width: 60rem;
        margin: 2rem auto;
        padding: 0 1rem;
    "
>
    <p><a href="/">All demos</a> - <a href="/_monocle" target="_blank">Open the Monocle dashboard</a></p>
    <h1>Inertia</h1>
    <p>
        No JavaScript frontend is loaded; the page props are embedded below. Partial reloads need a request with the
        X-Inertia header.
    </p>
    @inertia
</body>
</html>

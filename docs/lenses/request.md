<!-- Part of the Monocle documentation - see README.md -->

# Request

Part of [what gets recorded](../lenses.md).

![The Request tab](../images/30-lens-request-light.webp#gh-light-mode-only)
![The Request tab](../images/30-lens-request-dark.webp#gh-dark-mode-only)

Section `request`: method, URL, query and input (secrets masked), uploaded files, headers (masked), cookies (the session cookie, the XSRF token, remember-me cookies and secret-looking names masked), IP, user agent, locale, the logged-in user, the matched route with its parameters and middleware, and response status, size and headers. Responses with a capture for their route, a response header or their content type are kept, both as the capture presents them and raw; other responses only have their size.

A response with a 5xx status is reported as an error.

Option: `capture` - which responses are kept, and how. By default:

| Key | Default |
|---|---|
| `response_types` | `application/json` and `application/*+json` with `JsonCapture`, `text/html` with `InertiaCapture` (only Inertia pages are kept) |
| `headers` | `X-Inertia` with `InertiaCapture` |
| `routes` | `*livewire.update` with `LivewireUpdateCapture` |

Each key replaces its default when you set it, so list the defaults you want to keep. Add `'text/plain' => TextCapture::class` to `response_types` to keep plain text as it is, or leave an entry out to stop keeping that type.

## Response captures

Some responses read better as something other than their raw body. Map a route name (wildcards allowed) to a class implementing `ExeQue\Monocle\Contracts\ResponseCapture` under `capture.routes`, or a response header under `capture.headers`. A route capture wins over a header capture, which wins over the content type capture. The dashboard shows the capture and the raw body side by side. Livewire updates and Inertia pages come set up - see [Packages](#packages).

```php
RequestLens::class => [
    'capture' => [
        'response_types' => [
            'application/json' => JsonCapture::class,
            'application/*+json' => JsonCapture::class,
            'text/html' => InertiaCapture::class,
        ],
        'headers' => [
            'X-Inertia' => InertiaCapture::class,
        ],
        'routes' => [
            '*livewire.update' => LivewireUpdateCapture::class,
            'api.orders.*' => OrderResponseCapture::class,
        ],
    ],
],
```

```php
use ExeQue\Monocle\Contracts\ResponseCapture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderResponseCapture implements ResponseCapture
{
    public function capture(Request $request, Response $response): ?array
    {
        $order = json_decode($response->getContent(), true);

        return [[
            'title' => 'Order '.$order['number'],
            'items' => ['status' => $order['status'], 'lines' => count($order['lines'])],
        ]];
    }
}
```

See [Extending the request lens](../writing-a-lens.md#extending-the-request-lens) for writing your own.

The timeline splits the request into phases:

| Phase | Covers |
|---|---|
| `bootstrap` | starting the framework |
| `middleware (request)` | global middleware, before the request reaches the router |
| `routing` | finding the route |
| `handle` | route middleware, the controller and building the response |
| `controller` | the controller itself |
| `terminate` | work done after the response was sent |

## Folio

The page file a request matched is shown as the request's action, with the page name, mount path and parameters under `folio`. Built in - nothing to enable.

## Octane

Each request in an Octane worker is its own capture, starting before global middleware runs, so the middleware phase is complete. Built in - nothing to enable.

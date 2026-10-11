{{-- Method and url, route, action, user, ip, user agent and locale. --}}
<div class="grid gap-2">
    <h3 :class="cls.sectionTitle" class="mb-0">Request</h3>
    <p class="m-0 font-mono text-[13px] [overflow-wrap:anywhere]">
        <b x-text="request.method"></b> <span x-text="readableUrl(request.url)"></span>
        <span x-html="copyButton(request.url, 'url')"></span>
    </p>
    <dl class="m-0 grid gap-x-4 gap-y-1 text-[13px] sm:grid-cols-[160px_minmax(0,1fr)]">
        <template
            x-for="
                [k, v, path] in
                [
                    ['Route', request.route?.name ?? request.route?.uri, 'route.name'],
                    ['Action', request.route?.action, 'route.action'],
                ].filter(([, v]) => v)
            "
            :key="k"
        >
            <div class="contents">
                <dt class="text-muted" x-text="k"></dt>
                <dd
                    class="group/copy m-0 font-mono text-xs [overflow-wrap:anywhere]"
                    x-html="linked(v, path) + ' ' + copyButton(String(v), k.toLowerCase(), { hover: true })"
                ></dd>
            </div>
        </template>
        <template x-if="authUser">
            <div class="contents">
                <dt class="text-muted">User</dt>
                <dd class="m-0 font-mono text-xs [overflow-wrap:anywhere]">
                    <span x-html="maybeLink(authUser.user, authUser.link)"></span>
                    <span class="text-muted" x-text="'via ' + authUser.via"></span>
                </dd>
            </div>
        </template>
        <template
            x-for="
                [k, v, path] in
                [
                    ['IP', request.ip, 'ip'],
                    ['User agent', request.user_agent, 'user_agent'],
                    ['Locale', request.locale, 'locale'],
                ].filter(([, v]) => v)
            "
            :key="k"
        >
            <div class="contents">
                <dt class="text-muted" x-text="k"></dt>
                <dd
                    class="group/copy m-0 font-mono text-xs [overflow-wrap:anywhere]"
                    x-html="linked(v, path) + ' ' + copyButton(String(v), k.toLowerCase(), { hover: true })"
                ></dd>
            </div>
        </template>
    </dl>
</div>

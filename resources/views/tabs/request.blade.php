{{-- Request and response: facts, query, payload, files, headers, cookies, the response body and its capture. --}}
<x-monocle::panel
    name="request"
    class="grid gap-6"
    x-data="{
        responseView: 'pretty',
        get request() {
            return tab === 'request' ? (tabData?.data ?? null) : null
        },
        get authRows() {
            return tabData?.included?.auth ?? []
        },
        {{-- The user the request ran as, from the auth section. --}}
        get authUser() {
            const row = [...this.authRows].reverse().find((r) => ['authenticated', 'login', 'token'].includes(r.event) && (r.user || r.owner))
            if (! row) return null
            return {
                user: row.user ?? row.owner,
                link: row._links?.user ?? row._links?.owner,
                via: row.event === 'token' ? `Sanctum token ${row.token_name ?? row.token_id}` : `${row.guard} guard`,
            }
        },
        {{-- Auth events worth listing next to the request: attempts, failures, logins, lockouts. --}}
        get authEvents() {
            return this.authRows.filter((r) => r.event !== 'authenticated')
        },
    }"
>
    <template x-if="request">
        <div class="grid gap-6">
            <x-monocle::request.facts />

            <x-monocle::request.authentication />

            <x-monocle::section title="Query" show="dot(request.query).length">
                <x-monocle::kv-table rows="dot(request.query)" />
            </x-monocle::section>

            <x-monocle::section title="Payload" show="dot(request.input).length || request.files?.length">
                <x-monocle::kv-table rows="dot(request.input)" x-show="dot(request.input).length" />
                <div class="mt-3" :class="cls.tableWrap" x-show="request.files?.length">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th scope="col" :class="cls.th">File field</th>
                                <th scope="col" :class="cls.th">Name</th>
                                <th scope="col" :class="cls.th">Type</th>
                                <th scope="col" :class="cls.th" class="text-right">Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="f in request.files ?? []" :key="f.key + f.name">
                                <tr>
                                    <td :class="cls.td" class="font-mono text-xs" x-text="f.key"></td>
                                    <td :class="cls.td" x-text="f.name"></td>
                                    <td :class="cls.td" class="text-muted text-xs" x-text="f.mime"></td>
                                    <td :class="cls.td" class="text-right tabular-nums" x-text="f.size"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </x-monocle::section>

            <x-monocle::section title="Request headers">
                <x-monocle::kv-table rows="headers(request.headers)" keys="header" value="esc(v)" />
            </x-monocle::section>

            <x-monocle::section title="Cookies" show="dot(request.cookies).length">
                <x-monocle::kv-table rows="dot(request.cookies)" keys="header" value="esc(String(v ?? ''))" />
            </x-monocle::section>

            <x-monocle::request.response />
        </div>
    </template>
</x-monocle::panel>

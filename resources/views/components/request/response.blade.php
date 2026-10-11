{{-- The response: status, type and size, headers, and the body as cards or raw. --}}
<div class="grid gap-3">
    <h3 :class="cls.sectionTitle" class="mb-0">Response</h3>
    <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
        <span class="font-mono" :class="statusClass(request.status)" x-text="request.status"></span>
        <span class="text-muted" x-text="request.content_type"></span>
        <span
            class="text-muted tabular-nums"
            x-show="request.response_bytes != null"
            x-text="totalValue(['response_bytes', request.response_bytes, 'bytes'])"
        ></span>
        <span x-show="request.redirect">Redirects to <span class="font-mono" x-text="request.redirect"></span></span>
    </p>
    <x-monocle::kv-table rows="headers(request.response_headers)" keys="header" value="esc(v)" />
    <div class="flex gap-1.5" x-show="request.response_capture?.raw">
        <template
            x-for="
                v in
                [
                    ['pretty', 'Pretty'],
                    ['raw', 'Raw'],
                ]
            "
            :key="v[0]"
        >
            <button
                type="button"
                :class="cls.chip"
                @click="responseView = v[0]"
                :aria-pressed="responseView === v[0]"
                x-text="v[1]"
            ></button>
        </template>
        <span class="ml-auto" x-show="request.response_capture?.raw">
            <x-monocle::copy bind="request.response_capture?.raw ?? ''" what="response body" />
        </span>
    </div>
    <pre
        class="scroll-quiet border-line bg-panel m-0 max-h-[480px] overflow-auto rounded-md border p-3 font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap"
        x-show="responseView === 'raw' && request.response_capture?.raw"
        x-text="body(request.response_capture?.raw, request.content_type)"
    ></pre>
    <div
        x-show="request.response_capture && responseView === 'pretty'"
        x-html="request.response_capture ? cardsHtml(request.response_capture.cards) : ''"
    ></div>
</div>

{{-- The open capture's name, status, facts, copy buttons, trace links and section tabs. --}}
<header class="flex flex-wrap items-center gap-x-3 gap-y-2.5">
    <h2 class="m-0 min-w-0 text-2xl font-bold text-balance [overflow-wrap:anywhere]" x-text="summary.name"></h2>
    <span
        class="font-mono text-xs"
        :class="statusClass(summary.status)"
        x-text="
            summary.type === 'command' && summary.status !== null ? 'exit ' + summary.status : (summary.status ?? '')
        "
    ></span>
    <span
        class="border-line text-muted rounded-full border px-2 font-mono text-xs"
        x-show="capture.context?.route"
        x-text="capture.context?.route"
        data-tip="Route name"
    ></span>
    <span
        class="border-brass text-brass rounded-full border border-dashed px-2 text-xs"
        x-show="summary.label"
        x-text="'label: ' + summary.label"
    ></span>
</header>
<div class="text-muted flex flex-wrap items-center gap-x-[18px] gap-y-1.5 text-[13px]">
    <span
        ><b class="text-fg font-semibold tabular-nums" x-text="ms(summary.totals?.duration_ms ?? model?.duration)"></b>
        total</span>
    <span x-text="summary.type"></span>
    <span
        class="font-mono text-xs [overflow-wrap:anywhere]"
        x-show="capture.context?.action && capture.context.action !== 'Closure'"
        x-html="linkHtml(capture.context?.action, capture.context?.action_link)"
    ></span>
    <span
        class="font-mono text-xs [overflow-wrap:anywhere]"
        x-show="capture.context?.command_class"
        x-html="linkHtml(capture.context?.command_class, capture.context?.command_link)"
    ></span>
    <span class="inline-flex items-center gap-1">
        <span class="font-mono text-xs select-all" x-text="capture.id"></span>
        <button
            type="button"
            class="text-muted hover:text-brass rounded p-0.5"
            @click="copy(capture.id, 'id')"
            :data-tip="copied === 'id' ? 'Copied' : 'Copy id'"
            :aria-label="copied === 'id' ? 'Copied' : 'Copy capture id'"
        >
            <svg
                x-show="copied !== 'id'"
                class="size-3.5"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <rect x="5.5" y="5.5" width="8" height="8" rx="1.5" />
                <path d="M10.5 5.5V3.5A1.5 1.5 0 0 0 9 2H3.5A1.5 1.5 0 0 0 2 3.5V9a1.5 1.5 0 0 0 1.5 1.5h2" />
            </svg>
            <svg
                x-show="copied === 'id'"
                x-cloak
                class="text-ok size-3.5"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            ><path d="M3 8.5 6.5 12 13 4.5" /></svg>
        </button>
    </span>
    <span x-text="summary.started_at ? ago(summary.started_at) : ''"></span>
    <button
        type="button"
        class="border-line text-muted hover:border-brass hover:text-brass ml-auto inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs"
        @click="copy(agentPrompt, 'prompt')"
        data-tip="Copy a prompt that asks your coding agent to look into this capture through the Monocle MCP server"
    >
        <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z" /></svg>
        <span x-text="copied === 'prompt' ? 'Copied' : 'Copy agent prompt'"></span>
    </button>
</div>
<div class="flex flex-wrap gap-2 text-[13px]" x-show="summary.parent_id || summary.children?.length">
    <span x-show="summary.parent_id"
        >Dispatched by
        <button type="button" :class="cls.link" @click="select(summary.parent_id)">parent capture</button></span>
    <span x-show="summary.children?.length"
        >Dispatched
        <button
            type="button"
            :class="cls.link"
            @click="openTab('trace')"
            x-text="summary.children?.length + ' job(s)'"
        ></button
    ></span>
</div>

<nav
    class="scroll-quiet border-line flex snap-x snap-mandatory scroll-px-2 gap-0.5 overflow-x-auto border-b"
    role="tablist"
    aria-label="Capture sections"
    x-ref="tabs"
>
    <template x-for="t in tabs" :key="t.key">
        <button
            type="button"
            class="group text-muted aria-selected:border-brass aria-selected:text-fg flex shrink-0 snap-start items-center gap-1.5 border-b-2 border-transparent px-3 py-2 whitespace-nowrap aria-selected:font-semibold"
            role="tab"
            :id="'monocle-tab-' + t.key"
            :aria-controls="panelId(t.key)"
            :tabindex="tab === t.key ? 0 : -1"
            :data-tip="t.description ?? ''"
            @click="openTab(t.key)"
            @keydown.right.prevent="stepTab(1)"
            @keydown.left.prevent="stepTab(-1)"
            :aria-selected="tab === t.key"
        >
            <span
                class="grid after:invisible after:h-0 after:overflow-hidden after:font-semibold after:content-[attr(data-label)]"
                :data-label="t.label"
                x-text="t.label"
            ></span>
            <span class="relative inline-flex" x-show="t.count">
                <x-monocle::badge
                    count="t.count"
                    ::class="
                        t.errors
                            ? 'bg-bad-soft text-bad font-bold'
                            : t.problems
                              ? 'bg-warn-soft text-warn font-bold'
                              : 'bg-sunk text-muted group-aria-selected:bg-brass group-aria-selected:text-on-brass'
                    "
                    ::data-tip="problemTip(t)"
                />
                <span
                    class="bg-warn ring-bg absolute -top-0.5 -right-0.5 size-1.5 rounded-full ring-2"
                    x-show="t.errors && t.problems"
                ></span>
            </span>
            <span
                class="size-1.5 rounded-full"
                :class="t.errors ? 'bg-bad' : 'bg-warn'"
                x-show="! t.count && (t.errors || t.problems)"
                :data-tip="problemTip(t)"
                :aria-label="problemTip(t)"
            ></span>
        </button>
    </template>
</nav>

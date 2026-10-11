{{-- Calls to the application's own MCP servers. --}}
<x-monocle::panel name="mcp">
    <template x-if="tab === 'mcp'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>

{{-- Authentication events during the request: logins, failures, logouts, tokens. --}}
<div x-show="authEvents.length">
    <h3 :class="cls.sectionTitle">Authentication</h3>
    <div :class="cls.tableWrap">
        <table class="w-full border-collapse text-[13px]">
            <thead>
                <tr>
                    <th scope="col" :class="cls.th">Event</th>
                    <th scope="col" :class="cls.th">Guard</th>
                    <th scope="col" :class="cls.th">User</th>
                    <th scope="col" :class="cls.th">Details</th>
                    <th scope="col" :class="cls.th" class="w-0 whitespace-nowrap">Origin</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, i) in authEvents" :key="i">
                    <tr class="hover:bg-sunk cursor-pointer" @click="rowPopover($event, row, 'auth')">
                        <td
                            :class="cls.td"
                            :class="['failed', 'lockout'].includes(row.event) && 'text-warn font-medium'"
                            x-text="row.event"
                        ></td>
                        <td :class="cls.td" x-text="row.guard ?? ''"></td>
                        <td :class="cls.td" x-html="cell(row, 'user')"></td>
                        <td
                            :class="cls.td"
                            class="font-mono text-xs"
                            x-text="
                                [
                                    row.credentials?.length ? 'tried ' + row.credentials.join(', ') : null,
                                    row.remember ? 'remember' : null,
                                    row.ip,
                                    row.abilities?.length ? 'abilities ' + row.abilities.join(', ') : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            "
                        ></td>
                        <td :class="cls.td" class="w-0 whitespace-nowrap" x-html="cell(row, 'origin')"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>

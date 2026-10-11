import { ms, humanize } from "../format.js";

/** A total that counts failures, errors or misses and is above 0 shows in red. */
export function totalTone([k, v]) {
    return typeof v === "number" &&
        v > 0 &&
        /(failed|errors|exceptions|denied|declined|warnings)$/.test(k)
        ? "text-bad"
        : "";
}

export function totalValue([k, v]) {
    if (k.endsWith("_ms")) return ms(v);
    if (k.endsWith("_rate")) return v + " %";
    if (/_bytes(_|$)/.test(k))
        return v >= 1048576
            ? (v / 1048576).toFixed(1) + " MB"
            : v >= 1024
              ? Math.round(v / 1024) + " KB"
              : v + " B";
    if (k.endsWith("_mb")) return v + " MB";
    return String(v);
}

export function totalLabel(k, group = null) {
    const labels = {
        duration_ms: "Duration",
        query_ms: "Query time",
        cache_ms: "Cache time",
        redis_ms: "Redis time",
        views_ms: "View time",
        middleware_self_ms: "Middleware time",
        memory_peak_mb: "Peak memory",
        response_bytes: "Response size",
        cache_hit_rate: "Cache hit rate",
    };
    if (group && group !== "Overall" && k.endsWith("_ms")) return "Time";
    const label =
        labels[k] ??
        humanize(k.replace(/_(ms|mb)$/, "").replace(/_bytes(?=_|$)/, ""));
    const [first, ...rest] = label.split(" ");
    const singular = group?.toLowerCase().replace(/s$/, "");
    return group && rest.length && first.toLowerCase().startsWith(singular)
        ? humanize(rest.join(" "))
        : label;
}

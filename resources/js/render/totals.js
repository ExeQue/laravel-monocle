import { ms, humanize } from "../format.js";

/** A total that counts failures, errors or misses and is above 0 shows in red. */
export function totalTone([k, v]) {
    return typeof v === "number" &&
        v > 0 &&
        /(failed|errors|exceptions|denied|declined|warnings)$/.test(k)
        ? "text-bad"
        : "";
}

/** Formats a total by the unit its lens gave it; any other unit is shown after the value. */
export function totalValue([, v, unit]) {
  if (unit === 'ms') return ms(v);
  if (unit === 'percent') return v + ' %';
  if (unit === 'bytes') return v >= 1048576 ? (v / 1048576).toFixed(1) + ' MB' : v >= 1024 ? Math.round(v / 1024) + ' KB' : v + ' B';
  if (unit === 'mb') return v + ' MB';
  if (unit === 'usd') return '$' + (v > 0 && v < 0.01 ? v.toFixed(4) : v.toFixed(2));
  return unit ? `${v} ${unit}` : String(v);
}

/** The label its lens gave the total, or its name without the unit. */
export function totalLabel([k, , , label]) {
  return label ?? humanize(k.replace(/_(ms|mb|rate)$/, '').replace(/_bytes(?=_|$)/, '')).toLowerCase();
}

/** The label as a caption: capitalised, except a package name such as laravel/framework. */
export function totalTitle(total) {
  const label = totalLabel(total);
  return label.includes('/') ? label : label.charAt(0).toUpperCase() + label.slice(1);
}

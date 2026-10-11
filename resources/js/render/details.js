import { rowBody } from '../popover.js';

/**
 * The details of an expanded row, for sections whose lens has no details view: every field.
 * (Lenses with a details view are rendered on the server.)
 */
export function details(row) {
  return rowBody(row);
}

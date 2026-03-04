/** Diameter of the SVG viewBox (px). */
const RING_SIZE = 18;

/** Radius of the circular progress track. */
const RING_R = 7;

/** Full circumference of the circle – used for dash calculations. */
const RING_C = 2 * Math.PI * RING_R;

/**
 * Returns an inline SVG string for a circular progress indicator.
 *
 * - When `pct` is a number (0–100) the ring fills proportionally.
 * - When `pct` is `null` or `undefined` the ring renders in an
 *   indeterminate (spinning) state.
 *
 * @param {number|null|undefined} pct - Progress percentage, or
 *   null/undefined for an indeterminate spinner.
 * @returns {string} An SVG markup string ready for `innerHTML`.
 */
export function progressRingHTML(pct) {

    const indeterminate = (pct === null || pct === undefined);

    // Add the "indeterminate" class so CSS can apply a spin animation
    const cls = indeterminate
        ? 'progress-ring indeterminate'
        : 'progress-ring';

    // Indeterminate: short dash + long gap to create a spinning arc.
    // Determinate:   full circumference — offset controls the fill.
    const dasharray = indeterminate
        ? `${(RING_C * 0.28).toFixed(2)} ${(RING_C * 0.72).toFixed(2)}`
        : RING_C.toFixed(2);

    // Offset shrinks as progress increases, revealing more of the stroke
    const dashoffset = indeterminate
        ? '0'
        : (RING_C * (1 - Math.min(pct, 100) / 100)).toFixed(2);

    return `
        <svg class="${cls}" width="${RING_SIZE}" height="${RING_SIZE}" viewBox="0 0 ${RING_SIZE} ${RING_SIZE}">
            <circle class="progress-ring-bg" cx="9" cy="9" r="${RING_R}"/>
            <circle class="progress-ring-value" cx="9" cy="9" r="${RING_R}"
                stroke-dasharray="${dasharray}"
                stroke-dashoffset="${dashoffset}"/>
        </svg>
    `;
}

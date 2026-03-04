/**
 * Manages a horizontal progress bar that is lazily inserted into the DOM
 * directly after a given status element.
 */
export class ProgressBar {

    /**
     * @param {HTMLElement} statusElement - The element after which the
     *   progress bar will be inserted in the DOM.
     */
    constructor(statusElement) {
        this.statusElement = statusElement;
        this.bar = null;
        this.fill = null;
    }

    /**
     * Lazily creates the progress-bar DOM nodes and inserts them right
     * after the status element.  Subsequent calls are no-ops.
     */
    ensureExists() {

        if (this.bar) return;

        // Outer container
        this.bar = document.createElement('div');
        this.bar.className = 'progress-bar';

        // Inner fill whose width represents the current percentage
        this.fill = document.createElement('div');
        this.fill.className = 'progress-fill';

        this.bar.appendChild(this.fill);

        // Place the bar immediately after the status text
        this.statusElement.parentNode.insertBefore(
            this.bar,
            this.statusElement.nextSibling
        );
    }

    /**
     * Shows the progress bar and sets it to the given percentage.
     * Values above 100 are clamped.
     *
     * @param {number} pct - Progress percentage (0–100).
     */
    set(pct) {

        this.ensureExists();

        this.bar.style.display = 'block';
        this.fill.style.width = Math.min(pct, 100) + '%';
    }

    /**
     * Hides the progress bar and resets the fill to 0 %.
     */
    hide() {

        if (!this.bar) return;

        this.bar.style.display = 'none';
        this.fill.style.width = '0%';
    }

}

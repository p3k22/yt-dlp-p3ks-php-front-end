/**
 * Controls mutually-exclusive toggle-button rows in the download form.
 *
 * Each `.toggle-row` contains several `.toggle-btn` elements; clicking one
 * deactivates the others so exactly one button per row is active at a time.
 * The controller also reacts to format changes (video vs. audio) by
 * showing / hiding the relevant option groups.
 */
export class ToggleController {

    /**
     * Wires up click handlers on every toggle row and listens for
     * format-row changes to swap visible option groups.
     */
    init() {

        // For each toggle row, make clicks mutually exclusive
        document.querySelectorAll('.toggle-row').forEach(row => {

            row.querySelectorAll('.toggle-btn').forEach(btn => {

                btn.addEventListener('click', () => {

                    // Deactivate all sibling buttons first
                    row.querySelectorAll('.toggle-btn')
                        .forEach(b => b.classList.remove('active'));

                    // Activate the clicked button
                    btn.classList.add('active');

                });

            });

        });

        // Listen to the format row to toggle quality/type panels
        document
            .getElementById('formatRow')
            .addEventListener('click', this.onFormatChange.bind(this));
    }

    /**
     * Handles a click inside the format toggle row.
     * Shows quality & video-type groups for video formats, or the
     * audio-type group for the audio format.
     *
     * @param {MouseEvent} e - The click event delegated from the format row.
     */
    onFormatChange(e) {

        const btn = e.target.closest('.toggle-btn');
        if (!btn) return;

        const val = btn.dataset.val;

        const qualityGroup = document.getElementById('qualityGroup');
        const videoTypeGroup = document.getElementById('videoTypeGroup');
        const audioTypeGroup = document.getElementById('audioTypeGroup');

        if (val === 'audio') {
            // Audio selected — hide video-specific options
            qualityGroup.style.display = 'none';
            videoTypeGroup.style.display = 'none';
            audioTypeGroup.style.display = 'block';

        }
        else {
            // Video selected — show quality & video type, hide audio type
            qualityGroup.style.display = 'block';
            videoTypeGroup.style.display = 'block';
            audioTypeGroup.style.display = 'none';

        }
    }

    /**
     * Returns the `data-val` of the currently active button in a given
     * toggle row, or `undefined` if none is active.
     *
     * @param {string} rowId - The DOM id of the toggle row.
     * @returns {string|undefined}
     */
    getActive(rowId) {

        return document
            .querySelector(`#${rowId} .toggle-btn.active`)
            ?.dataset.val;
    }

}

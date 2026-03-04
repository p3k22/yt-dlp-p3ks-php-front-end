import { escapeHtml } from '../utils/escapeHtml.js';
import { progressRingHTML } from '../ui/progressRing.js';
import { ProgressBar } from '../ui/progressBar.js';
import { DownloadClient } from '../network/downloadClient.js';

/**
 * Orchestrates a single media download from start to finish.
 *
 * Reads the user’s chosen format, quality, and file-type from the
 * toggle controls, opens an SSE connection to the PHP backend, and
 * updates the UI through every phase.
 */
export class DownloadController {

    /**
     * @param {import('../ui/toggleController.js').ToggleController} toggleController
     *   Provides access to the currently selected format / quality / type.
     */
    constructor(toggleController) {

        this.toggleController = toggleController;

        // Cache frequently-accessed DOM nodes
        this.status = document.getElementById('status');
        this.logBox = document.getElementById('logBox');
        this.btn = document.getElementById('goBtn');

        this.progressBar = new ProgressBar(this.status);
    }

    /**
     * Starts a new download.
     *
     * Reads the URL and toggle selections, resets the UI, opens an SSE
     * stream to the backend, and binds every server event to its handler.
     */
    start() {

        const url =
            document.getElementById('url').value.trim();

        if (!url) return;

        // Gather the user’s current toggle selections
        const format =
            this.toggleController.getActive('formatRow');

        const quality =
            this.toggleController.getActive('qualityRow');

        const filetype =
            format === 'audio'
                ? this.toggleController.getActive('audioTypeRow')
                : this.toggleController.getActive('videoTypeRow');

        this.resetUI();

        // Open the SSE connection with the chosen parameters
        const client = new DownloadClient(
            'api/index.php?route=download',
            { url, format, quality, filetype }
        );

        // Wire up every server-sent event to its UI handler
        client.onLog(this.onLog.bind(this));

        client.onPhase(this.onPhase.bind(this));

        client.onProgress(this.onProgress.bind(this));

        client.onDone(info => this.onDone(client, info));

        client.onError(err => this.onError(client, err));

        client.onConnectionLost(() =>
            this.onError(client, 'Connection lost'));
    }

    /**
     * Resets all UI elements to their "download in progress" state:
     * spinner, empty log, disabled button, hidden progress bar.
     */
    resetUI() {

        this.status.className = 'status';
        this.status.innerHTML =
            progressRingHTML(null) + 'starting...';

        this.logBox.textContent = '';
        this.logBox.style.display = 'block';

        this.btn.disabled = true;

        this.progressBar.hide();
    }

    /**
     * Appends a line of backend output to the log box and auto-scrolls.
     *
     * @param {string} text - A single log line from the server.
     */
    onLog(text) {

        this.logBox.textContent += text + '\n';
        this.logBox.scrollTop = this.logBox.scrollHeight;
    }

    /**
     * Updates the status text when the backend enters a new phase
     * (e.g. downloading video, downloading audio, merging).
     *
     * @param {{ label: string }} info - Phase descriptor from the server.
     */
    onPhase(info) {

        if (info.label === 'merging') {
            // Merging happens after both streams are fully downloaded
            this.status.innerHTML =
                progressRingHTML(null) +
                'merging streams...';

            this.progressBar.set(100);

            return;
        }

        this.status.innerHTML =
            progressRingHTML(null) +
            escapeHtml(`downloading (${info.label})...`);
    }

    /**
     * Refreshes the progress ring and bar with the latest percentage.
     *
     * @param {{ percent: number }} p - Progress payload from the server.
     */
    onProgress(p) {

        this.status.innerHTML =
            progressRingHTML(p.percent) +
            escapeHtml(`${p.percent.toFixed(1)}%`);

        this.progressBar.set(p.percent);
    }

    /**
     * Handles a successful download: closes the SSE stream, shows a
     * success message, fills the progress bar, and triggers the
     * browser's file-save dialog via a hidden anchor click.
     *
     * @param {DownloadClient} client - The active SSE client to close.
     * @param {{ token: string, filename: string }} info - Completion
     *   payload containing a one-time serve token and the final filename.
     */
    onDone(client, info) {

        client.close();

        this.status.className = 'status success';

        this.status.innerHTML =
            progressRingHTML(100) +
            escapeHtml('done — ' + info.filename);

        this.progressBar.set(100);

        // Programmatically trigger a download via a temporary anchor.
        // A short delay lets the PHP dev server (single-threaded)
        // finish the SSE script before we request the serve route.
        setTimeout(() => {
            const a = document.createElement('a');

            a.href =
            'api/index.php?route=serve&token=' +
                encodeURIComponent(info.token);

            a.download = info.filename;
            a.style.display = 'none';

            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }, 500);

        this.btn.disabled = false;
    }

    /**
     * Handles any error or lost connection: closes the SSE stream,
     * displays the error message, and re-enables the download button.
     *
     * @param {DownloadClient} client - The active SSE client to close.
     * @param {string} err - Error description, or falsy for a generic message.
     */
    onError(client, err) {

        client.close();

        this.status.className = 'status error';

        this.status.textContent =
            err || 'Connection lost';

        this.btn.disabled = false;
    }

}

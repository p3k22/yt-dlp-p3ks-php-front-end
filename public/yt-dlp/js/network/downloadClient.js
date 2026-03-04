/**
 * Wraps an SSE (Server-Sent Events) connection to the download API,
 * exposing typed event hooks for each stage of the download lifecycle:
 * log messages, phase transitions, progress updates, completion, and errors.
 */
export class DownloadClient {

    constructor(url, params) {

        const separator = url.includes('?') ? '&' : '?';
        const query = new URLSearchParams(params).toString();

        this.eventSource =
            new EventSource(url + separator + query);
    }

    onLog(cb) {

        this.eventSource.addEventListener('log', e =>
            cb(e.data));
    }

    onPhase(cb) {

        this.eventSource.addEventListener('phase', e =>
            cb(JSON.parse(e.data)));
    }

    onProgress(cb) {

        this.eventSource.addEventListener('progress', e =>
            cb(JSON.parse(e.data)));
    }

    onDone(cb) {

        this.eventSource.addEventListener('done', e =>
            cb(JSON.parse(e.data)));
    }

    onError(cb) {

        this.eventSource.addEventListener('error', e =>
            cb(e.data));
    }

    onConnectionLost(cb) {

        this.eventSource.onerror = cb;
    }

    close() {

        this.eventSource.close();
    }

}

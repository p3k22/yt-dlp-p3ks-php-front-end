/**
 * Application entry point.
 *
 * Initialises the UI toggle controls and the download controller,
 * then wires up user-initiated download triggers (button click and
 * Enter key in the URL field).
 */

import { ToggleController } from './ui/toggleController.js';
import { DownloadController } from './controller/downloadController.js';

// Set up the format / quality / type toggle buttons
const toggleController = new ToggleController();
toggleController.init();

// Create the download controller, passing in the toggles so it can
// read the user's chosen format, quality, and type at download time
const downloadController =
    new DownloadController(toggleController);

// Start a download when the user clicks the "Go" button
document
    .getElementById('goBtn')
    .addEventListener(
        'click',
        () => downloadController.start()
    );

// Also start a download when the user presses Enter in the URL field
document
    .getElementById('url')
    .addEventListener(
        'keydown',
        e => {
            if (e.key === 'Enter')
                downloadController.start();
        }
    );

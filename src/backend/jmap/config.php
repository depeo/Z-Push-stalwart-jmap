<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   JMAP backend configuration
*
* Copyright 2024 - Z-Push Contributors
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* Consult LICENSE file for details
************************************************/

// JMAP session endpoint URL
if (!defined('JMAP_SESSION_URL')) {
    define('JMAP_SESSION_URL', 'https://stalwart.url/jmap/session');
}

// Whether to verify SSL certificates (disable only for development)
if (!defined('JMAP_SSL_VERIFY')) {
    define('JMAP_SSL_VERIFY', true);
}

// HTTP timeout in seconds for JMAP requests
if (!defined('JMAP_TIMEOUT')) {
    define('JMAP_TIMEOUT', 30);
}

// Maximum number of email IDs to fetch in one Email/get call
if (!defined('JMAP_MAX_OBJECTS_PER_REQUEST')) {
    define('JMAP_MAX_OBJECTS_PER_REQUEST', 500);
}

// Maximum pages (of JMAP_MAX_OBJECTS_PER_REQUEST) to paginate when seeding
// the mail cache for a folder.  300 * 500 = 150,000 emails max per folder.
if (!defined('JMAP_MAX_QUERY_PAGES')) {
    define('JMAP_MAX_QUERY_PAGES', 300);
}

// Maximum number of changes to accept from a single Email/queryChanges call
// before falling back to a full reseed of the folder cache.
if (!defined('JMAP_QUERY_CHANGES_MAX')) {
    define('JMAP_QUERY_CHANGES_MAX', 50000);
}

// How many bytes of body to fetch for the preview/truncation check (0 = unlimited)
if (!defined('JMAP_MAX_BODY_BYTES')) {
    define('JMAP_MAX_BODY_BYTES', 0);
}

// Minimum interval in ms between consecutive JMAP API calls from the same
// device.  Keeps sequential calls from overwhelming the server without
// significantly slowing down bulk syncs.
if (!defined('JMAP_MIN_CALL_INTERVAL')) {
    define('JMAP_MIN_CALL_INTERVAL', 5);
}

// Maximum random jitter in ms added before each JMAP call to naturally
// desynchronise multiple devices.  The retry logic (exponential backoff)
// is the primary defence against concurrent-limit collisions.
if (!defined('JMAP_CALL_JITTER_MAX')) {
    define('JMAP_CALL_JITTER_MAX', 10);
}

// Pipe-separated list of mailbox name patterns to exclude from sync.
// Any mailbox whose name (case-insensitive) contains one of these strings
// will be hidden from the device.  Handy for shared/public mailboxes that
// should not appear on a mobile device.
// Example: define('JMAP_EXCLUDED_FOLDERS', 'Shared|Public|Archive');
if (!defined('JMAP_EXCLUDED_FOLDERS')) {
    define('JMAP_EXCLUDED_FOLDERS', '');
}

// Sleep interval in seconds between ChangesSink poll cycles.
// Higher values reduce load on the JMAP server but increase latency
// for change detection.
if (!defined('JMAP_CHANGES_SINK_SLEEP')) {
    define('JMAP_CHANGES_SINK_SLEEP', 30);
}

// Maximum runtime in seconds for a single ChangesSink invocation.
// Prevents PHP-FPM from killing long-lived Ping processes and reduces
// concurrent load on the JMAP server.
if (!defined('JMAP_CHANGES_SINK_MAX_RUNTIME')) {
    define('JMAP_CHANGES_SINK_MAX_RUNTIME', 120);
}

<?php
/***********************************************
* File      :   jmap.php
* Project   :   Z-Push
* Descr     :   JMAP backend for Z-Push. Implements the BackendDiff
*               pattern so no custom importer/exporter is needed.
*               Supports mail, contacts (JSContact) and calendars
*               (JSCalendar) via Stalwart or any RFC-compliant JMAP
*               server.
*
* Copyright 2024 - Z-Push Contributors
* AGPL-3.0 - see LICENSE
************************************************/

require_once 'backend/jmap/config.php';
require_once 'backend/jmap/jmap_client.php';
require_once 'backend/jmap/jmap_contacts.php';
require_once 'backend/jmap/jmap_calendar.php';

class BackendJmap extends BackendDiff {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    const KW_SEEN      = '$seen';
    const KW_FLAGGED   = '$flagged';
    const KW_ANSWERED  = '$answered';
    const KW_FORWARDED = '$forwarded';
    const KW_DRAFT     = '$draft';

    // Folder-ID prefixes used to distinguish resource types.
    const PFX_MAILBOX = 'M';
    const PFX_CONTACTS = 'ab_';
    const PFX_CALENDAR = 'cal_';

    // Version of the per-folder mail stat cache format.  Bump when the
    // serialized structure or the stat algorithm changes so stale caches
    // from older deploys are discarded automatically.
    const MAIL_CACHE_VERSION = 1;

    private const ROLE_TO_AS_TYPE = [
        'inbox'   => SYNC_FOLDER_TYPE_INBOX,
        'drafts'  => SYNC_FOLDER_TYPE_DRAFTS,
        'trash'   => SYNC_FOLDER_TYPE_WASTEBASKET,
        'sent'    => SYNC_FOLDER_TYPE_SENTMAIL,
        'junk'    => SYNC_FOLDER_TYPE_USER_MAIL,
        'spam'    => SYNC_FOLDER_TYPE_USER_MAIL,
        'archive' => SYNC_FOLDER_TYPE_USER_MAIL,
    ];

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    private ?JmapClient $client          = null;
    private string      $accountId       = '';
    private string      $contactsAccountId = '';
    private string      $username        = '';
    private string      $identityId   = '';
    private string      $identityName = '';

    private array $sinkStates               = [];
    private bool  $sinkInitialized           = false;
    private ?string $savedEmailAccountState  = null;
    private ?string $savedMailboxListState   = null;
    private array $excludedFolderPatterns    = [];
    private ?array $excludedMailboxIds       = null;

    // Per-request batch prefetch of email metadata during export: instead of
    // one Email/get call per message (the dominant per-item cost on bulk
    // syncs) we fetch a chunk of the change list in a single call and serve
    // the results from this cache in export order.
    private array  $batchQueue  = [];
    private array  $batchCache  = [];
    private string $batchFolder = '';
    private int    $batchPos    = 0;
    private bool   $batchFillWarned = false;
    private const BATCH_CHUNK   = 50;

    // -------------------------------------------------------------------------
    // IBackend: Auth
    // -------------------------------------------------------------------------

    public function Logon($username, $domain, $password): bool {
        if (!function_exists('curl_init')) {
            throw new FatalException('BackendJmap->Logon(): php-curl is required', 0, null, LOGLEVEL_FATAL);
        }

        $this->username = $username;
        $this->client   = new JmapClient(JMAP_SESSION_URL, $username, $password);

        try {
            // One HTTP request — fetches session and verifies credentials.
            // identityId is lazy-loaded only when SendMail() needs it.
            $this->accountId = $this->client->getAccountId();
            $this->contactsAccountId = $this->client->getCapabilityAccountId(JmapClient::CAP_CONTACTS);
            if (defined('JMAP_EXCLUDED_FOLDERS') && JMAP_EXCLUDED_FOLDERS !== '') {
                $this->excludedFolderPatterns = explode('|', JMAP_EXCLUDED_FOLDERS);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                    'BackendJmap->Logon(): excluding folders (%s)', JMAP_EXCLUDED_FOLDERS
                ));
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->Logon(): user="%s" accountId="%s" contactsAccountId="%s"',
                $username, $this->accountId, $this->contactsAccountId
            ));
            return true;
        } catch (AuthenticationRequiredException $e) {
            ZLog::Write(LOGLEVEL_WARN, sprintf('BackendJmap->Logon(): auth failed for "%s"', $username));
            return false;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->Logon(): %s', $e->getMessage()));
            return false;
        }
    }

    public function Logoff(): bool {
        $this->SaveStorages();
        $this->client    = null;
        $this->accountId = '';
        return true;
    }

    public function GetSupportedASVersion(): string {
        return ZPush::ASV_14;
    }

    // -------------------------------------------------------------------------
    // IBackend: Mail sending
    // -------------------------------------------------------------------------

    public function SendMail($sm): bool {
        ZLog::Write(LOGLEVEL_DEBUG, 'BackendJmap->SendMail()');

        if (empty($sm->mime)) {
            throw new StatusException('BackendJmap->SendMail(): empty MIME', SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
        }

        try {
            $mime = $sm->mime;
            $identityName = $this->getIdentityName();
            if ($identityName !== '') {
                $mime = $this->rewriteFromHeader($mime, $identityName);
            }

            $blobId        = $this->client->uploadBlob($mime, 'message/rfc822');
            $sentMailboxId = $this->getSentMailboxId();
            $saveCopy      = !isset($sm->saveinsentitems) || $sm->saveinsentitems;

            $mailboxIds = $sentMailboxId ? [$sentMailboxId => true] : [];

            $importResponses = $this->client->call([
                ['Email/import', [
                    'accountId' => $this->accountId,
                    'emails'    => ['i1' => [
                        'blobId'     => $blobId,
                        'mailboxIds' => $mailboxIds ?: [$this->accountId => true],
                        'keywords'   => [self::KW_SEEN => true],
                        'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]],
                ], 'im'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL]);

            $importedId = $importResponses[0][1]['created']['i1']['id'] ?? null;
            if ($importedId === null) {
                $err = $importResponses[0][1]['notCreated']['i1'] ?? 'unknown';
                throw new StatusException(
                    sprintf('BackendJmap->SendMail(): import failed: %s', json_encode($err)),
                    SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED
                );
            }

            $subResponses = $this->client->call([
                ['EmailSubmission/set', [
                    'accountId' => $this->accountId,
                    'create'    => ['s1' => [
                        'emailId'    => $importedId,
                        'identityId' => $this->getIdentityId(),
                        'envelope'   => null,
                    ]],
                ], 'sub'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL, JmapClient::CAP_SUBMIT]);

            $notCreated = $subResponses[0][1]['notCreated'] ?? [];
            if (!empty($notCreated)) {
                $err = reset($notCreated);
                throw new StatusException(
                    sprintf('BackendJmap->SendMail(): submission failed: %s', json_encode($err)),
                    SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED
                );
            }

            if (isset($sm->source->itemid)) {
                $kw = [];
                if (!empty($sm->replyflag))   $kw[self::KW_ANSWERED]  = true;
                if (!empty($sm->forwardflag)) $kw[self::KW_FORWARDED] = true;
                if ($kw) $this->setKeywords($sm->source->itemid, $kw);
            }

            return true;
        } catch (StatusException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StatusException(
                sprintf('BackendJmap->SendMail(): %s', $e->getMessage()),
                SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED
            );
        }
    }

    // -------------------------------------------------------------------------
    // IBackend: Attachment download
    // -------------------------------------------------------------------------

    public function GetAttachmentData($attname): SyncItemOperationsAttachment {
        $parts  = explode('||', $attname, 3);
        $blobId = $parts[1] ?? '';
        $name   = $parts[2] ?? 'attachment';

        if (!$blobId) {
            throw new StatusException(
                sprintf('BackendJmap->GetAttachmentData(): bad ref "%s"', $attname),
                SYNC_ITEMOPERATIONSSTATUS_INVALIDATT
            );
        }

        try {
            $data = $this->client->downloadBlob($blobId, $name);
        } catch (\Throwable $e) {
            throw new StatusException(
                sprintf('BackendJmap->GetAttachmentData(): %s', $e->getMessage()),
                SYNC_ITEMOPERATIONSSTATUS_INVALIDATT
            );
        }

        $attachment              = new SyncItemOperationsAttachment();
        $attachment->data        = StringStreamWrapper::Open($data);
        $attachment->contenttype = $this->extToMime(strtolower(pathinfo($name, PATHINFO_EXTENSION)));
        return $attachment;
    }

    // -------------------------------------------------------------------------
    // IBackend: Waste basket & sink
    // -------------------------------------------------------------------------

    public function GetWasteBasket(): string|false {
        try {
            $id = $this->getMailboxIdByRole('trash');
            return $id !== false ? self::PFX_MAILBOX . $id : false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function HasChangesSink(): bool { return true; }

    public function ChangesSinkInitialize($folderid): bool {
        try {
            $state = $this->getFolderQueryState($folderid);
            $this->sinkStates[$folderid] = $state;
            $this->sinkInitialized = true;
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->ChangesSinkInitialize(): folder=%s state=%s',
                $folderid, $state
            ));
            // When any mail folder is initialized, seed sinkStates for ALL
            // mailboxes and capture the account-level email state.
            // This state changes for ALL email modifications (including
            // keyword-only flag toggles) in ANY mailbox, not just the subset
            // of folders the phone is currently syncing.
            if ($this->folderType($folderid) === 'mail') {
                $this->seedAllMailboxSinkStates();
            }
            return true;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->ChangesSinkInitialize(): %s', $e->getMessage()));
            return false;
        }
    }

    private function seedAllMailboxSinkStates(): void {
        // Capture account-level email state once
        if ($this->savedEmailAccountState === null) {
            $current = $this->fetchEmailAccountState();
            $this->savedEmailAccountState = $current;

            // Detect state changes that happened between processes (e.g. during
            // initial sync or while the previous ChangesSink was not running).
            $previous = $this->readPersistedEmailState();
            if ($previous !== '' && $previous !== $current) {
                // The next delta sync will pick the changes up (window re-list).
                ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                    'BackendJmap->seedAllMailboxSinkStates(): cross-process gap detected prev=%s current=%s (delta sync will catch up)',
                    $previous, $current
                ));
            }
            // Persist current state for the next process.
            $this->writePersistedEmailState($current);

            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->seedAllMailboxSinkStates(): emailAccountState=%s%s',
                $current, $previous !== '' ? sprintf(' prev=%s', $previous) : ' (first run)'
            ));
        }
        // Capture mailbox list state for hierarchy change detection
        if ($this->savedMailboxListState === null) {
            $this->savedMailboxListState = $this->fetchMailboxListState();
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->seedAllMailboxSinkStates(): mailboxListState=%s',
                $this->savedMailboxListState
            ));
        }
        // Add every mailbox to sinkStates (with empty state, since we rely on
        // the account-level state for change detection, not per-folder queries)
        try {
            $mailboxes = $this->getAllMailboxes();
            $seeded = 0;
            foreach ($mailboxes as $mb) {
                if ($this->isExcludedMailbox($mb)) continue;
                $mbId = self::PFX_MAILBOX . $mb['id'];
                if (!isset($this->sinkStates[$mbId])) {
                    $this->sinkStates[$mbId] = '';
                }
                $seeded++;
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->seedAllMailboxSinkStates(): %d mailboxes seeded (%d excluded)',
                $seeded, count($mailboxes) - $seeded
            ));
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->seedAllMailboxSinkStates(): %s', $e->getMessage()
            ));
        }
    }

    /**
     * Persistent file-based email state that survives across process boundaries.
     * Written by every ChangesSink poll; read by seedAllMailboxSinkStates() to
     * detect state changes that occurred between processes.
     */
    private function emailStateFilePath(): string {
        return sys_get_temp_dir() . '/zpush_jmap_emailstate_' . md5($this->username ?? 'unknown') . '.sta';
    }

    private function readPersistedEmailState(): string {
        $v = @file_get_contents($this->emailStateFilePath());
        return $v !== false ? $v : '';
    }

    private function writePersistedEmailState(string $state): void {
        @file_put_contents($this->emailStateFilePath(), $state);
    }

    public function ChangesSink($timeout = 30): array {
        if (!$this->sinkInitialized || !$this->sinkStates) {
            ZLog::Write(LOGLEVEL_DEBUG, 'BackendJmap->ChangesSink(): not initialized');
            return [];
        }

        $start      = time();
        $maxRuntime = min($timeout, JMAP_CHANGES_SINK_MAX_RUNTIME);
        $sleep      = JMAP_CHANGES_SINK_SLEEP;
        $changed    = [];

        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->ChangesSink(): polling for %ds (max %ds, sleep %ds)',
            $timeout, $maxRuntime, $sleep
        ));

        while (time() - $start < $maxRuntime) {
            try {
                $mailChanged = false;

                // Check account-level email state — this catches ALL email
                // modifications (including keyword-only flag toggles) in ANY
                // mailbox, not just the folders the phone is currently syncing.
                if ($this->savedEmailAccountState !== null) {
                    $current = $this->fetchEmailAccountState();
                    // Skip if the API call failed (empty result) — treating a
                    // failure as a change would cause false-positive rescan flags.
                    if ($current !== '') {
                        $mailChanged = $current !== $this->savedEmailAccountState;
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                            'BackendJmap->emailAccountState: saved=%s current=%s [%s]',
                            $this->savedEmailAccountState, $current, $mailChanged ? 'DIFF' : 'SAME'
                        ));
                        if ($mailChanged) {
                            $this->savedEmailAccountState = $current;
                            // A change happened somewhere in the account.  Wake
                            // the mail folders; each runs a normal cutoff delta
                            // sync.  No rescan/full scan needed — the window
                            // re-list + stat 'mod' hash catches keyword/flag
                            // changes on synced emails.
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                                'BackendJmap->emailAccountState: change detected, signaling mail folders for delta sync (%s)',
                                $current
                            ));
                        }
                        // Keep the file-based state current at each poll cycle so
                        // the next ChangesSink process can detect cross-process gaps.
                        $this->writePersistedEmailState($current);
                    }
                }

                // Check mailbox list state — detect new/renamed/deleted mailboxes
                if ($this->savedMailboxListState !== null) {
                    $current = $this->fetchMailboxListState();
                    if ($current !== '') {
                        if ($current !== $this->savedMailboxListState) {
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                                'BackendJmap->ChangesSink(): mailbox hierarchy changed %s -> %s',
                                $this->savedMailboxListState, $current
                            ));
                            $this->savedMailboxListState = $current;
                            $changed[] = IBackend::HIERARCHYNOTIFICATION;
                        }
                    }
                }

                // Per-folder checks (contacts, calendars — mail folders are
                // handled via account-level state above).
                foreach ($this->sinkStates as $folderid => $saved) {
                    if ($this->folderType($folderid) === 'mail') {
                        // If account state detected a change, signal all mail folders.
                        if ($mailChanged && !in_array($folderid, $changed, true)) {
                            $changed[] = $folderid;
                        }
                        continue; // skip per-folder mail query
                    }
                    $current = $this->getFolderQueryState($folderid);
                    $same = $current === $saved ? 'SAME' : 'DIFF';
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                        'BackendJmap->ChangesSink(): folder=%s saved=%s current=%s [%s]',
                        $folderid, $saved, $current, $same
                    ));
                    if ($current !== $saved) {
                        $this->sinkStates[$folderid] = $current;
                        $changed[] = $folderid;
                    }
                }
            } catch (\Throwable $e) {
                ZLog::Write(LOGLEVEL_WARN, sprintf('BackendJmap->ChangesSink(): %s', $e->getMessage()));
            }

            if ($changed) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                    'BackendJmap->ChangesSink(): DETECTED changes in %s', json_encode($changed)
                ));
                return $changed;
            }
            sleep($sleep);
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->ChangesSink(): max runtime (%ds) reached, no changes', $maxRuntime
        ));
        return [];
    }

    private function isExcludedMailbox(array $mb): bool {
        if (!$this->excludedFolderPatterns) return false;
        $name = strtolower($mb['name'] ?? '');
        foreach ($this->excludedFolderPatterns as $pattern) {
            if (str_contains($name, strtolower(trim($pattern)))) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                    'BackendJmap->isExcludedMailbox(): pattern="%s" matched mailbox="%s"',
                    $pattern, $mb['name'] ?? '?'
                ));
                return true;
            }
        }
        return false;
    }

    /**
     * Excluded by mailbox name (JMAP_EXCLUDED_FOLDERS) but keyed by folder id,
     * so the data paths (GetMessageList/GetMessage/StatMessage) can refuse to
     * serve sync data for folders the device may still hold in its sync set
     * until the next FolderSync removes them.
     */
    private function isExcludedFolderId(string $folderid): bool {
        if ($this->folderType($folderid) !== 'mail') return false;
        if ($this->excludedMailboxIds === null) {
            $this->excludedMailboxIds = [];
            try {
                foreach ($this->getAllMailboxes() as $mb) {
                    if ($this->isExcludedMailbox($mb)) {
                        $this->excludedMailboxIds[$mb['id']] = true;
                    }
                }
            } catch (\Throwable $e) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                    'BackendJmap->isExcludedFolderId(): %s', $e->getMessage()
                ));
            }
        }
        return isset($this->excludedMailboxIds[$this->jmapId($folderid)]);
    }

    // -------------------------------------------------------------------------
    // BackendDiff: Folder list / hierarchy
    // -------------------------------------------------------------------------

    public function GetFolderList(): array {
        try {
            $out = [];

            // Mail mailboxes
            $mailboxes = $this->getAllMailboxes();
            foreach ($mailboxes as $mb) {
                if ($this->isExcludedMailbox($mb)) continue;
                $out[] = ['id' => self::PFX_MAILBOX . $mb['id'], 'parent' => $mb['parentId'] ?? false, 'mod' => $mb['id']];
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->GetFolderList(): %d mailboxes (%d after exclude), names=[%s]',
                count($mailboxes), count($out),
                implode(', ', array_map(fn($m) => $m['name'] . '[' . ($m['role'] ?? '?') . '](parent=' . ($m['parentId'] ?? 'root') . ')', $mailboxes))
            ));

            // Address books — prefixed with PFX_CONTACTS
            foreach ($this->getAllAddressBooks() as $ab) {
                $out[] = ['id' => self::PFX_CONTACTS . $ab['id'], 'parent' => false, 'mod' => $ab['id']];
            }

            // Calendars — prefixed with PFX_CALENDAR
            foreach ($this->getAllCalendars() as $cal) {
                $out[] = ['id' => self::PFX_CALENDAR . $cal['id'], 'parent' => false, 'mod' => $cal['id']];
            }

            return $out;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->GetFolderList(): %s', $e->getMessage()));
            return [];
        }
    }

    public function GetFolder($id): SyncFolder|false {
        try {
            return match($this->folderType($id)) {
                'contacts' => $this->getAddressBookAsFolder($id),
                'calendar' => $this->getCalendarAsFolder($id),
                default    => $this->getMailboxAsFolder($id),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->GetFolder("%s"): %s', $id, $e->getMessage()));
            return false;
        }
    }

    public function StatFolder($id): array|false {
        try {
            $jid = $this->jmapId($id);
            return ['id' => $id, 'parent' => false, 'mod' => $jid];
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function ChangeFolder($folderid, $oldid, $displayname, $type): string|false {
        try {
            if (in_array($type, [SYNC_FOLDER_TYPE_CONTACT, SYNC_FOLDER_TYPE_USER_CONTACT], true)) {
                return $this->changeAddressBook($oldid, $displayname);
            }
            if (in_array($type, [SYNC_FOLDER_TYPE_APPOINTMENT, SYNC_FOLDER_TYPE_USER_APPOINTMENT], true)) {
                return $this->changeCalendar($oldid, $displayname);
            }
            return $this->changeMailbox($folderid, $oldid, $displayname);
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->ChangeFolder(): %s', $e->getMessage()));
            return false;
        }
    }

    public function DeleteFolder($id, $parentid): bool {
        try {
            return match($this->folderType($id)) {
                'contacts' => $this->deleteAddressBook($id),
                'calendar' => $this->deleteCalendar($id),
                default    => $this->deleteMailbox($id),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->DeleteFolder("%s"): %s', $id, $e->getMessage()));
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // BackendDiff: Message list & individual messages
    // -------------------------------------------------------------------------

    public function GetMessageList($folderid, $cutoffdate): array {
        if ($this->isExcludedFolderId($folderid)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->GetMessageList("%s"): folder excluded, serving empty list', $folderid
            ));
            return [];
        }
        try {
            $list = match($this->folderType($folderid)) {
                'contacts' => $this->listContacts($this->jmapId($folderid)),
                'calendar' => $this->listCalendarEvents($this->jmapId($folderid), $cutoffdate),
                default    => $this->listEmails($folderid, $cutoffdate),
            };

            // Prime the per-request metadata batch prefetch for the export that
            // follows (mail folders only).  listEmails() returns the stat
            // arrays as values, so the ids must be pulled out of each entry
            // rather than taken from the array keys (which are 0..n integers
            // and would make the batch Email/get fail schema validation).
            if ($this->folderType($folderid) === 'mail') {
                $this->batchQueue  = array_values(array_filter(
                    array_column($list, 'id'),
                    static fn($v) => is_string($v) && $v !== ''
                ));
                $this->batchFolder = $folderid;
                $this->batchCache  = [];
                $this->batchPos    = 0;
            }

            ZLog::Write(LOGLEVEL_INFO, sprintf(
                'BackendJmap->GetMessageList("%s"): %d items, cutoff=%d',
                $folderid, count($list), $cutoffdate
            ));
            return $list;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->GetMessageList("%s"): %s', $folderid, $e->getMessage()));
            return [];
        }
    }

    public function GetMessage($folderid, $id, $contentparameters): SyncObject|false {
        if ($this->isExcludedFolderId($folderid)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->GetMessage("%s","%s"): folder excluded, serving false', $folderid, $id
            ));
            return false;
        }
        try {
            return match($this->folderType($folderid)) {
                'contacts' => $this->getContact($id),
                'calendar' => $this->getCalendarEvent($id),
                default    => $this->getEmail($folderid, $id, $contentparameters),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->GetMessage("%s","%s"): %s', $folderid, $id, $e->getMessage()));
            return false;
        }
    }

    public function StatMessage($folderid, $id): array|false {
        if ($this->isExcludedFolderId($folderid)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->StatMessage("%s","%s"): folder excluded, serving false', $folderid, $id
            ));
            return false;
        }
        try {
            $result = match($this->folderType($folderid)) {
                'contacts' => $this->statContact($id),
                'calendar' => $this->statCalendarEvent($id),
                default    => $this->statEmail($folderid, $id),
            };
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->StatMessage("%s","%s"): %s', $folderid, $id, json_encode($result)
            ));
            return $result;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->StatMessage("%s","%s"): %s', $folderid, $id, $e->getMessage()));
            return false;
        }
    }

    public function ChangeMessage($folderid, $id, $message, $contentParameters): array|false {
        try {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->ChangeMessage(): folder=%s id=%s type=%s',
                $folderid, $id, $this->folderType($folderid)
            ));
            $newId = match($this->folderType($folderid)) {
                'contacts' => $this->saveContact($this->jmapId($folderid), $id, $message),
                'calendar' => $this->saveCalendarEvent($this->jmapId($folderid), $id, $message),
                default    => $this->saveDraft($folderid, $id, $message, $contentParameters),
            };
            if ($newId === false) {
                ZLog::Write(LOGLEVEL_WARN, sprintf(
                    'BackendJmap->ChangeMessage(): FAILED folder=%s id=%s', $folderid, $id
                ));
                return false;
            }
            return $this->StatMessage($folderid, $newId);
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->ChangeMessage("%s","%s"): %s', $folderid, $id, $e->getMessage()));
            return false;
        }
    }

    public function SetReadFlag($folderid, $id, $flags, $contentParameters): bool {
        if ($this->folderType($folderid) !== 'mail') return true;
        try {
            $result = $this->setKeywords($id, [self::KW_SEEN => ($flags != 0)]);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->SetReadFlag(): id=%s flags=%d result=%s', $id, $flags, $result ? 'ok' : 'FAIL'
            ));
            return $result;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->SetReadFlag(): %s', $e->getMessage()));
            return false;
        }
    }

    public function DeleteMessage($folderid, $id, $contentParameters): bool {
        try {
            return match($this->folderType($folderid)) {
                'contacts' => $this->destroyContact($id),
                'calendar' => $this->destroyCalendarEvent($id),
                default    => $this->deleteEmail($folderid, $id, $contentParameters),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->DeleteMessage(): %s', $e->getMessage()));
            return false;
        }
    }

    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters): string|false {
        try {
            return match($this->folderType($folderid)) {
                'contacts' => false,
                'calendar' => $this->moveCalendarEvent($id, $this->jmapId($folderid), $this->jmapId($newfolderid)),
                default    => $this->moveEmail($folderid, $id, $newfolderid),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->MoveMessage(): %s', $e->getMessage()));
            return false;
        }
    }

    // =========================================================================
    // MAIL helpers
    // =========================================================================

    private function listEmails(string $folderid, int $cutoffdate): array {
        $jid    = $this->jmapId($folderid);
        $filter = $this->mailWindowFilter($jid, $cutoffdate);

        // Per-folder stat cache: the expensive part of a mailbox sync is
        // re-enumerating + fetching keywords for every email in the window on
        // every GetMessageList call.  Instead we keep a persistent cache of
        // the stat list and only reconcile it when the account state changed.
        // A folder with 100k+ emails then costs ONE cheap account-state call
        // per idle sync instead of re-listing the whole mailbox.
        $cache = $this->mailCacheLoad($jid, $cutoffdate);
        if ($cache !== false) {
            $stateNow = $this->fetchEmailAccountState();
            if ($stateNow === '') {
                // JMAP temporarily unavailable: fail soft with the cached list.
                return array_values($cache['emails']);
            }
            if ($stateNow === $cache['accountState']) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                    'BackendJmap->listEmails(%s): cache hit (%d emails, cutoff=%d)',
                    $folderid, count($cache['emails']), $cutoffdate
                ));
                return array_values($cache['emails']);
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->listEmails(%s): account state changed, reconciling cache (%d emails)',
                $folderid, count($cache['emails'])
            ));
            $cache = $this->mailCacheReconcile($jid, $cache, $stateNow);
            return array_values($cache['emails']);
        }

        $cache = $this->mailCacheSeed($jid, $filter, $cutoffdate);
        return array_values($cache['emails']);
    }

    private function mailWindowFilter(string $jid, int $cutoffdate): array {
        $filter = ['inMailbox' => $jid];
        if ($cutoffdate > 0) {
            // Incremental sync: filter by receivedAt.
            $filter['after'] = gmdate('Y-m-d\TH:i:s\Z', $cutoffdate);
        }
        return $filter;
    }

    /**
     * Build the full stat list for a folder window from scratch.
     * Returns the cache array; persists it to disk.
     */
    private function mailCacheSeed(string $jid, array $filter, int $cutoffdate): array {
        // Capture the account state BEFORE the enumeration so that any change
        // that happens while we are listing (e.g. an incoming message) leaves
        // a mismatch that the next sync reconciles.
        $stateAtSeed = $this->fetchEmailAccountState();

        // Paginate through all emails, newest first
        $allIds     = [];
        $pageStart  = 0;
        $total      = 0;
        $queryState = '';
        $maxPages   = JMAP_MAX_QUERY_PAGES;
        for ($page = 0; $page < $maxPages; $page++) {
            $q = [
                'accountId'      => $this->accountId,
                'filter'         => $filter,
                'sort'           => [['property' => 'receivedAt', 'isAscending' => false]],
                'limit'          => JMAP_MAX_OBJECTS_PER_REQUEST,
                'calculateTotal' => true,
            ];
            if ($pageStart > 0) {
                $q['position'] = $pageStart;
            }
            $r = $this->client->call([
                ['Email/query', $q, 'q'],
            ]);
            $resp       = $r[0][1] ?? [];
            $ids        = $resp['ids'] ?? [];
            $total      = $resp['total'] ?? $total;
            $queryState = $resp['queryState'] ?? $queryState;
            $allIds     = array_merge($allIds, $ids);
            $count      = count($ids);
            if ($count < JMAP_MAX_OBJECTS_PER_REQUEST) break;
            if ($total > 0 && $pageStart + $count >= $total) break;
            $pageStart += $count;
        }

        if ($total > 0 && count($allIds) < $total) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->listEmails: mailbox %s has %d emails, only fetched %d (pagination cap %d)',
                $jid, $total, count($allIds), JMAP_MAX_OBJECTS_PER_REQUEST * $maxPages
            ));
        }

        $emails = [];
        if (!empty($allIds)) {
            $stats = $this->fetchEmailStats($allIds, $jid);
            foreach ($stats as $id => $stat) {
                if ($stat !== null) $emails[$id] = $stat;
            }
        }

        $cache = [
            'v'            => self::MAIL_CACHE_VERSION,
            'cutoff'       => $cutoffdate,
            'accountState' => $stateAtSeed,
            'queryState'   => $queryState,
            'emails'       => $emails,
        ];
        $this->mailCacheSave($jid, $cache);

        ZLog::Write(LOGLEVEL_INFO, sprintf(
            'BackendJmap->listEmails: seeded %s (%d/%d emails, cutoff=%d)',
            $jid, count($emails), $total, $cutoffdate
        ));
        return $cache;
    }

    /**
     * Incrementally update the stat cache using Email/queryChanges.
     * Returns the updated cache array.
     */
    private function mailCacheReconcile(string $jid, array $cache, string $stateNow): array {
        $queryState = $cache['queryState'] ?? '';
        $cutoff     = $cache['cutoff'] ?? 0;
        if ($queryState === '') {
            return $this->mailCacheSeed($jid, $this->mailWindowFilter($jid, $cutoff), $cutoff);
        }
        try {
            $r = $this->client->call([
                ['Email/queryChanges', [
                    'accountId'       => $this->accountId,
                    'sinceQueryState' => $queryState,
                    'maxChanges'      => JMAP_QUERY_CHANGES_MAX,
                    'filter'          => $this->mailWindowFilter($jid, $cutoff),
                    'sort'            => [['property' => 'receivedAt', 'isAscending' => false]],
                    'calculateTotal'  => true,
                ], 'c'],
            ]);
            $resp = $r[0][1] ?? [];
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->mailCacheReconcile(%s): queryChanges error, reseeding: %s',
                $jid, $e->getMessage()
            ));
            return $this->mailCacheSeed($jid, $this->mailWindowFilter($jid, $cutoff), $cutoff);
        }

        // Stalwart omits 'canCalculateChanges' on success (RFC 8620 only sends
        // it when false); an inability to calculate changes arrives as a JMAP
        // error response instead (e.g. 'cannotCalculateChanges', 'tooManyChanges').
        // Treat an error response -- or an explicit canCalculateChanges:false --
        // as "cannot reconcile, reseed".
        if (isset($resp['type']) || ($resp['canCalculateChanges'] ?? true) === false) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->mailCacheReconcile(%s): queryChanges not calculable (%s), reseeding',
                $jid, ($resp['type'] ?? 'canCalculateChanges=false')
            ));
            return $this->mailCacheSeed($jid, $this->mailWindowFilter($jid, $cutoff), $cutoff);
        }

        // 'removed' from Stalwart is complete: every updated + destroyed id in
        // the account since the last queryState (moved/flag-changed/deleted).
        // 'added' additionally contains created ids (always at the top of the
        // receivedAt-desc query).  Union = all changed ids in the account.
        $removed = array_values($resp['removed'] ?? []);
        $added   = [];
        foreach (($resp['added'] ?? []) as $item) {
            $added[] = $item['id'] ?? $item;
        }
        $changed = array_values(array_unique(array_merge($removed, $added)));
        $newQueryState = $resp['newQueryState'] ?? $queryState;

        if (!$changed) {
            // Account-wide state moved but this folder saw no journal changes.
            // Keep the cache; just advance the fingerprints.
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->mailCacheReconcile(%s): no changes reported, keeping cache (%d emails)',
                $jid, count($cache['emails'])
            ));
            $cache['accountState'] = $stateNow;
            $cache['queryState']   = $newQueryState;
            $this->mailCacheSave($jid, $cache);
            return $cache;
        }

        try {
            $stats = $this->fetchEmailStats($changed, $jid, $cutoff);
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->mailCacheReconcile(%s): failed to fetch stats, reseeding: %s',
                $jid, $e->getMessage()
            ));
            return $this->mailCacheSeed($jid, $this->mailWindowFilter($jid, $cutoff), $cutoff);
        }
        $emails = $cache['emails'];
        $addedCount = $updatedCount = $removedCount = 0;
        foreach ($changed as $id) {
            if (array_key_exists($id, $stats)) {
                if ($stats[$id] !== null) {
                    if (isset($emails[$id])) {
                        $updatedCount++;
                    } else {
                        $addedCount++;
                    }
                    $emails[$id] = $stats[$id];
                } elseif (isset($emails[$id])) {
                    // Email no longer in this mailbox.
                    unset($emails[$id]);
                    $removedCount++;
                }
            } elseif (isset($emails[$id])) {
                // Not returned by Email/get: destroyed.
                unset($emails[$id]);
                $removedCount++;
            }
        }

        $cache['emails']       = $emails;
        $cache['accountState'] = $stateNow;
        $cache['queryState']   = $newQueryState;
        $this->mailCacheSave($jid, $cache);

        ZLog::Write(LOGLEVEL_INFO, sprintf(
            'BackendJmap->listEmails: delta %s added=%d updated=%d removed=%d (cache %d -> %d emails)',
            $jid, $addedCount, $updatedCount, $removedCount,
            count($cache['emails']) - $addedCount + $removedCount, count($cache['emails'])
        ));
        return $cache;
    }

    /**
     * Fetch stat entries (keywords) for a batch of email IDs via Email/get.
     * Multiple Email/get calls are batched into a single JMAP request.
     * Returns id => stat array; an id mapped to null means the email exists
     * but is no longer in the given mailbox (or, if $minReceivedAt > 0, it is
     * outside the sync window).  Missing ids were destroyed.
     */
    private function fetchEmailStats(array $ids, string $jid, int $minReceivedAt = 0): array {
        $out = [];
        if (!$ids) return $out;
        $chunks = array_chunk($ids, JMAP_MAX_OBJECTS_PER_REQUEST);
        // Stalwart rejects any HTTP request with more than 'maxMethodCalls'
        // (default 16) method calls in the batch.  Keep each request within it.
        $batchSize = 16;
        foreach (array_chunk($chunks, $batchSize) as $batch) {
            $calls = [];
            foreach ($batch as $i => $chunk) {
                $calls[] = ['Email/get', [
                    'accountId'  => $this->accountId,
                    'ids'        => $chunk,
                    'properties' => ['id', 'keywords', 'mailboxIds', 'receivedAt'],
                ], 'e' . $i];
            }
            $resps = $this->client->call($calls);
            foreach ($resps as $resp) {
                if (!isset($resp[1]['list'])) {
                    // An error response must never be mistaken for "email was
                    // destroyed"; let the caller reseed instead.
                    ZLog::Write(LOGLEVEL_WARN, sprintf(
                        'BackendJmap->fetchEmailStats: unexpected response %s',
                        json_encode($resp[1] ?? $resp[0] ?? [])
                    ));
                    throw new \RuntimeException('Email/get error response in fetchEmailStats');
                }
                foreach ($resp[1]['list'] as $email) {
                    $eid = $email['id'] ?? null;
                    if ($eid === null) continue;
                    if (isset($email['mailboxIds'][$jid])
                        && ($minReceivedAt <= 0 || strtotime($email['receivedAt'] ?? '') >= $minReceivedAt)) {
                        $out[$eid] = $this->emailToStat($email);
                    } else {
                        $out[$eid] = null;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Cache file path.  The path is keyed by the sync window so that devices
     * on different filters (e.g. one on "No Limit", another on "1 month") use
     * separate caches for the same folder.  Without this, the two devices keep
     * invalidating each other's cache and force a full reseed on every poll.
     * The window start (time() - N days) drifts by seconds between polls, so it
     * is bucketed to 7-day boundaries to stay stable across a week.
     */
    private function mailCachePath(string $jid, int $cutoffdate): string {
        $bucket = $cutoffdate <= 0 ? 0 : (int)ceil($cutoffdate / (7 * 86400));
        return sys_get_temp_dir() . '/zpush_jmap_mailcache_' . md5(($this->username ?? 'unknown') . '_' . $jid) . '_' . $bucket . '.cache';
    }

    /**
     * Load a valid cache for the folder, or false if absent/stale/invalid.
     * The path is window-bucketed so devices on different filters never share
     * a cache; the remaining tolerance covers drift within a bucket.
     */
    private function mailCacheLoad(string $jid, int $cutoffdate): array|false {
        $path = $this->mailCachePath($jid, $cutoffdate);
        $raw  = @file_get_contents($path);
        if ($raw === false) return false;
        // PHP serialize is considerably faster than json_decode for the large
        // per-folder stat lists.  allowed_classes=false keeps it safe to read.
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || ($data['v'] ?? 0) !== self::MAIL_CACHE_VERSION) return false;
        if (!isset($data['emails']) || !is_array($data['emails'])) return false;
        $cachedCutoff = $data['cutoff'] ?? null;
        if (!is_int($cachedCutoff) || abs($cutoffdate - $cachedCutoff) > 7 * 86400) return false;
        return $data;
    }

    private function mailCacheSave(string $jid, array $data): void {
        $path = $this->mailCachePath($jid, $data['cutoff'] ?? 0);
        $tmp  = $path . '.' . getmypid() . '.tmp';
        $raw  = serialize($data);
        if (@file_put_contents($tmp, $raw) === false) return;
        @rename($tmp, $path);
        @chmod($path, 0600);
    }

    private function getEmail(string $folderid, string $id, $contentparameters): SyncMail|false {
        $jid = $this->jmapId($folderid);

        // Serve from the per-request batch prefetch when available (export path).
        if ($folderid === $this->batchFolder && array_key_exists($id, $this->batchCache)) {
            $email = $this->batchCache[$id];
            unset($this->batchCache[$id]);
            return ($email === null) ? false : $this->buildSyncMail($email, $contentparameters);
        }
        if ($folderid === $this->batchFolder && $this->batchPos < count($this->batchQueue)) {
            $this->fillBatchCache($jid, $contentparameters);
            if (array_key_exists($id, $this->batchCache)) {
                $email = $this->batchCache[$id];
                unset($this->batchCache[$id]);
                return ($email === null) ? false : $this->buildSyncMail($email, $contentparameters);
            }
        }

        $r = $this->client->call([
            ['Email/get', $this->emailGetParams($contentparameters, [$id]), '0'],
        ]);

        $email = $r[0][1]['list'][0] ?? null;
        if ($email && !isset($email['mailboxIds'][$jid])) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->getEmail(): email %s no longer in mailbox %s', $id, $folderid
            ));
            return false;
        }
        return $email ? $this->buildSyncMail($email, $contentparameters) : false;
    }

    /**
     * Build the Email/get arguments for one or more ids.  When the client wants
     * raw MIME (iOS full download) the body values are skipped entirely and the
     * blob is downloaded instead — the body JSON is a large fraction of the
     * per-email cost during a bulk sync.
     */
    private function emailGetParams($contentparameters, array $ids): array {
        $bodypreference = $contentparameters->GetBodyPreference() ?: [];
        $mimePreferred  = in_array(SYNC_BODYPREFERENCE_MIME, $bodypreference, true);
        $properties = [
            'id', 'blobId', 'mailboxIds', 'keywords', 'size',
            'receivedAt', 'subject', 'from', 'to', 'cc', 'bcc', 'replyTo',
            'hasAttachment', 'attachments',
        ];
        if (!$mimePreferred) {
            $properties = array_merge($properties, ['textBody', 'htmlBody', 'bodyValues']);
        }
        $params = [
            'accountId'  => $this->accountId,
            'ids'        => $ids,
            'properties' => $properties,
        ];
        if (!$mimePreferred) {
            $params['fetchAllBodyValues'] = true;
            if (JMAP_MAX_BODY_BYTES > 0) {
                $params['maxBodyValueBytes'] = JMAP_MAX_BODY_BYTES;
            }
        }
        return $params;
    }

    /**
     * Fetch the next batch of email metadata (headers + attachments) in a
     * single Email/get call and cache it keyed by id.  Emails that no longer
     * exist or moved out of the folder are cached as null so getEmail() can
     * skip them without a per-item call.
     */
    private function fillBatchCache(string $jid, $contentparameters): void {
        $ids = array_slice($this->batchQueue, $this->batchPos, self::BATCH_CHUNK);
        $this->batchPos += count($ids);
        if (empty($ids)) {
            return;
        }
        try {
            $r = $this->client->call([
                ['Email/get', $this->emailGetParams($contentparameters, $ids), '0'],
            ]);
        } catch (\Throwable $e) {
            if (!$this->batchFillWarned) {
                $this->batchFillWarned = true;
                ZLog::Write(LOGLEVEL_WARN, sprintf(
                    'BackendJmap->fillBatchCache(): batch Email/get failed (%s), falling back to single fetches',
                    $e->getMessage()
                ));
            }
            return;
        }
        $found = [];
        foreach (($r[0][1]['list'] ?? []) as $email) {
            if ($email && isset($email['mailboxIds'][$jid])) {
                $found[$email['id']] = $email;
            }
        }
        foreach ($ids as $batchId) {
            $this->batchCache[$batchId] = $found[$batchId] ?? null;
        }
    }

    private function statEmail(string $folderid, string $id): array|false {
        $jid = $this->jmapId($folderid);

        // Serve from the per-request batch prefetch when available (export path).
        // The stat data (id + keywords) is already part of the batched Email/get
        // records, so no extra per-item call is needed.
        if ($folderid === $this->batchFolder && array_key_exists($id, $this->batchCache)) {
            $email = $this->batchCache[$id];
            return ($email === null) ? false : $this->emailToStat($email);
        }

        $r = $this->client->call([
            ['Email/get', ['accountId' => $this->accountId, 'ids' => [$id], 'properties' => ['id', 'keywords', 'mailboxIds']], '0'],
        ]);
        $email = $r[0][1]['list'][0] ?? null;
        if ($email && !isset($email['mailboxIds'][$jid])) {
            return false;
        }
        return $email ? $this->emailToStat($email) : false;
    }

    private function saveDraft(string $folderid, ?string $id, $message, $contentParameters): string|false {
        // Keyword-only changes (flag, categories) have no MIME content.
        $hasMime = !empty($message->mime);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->saveDraft(): id=%s mime=%s flag=%s',
            $id ?? 'NEW',
            $hasMime ? 'YES' : 'NO',
            isset($message->flag) ? ('status=' . ($message->flag->flagstatus ?? '?')) : 'NONE'
        ));
        if (!$hasMime) {
            if (!$id) return false;
            return $this->saveDraftKeywords($id, $message) ? $id : false;
        }

        $jid   = $this->jmapId($folderid);
        $blobId = $this->client->uploadBlob($message->mime, 'message/rfc822');
        $folder = $this->GetFolder($folderid);
        $kw     = ($folder && $folder->type === SYNC_FOLDER_TYPE_DRAFTS) ? [self::KW_DRAFT => true] : [];

        $r = $this->client->call([
            ['Email/import', [
                'accountId' => $this->accountId,
                'emails'    => ['n1' => [
                    'blobId'     => $blobId,
                    'mailboxIds' => [$jid => true],
                    'keywords'   => $kw,
                    'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ]],
            ], '0'],
        ]);

        $created = $r[0][1]['created']['n1'] ?? null;
        if (!$created) return false;

        if ($id) $this->deleteEmail($folderid, $id, $contentParameters);
        return $created['id'];
    }

    private function saveDraftKeywords(string $id, $message): bool {
        $kw = [];
        if (isset($message->flag)) {
            $flag = $message->flag;
            $kw[self::KW_FLAGGED] = !empty($flag->flagstatus);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->saveDraftKeywords(): flagstatus=%s → $flagged=%s',
                json_encode($flag->flagstatus ?? 'NOTSET'),
                json_encode($kw[self::KW_FLAGGED])
            ));
        }
        if (!empty($message->replyflag))   $kw[self::KW_ANSWERED]  = true;
        if (!empty($message->forwardflag)) $kw[self::KW_FORWARDED] = true;
        if (!empty($message->categories)) {
            $catList = is_array($message->categories) ? $message->categories : explode(' ', $message->categories);
            foreach ($catList as $cat) {
                $kw[trim($cat)] = true;
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->saveDraftKeywords(): id=%s kw=%s', $id, json_encode($kw)
        ));
        if (!$kw) return false;
        return $this->setKeywords($id, $kw);
    }

    private function deleteEmail(string $folderid, string $id, $contentParameters): bool {
        $trashId = $this->GetWasteBasket();
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->deleteEmail(): id=%s folder=%s trash=%s',
            $id, $folderid, $trashId ?: 'none'
        ));
        if ($trashId && $folderid !== $trashId) {
            $result = $this->moveEmail($folderid, $id, $trashId);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->deleteEmail(): move to trash %s', $result ? 'OK' : 'FAIL'
            ));
            return $result !== false;
        }
        $r = $this->client->call([
            ['Email/set', ['accountId' => $this->accountId, 'destroy' => [$id]], '0'],
        ]);
        $destroyed = array_key_exists($id, $r[0][1]['destroyed'] ?? []);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->deleteEmail(): destroy %s', $destroyed ? 'OK' : 'FAIL'
        ));
        return $destroyed;
    }

    private function moveEmail(string $folderid, string $id, string $newfolderid): string|false {
        $jid  = $this->jmapId($folderid);
        $njid = $this->jmapId($newfolderid);
        $r = $this->client->call([
            ['Email/set', [
                'accountId' => $this->accountId,
                'update'    => [$id => [
                    "mailboxIds/$jid"  => null,
                    "mailboxIds/$njid" => true,
                ]],
            ], '0'],
        ]);
        $ok = array_key_exists($id, $r[0][1]['updated'] ?? []);
        if (!$ok) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->moveEmail(%s => %s): FAIL. response=%s',
                $folderid, $newfolderid, json_encode($r[0][1] ?? $r[0])
            ));
        }
        return $ok ? $id : false;
    }

    private function emailToStat(array $email): array {
        $kw = $email['keywords'] ?? [];
        return [
            'id'    => $email['id'],
            'flags' => isset($kw[self::KW_SEEN]) ? 1 : 0,
            'mod'   => $this->keywordsHash($kw),
        ];
    }

    private function buildSyncMail(array $email, $contentparameters): SyncMail {
        $output = new SyncMail();
        $output->subject      = $this->sanitizeText($email['subject']  ?? '');
        $output->from         = $this->formatAddressList($email['from']    ?? []);
        $output->to           = $this->formatAddressList($email['to']      ?? []);
        $cc      = $this->formatAddressList($email['cc']      ?? []);
        $bcc     = $this->formatAddressList($email['bcc']     ?? []);
        $replyTo = $this->formatAddressList($email['replyTo'] ?? []);
        if ($cc)      $output->cc       = $cc;
        if ($bcc)     $output->bcc      = $bcc;
        if ($replyTo) $output->reply_to = $replyTo;
        $output->datereceived = $this->parseJmapDate($email['receivedAt']  ?? '');
        $output->messageclass = 'IPM.Note';
        $output->read         = isset($email['keywords'][self::KW_SEEN]) ? 1 : 0;

        $kw = $email['keywords'] ?? [];
        if (!empty($kw[self::KW_FLAGGED])) {
            $flag = new SyncMailFlags();
            // Use flagstatus=2 to match what iOS sends when the user taps the
            // star/flag icon in the email list.  iOS does NOT display the flag
            // icon for flagstatus=1 (which is treated as a follow-up flag with
            // sub-fields, not the simple star toggle).
            $flag->flagstatus = 2;
            $flag->flagtype = 'Active';
            $output->flag = $flag;
        }

        if (!empty($kw[self::KW_DRAFT])) {
            $output->isdraft = 1;
        }

        if (!empty($kw[self::KW_ANSWERED])) {
            $output->lastverbexecuted = 1;
            $output->lastverbexectime = time();
        } elseif (!empty($kw[self::KW_FORWARDED])) {
            $output->lastverbexecuted = 3;
            $output->lastverbexectime = time();
        }

        $categories = [];
        foreach ($kw as $kwName => $kwVal) {
            if ($kwVal && isset($kwName[0]) && $kwName[0] !== '$') {
                $categories[] = $this->sanitizeText($kwName);
            }
        }
        if ($categories) {
            $output->categories = $categories;
        }

        $bodypreference = $contentparameters->GetBodyPreference() ?: [];
        [$bodyType, $bodyData] = $this->selectBody($email, $bodypreference);
        $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

        $output->asbody = new SyncBaseBody();
        $output->asbody->type = $bodyType;
        if ($truncsize !== -1 && strlen($bodyData) > $truncsize) {
            $bodyData = substr($bodyData, 0, $truncsize);
            $output->asbody->truncated = 1;
        } else {
            $output->asbody->truncated = 0;
        }
        $output->asbody->estimatedDataSize = strlen($bodyData);
        $output->asbody->data              = StringStreamWrapper::Open($bodyData);

        foreach ($email['attachments'] ?? [] as $att) {
            $blobId   = $att['blobId'] ?? '';
            $filename = $this->sanitizeText($att['name'] ?? ('attachment-' . substr($blobId, 0, 8)));
            $inline   = strtolower($att['disposition'] ?? '') === 'inline';
            $sa = new SyncBaseAttachment();
            $sa->displayname       = $filename;
            $sa->filereference     = $email['id'] . '||' . $blobId . '||' . $filename;
            $sa->method            = 1;
            $sa->estimatedDataSize = $att['size'] ?? 0;
            $sa->isinline          = $inline ? 1 : 0;
            if (!empty($att['cid'])) $sa->contentid = $att['cid'];
            $output->asattachments[] = $sa;
        }

        return $output;
    }

    /**
     * Re-fetch a single email WITH its body values.  Only used as a fallback
     * when getEmail() skipped the bodies (MIME-preferred client) but the MIME
     * blob download failed.
     */
    private function fetchEmailWithBodies(string $id): array {
        if ($id === '') return [];
        try {
            $r = $this->client->call([
                ['Email/get', [
                    'accountId'          => $this->accountId,
                    'ids'                => [$id],
                    'properties'         => ['id', 'blobId', 'textBody', 'htmlBody', 'bodyValues'],
                    'fetchAllBodyValues' => true,
                    ...(JMAP_MAX_BODY_BYTES > 0 ? ['maxBodyValueBytes' => JMAP_MAX_BODY_BYTES] : []),
                ], '0'],
            ]);
            return $r[0][1]['list'][0] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function selectBody(array $email, array $bodypreference): array {
        $bodyValues = $email['bodyValues'] ?? [];

        if (in_array(SYNC_BODYPREFERENCE_MIME, $bodypreference, true) && !empty($email['blobId'])) {
            $mime = '';
            try {
                $mime = $this->client->downloadBlob($email['blobId'], 'message.eml', 'message/rfc822');
            } catch (\Throwable $e) {
                ZLog::Write(LOGLEVEL_WARN, 'BackendJmap->selectBody(): MIME download failed: ' . $e->getMessage());
            }
            if ($mime !== '') {
                // Serve the original raw MIME whenever it is well-formed, or when
                // the message MUST stay byte-identical (S/MIME signed/encrypted,
                // calendar invitations) so signatures/ICS survive verbatim.
                if ($this->mimeIsClean($mime) || $this->emailNeedsRawMime($email)) {
                    return [SYNC_BODYPREFERENCE_MIME, $mime];
                }
                // Legacy/broken encoding in the raw bytes (e.g. non-encoded 8-bit
                // chars) breaks iOS import and triggers loop detection.  Rebuild a
                // valid UTF-8 MIME from the clean JMAP header/body data instead.
                $clean = $this->buildCleanMime($email);
                if ($clean !== '') {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                        'BackendJmap->selectBody(): rebuilt broken MIME for email %s (%d -> %d bytes)',
                        $email['id'] ?? '?', strlen($mime), strlen($clean)
                    ));
                    return [SYNC_BODYPREFERENCE_MIME, $clean];
                }
            }
            else {
                ZLog::Write(LOGLEVEL_WARN, sprintf('BackendJmap->selectBody(): empty MIME blob for email %s, falling back to HTML/text', $email['id'] ?? '?'));
            }
            // MIME failed / not rebuildable and getEmail() skipped the body values:
            // fetch them so the HTML/text fallback below still has data to render.
            if (empty($bodyValues) && empty($email['htmlBody']) && empty($email['textBody'])) {
                $full = $this->fetchEmailWithBodies($email['id'] ?? '');
                if (!empty($full)) {
                    $email = $full;
                    $bodyValues = $email['bodyValues'] ?? [];
                }
            }
        }

        // Prefer HTML if the client wants it, or if no specific preference (also used as
        // fallback when MIME failed — we try HTML/text regardless of original preference).
        $wantHtml = !$bodypreference
            || in_array(SYNC_BODYPREFERENCE_HTML,  $bodypreference, true)
            || in_array(SYNC_BODYPREFERENCE_MIME,  $bodypreference, true);
        if ($wantHtml && !empty($email['htmlBody'])) {
            $partId = $email['htmlBody'][0]['partId'] ?? null;
            if ($partId && isset($bodyValues[$partId]['value'])) {
                return [SYNC_BODYPREFERENCE_HTML, $bodyValues[$partId]['value']];
            }
        }

        if (!empty($email['textBody'])) {
            $partId = $email['textBody'][0]['partId'] ?? null;
            if ($partId && isset($bodyValues[$partId]['value'])) {
                return [SYNC_BODYPREFERENCE_PLAIN, $bodyValues[$partId]['value']];
            }
        }

        // bodyValues was empty or partIds didn't match — last resort: download raw MIME
        if (!empty($email['blobId'])) {
            try {
                $mime = $this->client->downloadBlob($email['blobId'], 'message.eml', 'message/rfc822');
                if ($mime !== '') {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf('BackendJmap->selectBody(): used MIME fallback for email %s', $email['id'] ?? '?'));
                    return [SYNC_BODYPREFERENCE_MIME, $mime];
                }
            } catch (\Throwable $e) {
                ZLog::Write(LOGLEVEL_WARN, 'BackendJmap->selectBody(): MIME fallback failed: ' . $e->getMessage());
            }
        }

        ZLog::Write(LOGLEVEL_WARN, sprintf(
            'BackendJmap->selectBody(): no usable body for email %s (htmlBody=%d textBody=%d bodyValues=%d)',
            $email['id'] ?? '?',
            count($email['htmlBody'] ?? []),
            count($email['textBody'] ?? []),
            count($bodyValues)
        ));
        return [SYNC_BODYPREFERENCE_PLAIN, ''];
    }

    /**
     * True when the raw MIME is well-formed for delivery: valid UTF-8 and free
     * of the control characters that XML 1.0 / WBXML forbid.  iOS rejects such
     * messages, which shows up as "message was causing loop" + frozen folders.
     */
    private function mimeIsClean(string $mime): bool {
        if (!preg_match('//u', $mime)) {
            return false; // not valid UTF-8 (e.g. legacy iso-8859-1 8-bit bytes)
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $mime)) {
            return false; // XML 1.0 forbidden control characters
        }
        return true;
    }

    /**
     * Messages that must be delivered byte-identical and therefore never get
     * rebuilt: S/MIME signed/encrypted (pkcs7) and calendar invitations.
     */
    private function emailNeedsRawMime(array $email): bool {
        foreach ($email['attachments'] ?? [] as $a) {
            $t = strtolower($a['type'] ?? '');
            if (str_contains($t, 'pkcs7') || $t === 'text/calendar') {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract a text body part (textBody|htmlBody) as a clean UTF-8 string.
     * Prefers the decoded JMAP bodyValues; falls back to downloading the part
     * blob directly (the part itself is addressable by blobId in JMAP) and
     * converting legacy 8-bit charsets (iso-8859-1) to UTF-8.
     */
    private function bodyPartValue(array $email, string $field, array $bodyValues): string {
        $part = $email[$field][0] ?? null;
        if (is_array($part)) {
            $partId = $part['partId'] ?? null;
            if ($partId !== null && isset($bodyValues[$partId]['value'])) {
                return $bodyValues[$partId]['value'];
            }
            $blobId = $part['blobId'] ?? null;
            if ($blobId) {
                return $this->bodyPartFromBlob($blobId);
            }
        }
        // Some servers surface bodies only in bodyValues without part listing.
        if (!$part && !empty($bodyValues)) {
            foreach ($bodyValues as $val) {
                if (isset($val['value']) && $val['value'] !== '') {
                    return $val['value'];
                }
            }
        }
        return '';
    }

    /**
     * Download a MIME body part blob and return it as clean UTF-8, converting
     * legacy 8-bit (iso-8859-1) bytes if the raw data is not valid UTF-8.
     */
    private function bodyPartFromBlob(string $blobId): string {
        try {
            $raw = $this->client->downloadBlob($blobId, 'body', 'text/plain');
        } catch (\Throwable) {
            return '';
        }
        if ($raw === '') {
            return '';
        }
        if (preg_match('//u', $raw)) {
            return $raw; // already clean UTF-8
        }
        $conv = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $raw);
        return is_string($conv) ? $conv : '';
    }

    /**
     * Rebuild a well-formed UTF-8 MIME message from clean JMAP data (headers +
     * textBody/htmlBody).  Mirrors the IMAP backend's build_mime_message():
     * headers are RFC2047-encoded, text parts are base64/UTF-8.  Regular
     * attachments are NOT embedded - they stay available via asattachments.
     */
    private function buildCleanMime(array $email): string {
        $id = $email['id'] ?? '';
        $full = $email;
        if (empty($full['htmlBody']) && empty($full['textBody'])) {
            $fetched = $this->fetchEmailWithBodies($id);
            if (!empty($fetched)) {
                $full = array_merge($email, $fetched);
            }
        }
        $bodyValues = $full['bodyValues'] ?? [];

        $text = $this->bodyPartValue($full, 'textBody', $bodyValues);
        $html = $this->bodyPartValue($full, 'htmlBody', $bodyValues);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->buildCleanMime(): email %s text/plain=%d text/html=%d bodyValues=%s',
            $id,
            strlen($text), strlen($html),
            json_encode(array_keys($bodyValues))
        ));
        if ($text === '' && $html === '') {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->buildCleanMime(): could not obtain body text for email %s; not rebuilding', $id
            ));
            return '';
        }

        $eol      = "\r\n";
        $boundary = '----=_zpush_' . bin2hex(random_bytes(16));

        $headers = ['MIME-Version: 1.0'];
        $date = $this->parseJmapDate($full['receivedAt'] ?? '');
        if ($date) {
            $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s O', $date);
        }
        $subject = $this->sanitizeText($full['subject'] ?? '');
        if ($subject !== '') {
            $headers[] = 'Subject: ' . $this->mimeHeaderValue($subject);
        }
        foreach ([['From', 'from'], ['To', 'to'], ['Cc', 'cc'], ['Bcc', 'bcc'], ['Reply-To', 'replyTo']] as [$label, $field]) {
            $addr = $this->mimeAddressHeader($full[$field] ?? []);
            if ($addr !== '') {
                $headers[] = $label . ': ' . $addr;
            }
        }
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'Content-Transfer-Encoding: 7bit';

        $body = '';
        if ($text !== '') {
            $body .= '--' . $boundary . $eol
                   . 'Content-Type: text/plain; charset=UTF-8' . $eol
                   . 'Content-Transfer-Encoding: base64' . $eol . $eol
                   . chunk_split(base64_encode($text), 76, $eol) . $eol;
        }
        if ($html !== '') {
            $body .= '--' . $boundary . $eol
                   . 'Content-Type: text/html; charset=UTF-8' . $eol
                   . 'Content-Transfer-Encoding: base64' . $eol . $eol
                   . chunk_split(base64_encode($html), 76, $eol) . $eol;
        }
        $body .= '--' . $boundary . '--' . $eol;

        return implode($eol, $headers) . $eol . $eol . $body;
    }

    /**
     * RFC2047-encode a header value when it contains non-ASCII characters.
     */
    private function mimeHeaderValue(string $value): string {
        // Never emit a raw CR/LF into a generated header - that would be a
        // header-injection vector (attacker-controlled subject/address names).
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (!preg_match('/[\x80-\xFF]/', $value)) {
            return $value;
        }
        return mb_encode_mimeheader($value, 'UTF-8', 'B');
    }

    /**
     * Format a JMAP address list as a RFC2822 header value with encoded names.
     */
    private function mimeAddressHeader(array $addresses): string {
        $parts = [];
        foreach ($addresses as $addr) {
            $email = $this->sanitizeText($addr['email'] ?? '');
            $name  = $this->sanitizeText($addr['name']  ?? '');
            if ($email === '' && $name === '') {
                continue;
            }
            if ($name !== '') {
                $parts[] = '"' . $this->mimeHeaderValue($name) . '" <' . $email . '>';
            } else {
                $parts[] = $email;
            }
        }
        return implode(', ', $parts);
    }

    // =========================================================================
    // CONTACTS helpers
    // =========================================================================

    private function listContacts(string $abId): array {
        $r = $this->client->call([
            ['ContactCard/query', [
                'accountId' => $this->accountId,
                'filter'    => ['inAddressBook' => $abId],
                'limit'     => JMAP_MAX_OBJECTS_PER_REQUEST,
            ], 'q'],
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                'properties' => ['id', 'name', 'emails', 'phones', 'addresses', 'nicknames', 'organizations', 'titles', 'notes', 'onlineServices', 'links', 'anniversaries', 'relations', 'categories', 'media', 'updated', 'children', 'officeLocation', 'customerId', 'governmentId', 'accountName', 'yomiFirstName', 'yomiLastName', 'yomiCompanyName'],
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        return array_map(fn($c) => [
            'id'    => $c['id'],
            'flags' => 0,
            'mod'   => JmapContactConverter::cardModHash($c),
        ], $r[1][1]['list'] ?? []);
    }

    private function getContact(string $id): SyncContact|false {
        $r = $this->client->call([
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => null,
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        $card = $r[0][1]['list'][0] ?? null;
        if (!$card) return false;

        $contact = JmapContactConverter::cardToSyncContact($card);

        // Log problematic contacts where name fields could not be recovered
        if (empty($contact->firstname) && empty($contact->lastname)) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->getContact(): missing firstname/lastname for %s. name in card: %s',
                $id, json_encode($card['name'] ?? null)
            ));
        }

        // Read contact photo from `media` (Stalwart) or `photos` (JSContact standard).
        // Stalwart stores photos as data URIs: data:image/jpeg;base64,<base64data>
        $photoEntry = null;
        if (!empty($card['media'])) {
            $photoEntry = reset($card['media']);
        } elseif (!empty($card['photos'])) {
            $photoEntry = reset($card['photos']);
        }

        if ($photoEntry && !empty($photoEntry['uri'])) {
            $commaPos = strpos($photoEntry['uri'], ',');
            if ($commaPos !== false) {
                $rawBase64 = substr($photoEntry['uri'], $commaPos + 1);
                try {
                    $rawData = base64_decode($rawBase64, true);
                    if ($rawData !== false) {
                        $contact->picture = self::processContactPhoto($rawData);
                    }
                } catch (\Throwable $e) {
                    ZLog::Write(LOGLEVEL_WARN, sprintf(
                        'BackendJmap->getContact(): photo processing failed for %s, using original: %s',
                        $id, $e->getMessage()
                    ));
                    $contact->picture = $rawBase64;
                }
            }
        }

        return $contact;
    }

    private function statContact(string $id): array|false {
        $r = $this->client->call([
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => ['id', 'name', 'emails', 'phones', 'addresses', 'nicknames', 'organizations', 'titles', 'notes', 'onlineServices', 'links', 'anniversaries', 'relations', 'categories', 'media', 'updated', 'children', 'officeLocation', 'customerId', 'governmentId', 'accountName', 'yomiFirstName', 'yomiLastName', 'yomiCompanyName'],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        $card = $r[0][1]['list'][0] ?? null;
        if (!$card) return false;
        return ['id' => $card['id'], 'flags' => 0, 'mod' => JmapContactConverter::cardModHash($card)];
    }

    /**
     * Fetch the full raw ContactCard from the server, or null if not found.
     */
    private function fetchContactCard(string $id): ?array {
        $r = $this->client->call([
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => null,
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return $r[0][1]['list'][0] ?? null;
    }

    private function saveContact(string $abId, ?string $id, SyncContact $contact): string|false {
        $card = JmapContactConverter::syncContactToCard($contact, $abId);

        // Handle contact picture: set, update, or remove.
        // Stalwart stores photos as data URIs under `media`, not `photos` (JSContact standard).
        if (isset($contact->picture)) {
            if ($contact->picture !== '' && $contact->picture !== null) {
                try {
                    $data = base64_decode($contact->picture, true);
                    if ($data !== false && strlen($data) > 0) {
                        $mime = self::detectImageMimeType($data);
                        $card['media'] = ['p1' => [
                            'uri'  => 'data:' . $mime . ';base64,' . base64_encode($data),
                            'kind' => 'photo',
                        ]];
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                            'BackendJmap->saveContact(): set photo as data URI for %s',
                            $id ?? 'new'
                        ));
                    } else {
                        $card['media'] = null;
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                            'BackendJmap->saveContact(): clearing photo for %s (decoded empty)',
                            $id ?? 'new'
                        ));
                    }
                } catch (\Throwable $e) {
                    ZLog::Write(LOGLEVEL_WARN, sprintf(
                        'BackendJmap->saveContact(): photo processing failed for %s: %s',
                        $id ?? 'new', $e->getMessage()
                    ));
                }
            } else {
                // Photo explicitly cleared by the device
                $card['media'] = null;
                ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                    'BackendJmap->saveContact(): clearing photo for %s (explicit)',
                    $id ?? 'new'
                ));
            }
        }

        if ($id) {
            // Fetch existing card and deep-merge device fields on top, so
            // server-only fields and map entries (birthdays, custom
            // properties etc.) are preserved even if the device didn't send them.
            $existing = $this->fetchContactCard($id);
            if ($existing !== null) {
                $card = self::mergeJmapPatch($existing, $card);
            }

            $r = $this->client->call([
                ['ContactCard/set', [
                    'accountId' => $this->accountId,
                    'update'    => [$id => $card],
                ], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
            $resp = $r[0][1] ?? [];
            if (isset($resp['notUpdated'][$id])) {
                ZLog::Write(LOGLEVEL_WARN, sprintf(
                    'BackendJmap->saveContact(): update failed for %s: %s',
                    $id, json_encode($resp['notUpdated'][$id])
                ));
                return false;
            }
            if (!array_key_exists($id, $resp['updated'] ?? [])) {
                ZLog::Write(LOGLEVEL_WARN, sprintf(
                    'BackendJmap->saveContact(): update response missing %s in updated/notUpdated. Response: %s',
                    $id, json_encode($resp)
                ));
                return false;
            }
            return $id;
        }

        $r = $this->client->call([
            ['ContactCard/set', [
                'accountId' => $this->accountId,
                'create'    => ['c1' => $card],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return $r[0][1]['created']['c1']['id'] ?? false;
    }

    private function destroyContact(string $id): bool {
        $r = $this->client->call([
            ['ContactCard/set', [
                'accountId' => $this->accountId,
                'destroy'   => [$id],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        $resp = $r[0][1] ?? [];
        if (isset($resp['notDestroyed'][$id])) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->destroyContact(): failed for %s: %s',
                $id, json_encode($resp['notDestroyed'][$id])
            ));
            return false;
        }
        return array_key_exists($id, $resp['destroyed'] ?? []);
    }

    // =========================================================================
    // CALENDAR helpers
    // =========================================================================

    private function listCalendarEvents(string $calId, int $cutoffdate): array {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->listCalendarEvents(): calId=%s cutoffdate=%d', $calId, $cutoffdate
        ));
        $filter = ['inCalendar' => $calId];
        if ($cutoffdate > 0) {
            $filter['after'] = gmdate('Y-m-d\TH:i:s\Z', $cutoffdate);
        }

        // Paginate through all events, newest first
        $allIds    = [];
        $pageStart = 0;
        $maxPages  = 20; // safety cap: 20 * 500 = 10000 events max
        for ($page = 0; $page < $maxPages; $page++) {
            $q = [
                'accountId'      => $this->accountId,
                'filter'         => $filter,
                'limit'          => JMAP_MAX_OBJECTS_PER_REQUEST,
                'sort'           => [['property' => 'start', 'isAscending' => false]],
                'calculateTotal' => true,
            ];
            if ($pageStart > 0) {
                $q['position'] = $pageStart;
            }
            $r = $this->client->call([
                ['CalendarEvent/query', $q, 'q'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
            $resp     = $r[0][1] ?? [];
            $ids      = $resp['ids'] ?? [];
            $total    = $resp['total'] ?? 0;
            $allIds   = array_merge($allIds, $ids);
            $count    = count($ids);
            if ($count < JMAP_MAX_OBJECTS_PER_REQUEST) break;
            if ($total > 0 && $pageStart + $count >= $total) break;
            $pageStart += $count;
            // Small delay to avoid rate limiting
            usleep(50000);
        }

        if (empty($allIds)) return [];

        // Fetch details for all collected IDs in batches
        $events = [];
        foreach (array_chunk($allIds, JMAP_MAX_OBJECTS_PER_REQUEST) as $chunk) {
            $r = $this->client->call([
                ['CalendarEvent/get', [
                    'accountId'  => $this->accountId,
                    'ids'        => $chunk,
                    'properties' => ['id', 'title', 'start', 'duration', 'description', 'locations', 'participants', 'privacy', 'freeBusyStatus', 'categories', 'recurrenceRules', 'showWithoutTime', 'alerts', 'updated'],
                ], 'g'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
            $events = array_merge($events, $r[0][1]['list'] ?? []);
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->listCalendarEvents(): got %d events from JMAP (total IDs: %d)',
            count($events), count($allIds)
        ));
        return array_map(fn($e) => [
            'id'    => $e['id'],
            'flags' => 0,
            'mod'   => JmapCalendarConverter::eventModHash($e),
        ], $events);
    }

    private function getCalendarEvent(string $id): SyncAppointment|false {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf('BackendJmap->getCalendarEvent(): id=%s', $id));
        $r = $this->client->call([
            ['CalendarEvent/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => null,
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        $event = $r[0][1]['list'][0] ?? null;
        if (!$event) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap->getCalendarEvent(): event %s not found, response: %s',
                $id, json_encode($r[0][1] ?? [])
            ));
            return false;
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->getCalendarEvent(): got event "%s"', $event['title'] ?? '(no title)'
        ));
        return JmapCalendarConverter::eventToSyncAppointment($event);
    }

    private function statCalendarEvent(string $id): array|false {
        $r = $this->client->call([
            ['CalendarEvent/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => ['id', 'title', 'start', 'duration', 'description', 'locations', 'participants', 'privacy', 'freeBusyStatus', 'categories', 'recurrenceRules', 'showWithoutTime', 'alerts', 'updated'],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        $event = $r[0][1]['list'][0] ?? null;
        if (!$event) return false;
        return ['id' => $event['id'], 'flags' => 0, 'mod' => JmapCalendarConverter::eventModHash($event)];
    }

    /**
     * Fetch the full raw CalendarEvent from the server, or null if not found.
     */
    private function fetchCalendarEvent(string $id): ?array {
        $r = $this->client->call([
            ['CalendarEvent/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => null,
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return $r[0][1]['list'][0] ?? null;
    }

    private function saveCalendarEvent(string $calId, ?string $id, SyncAppointment $appt): string|false {
        $event = JmapCalendarConverter::syncAppointmentToEvent($appt, $calId);

        if ($id) {
            // Fetch existing event to preserve server-only fields
            $existing = $this->fetchCalendarEvent($id);
            if ($existing !== null) {
                foreach ($existing as $k => $v) {
                    if (!array_key_exists($k, $event)) {
                        $event[$k] = $v;
                    }
                }
            }

            $r = $this->client->call([
                ['CalendarEvent/set', [
                    'accountId' => $this->accountId,
                    'update'    => [$id => $event],
                ], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
            $resp = $r[0][1] ?? [];
            if (isset($resp['notUpdated'][$id])) {
                ZLog::Write(LOGLEVEL_WARN, sprintf(
                    'BackendJmap->saveCalendarEvent(): update failed for %s: %s',
                    $id, json_encode($resp['notUpdated'][$id])
                ));
                return false;
            }
            return array_key_exists($id, $resp['updated'] ?? []) ? $id : false;
        }

        $r = $this->client->call([
            ['CalendarEvent/set', [
                'accountId' => $this->accountId,
                'create'    => ['ev1' => $event],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        return $r[0][1]['created']['ev1']['id'] ?? false;
    }

    private function destroyCalendarEvent(string $id): bool {
        $r = $this->client->call([
            ['CalendarEvent/set', ['accountId' => $this->accountId, 'destroy' => [$id]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return array_key_exists($id, $r[0][1]['destroyed'] ?? []);
    }

    private function moveCalendarEvent(string $id, string $fromCalId, string $toCalId): string|false {
        $r = $this->client->call([
            ['CalendarEvent/set', [
                'accountId' => $this->accountId,
                'update'    => [$id => [
                    "calendarIds/$fromCalId" => null,
                    "calendarIds/$toCalId"   => true,
                ]],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return array_key_exists($id, $r[0][1]['updated'] ?? []) ? $id : false;
    }

    // =========================================================================
    // Folder CRUD helpers
    // =========================================================================

    private function getMailboxAsFolder(string $id): SyncFolder|false {
        $jid = $this->jmapId($id);
        $r = $this->client->call([
            ['Mailbox/get', ['accountId' => $this->accountId, 'ids' => [$jid]], '0'],
        ]);
        $mb = $r[0][1]['list'][0] ?? null;
        if (!$mb) return false;
        $f = new SyncFolder();
        $f->serverid    = $id;
        $f->parentid    = isset($mb['parentId']) ? self::PFX_MAILBOX . $mb['parentId'] : '0';
        $f->displayname = match($mb['role'] ?? '') {
            'inbox'   => 'Inbox',
            'drafts'  => 'Drafts',
            'sent'    => 'Sent Items',
            'trash'   => 'Deleted Items',
            'junk', 'spam' => 'Junk',
            default   => $mb['name'],
        };
        $f->type        = self::ROLE_TO_AS_TYPE[$mb['role'] ?? ''] ?? SYNC_FOLDER_TYPE_USER_MAIL;
        return $f;
    }

    private function getAddressBookAsFolder(string $id): SyncFolder|false {
        $jid = $this->jmapId($id);
        $r   = $this->client->call([
            ['AddressBook/get', ['accountId' => $this->accountId, 'ids' => [$jid]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        $ab = $r[0][1]['list'][0] ?? null;
        if (!$ab) return false;
        $f = new SyncFolder();
        $f->serverid    = $id;
        $f->parentid    = '0';
        $f->displayname = $ab['name'];
        $f->type        = !empty($ab['isDefault']) ? SYNC_FOLDER_TYPE_CONTACT : SYNC_FOLDER_TYPE_USER_CONTACT;
        return $f;
    }

    private function getCalendarAsFolder(string $id): SyncFolder|false {
        $jid = $this->jmapId($id);
        $r   = $this->client->call([
            ['Calendar/get', ['accountId' => $this->accountId, 'ids' => [$jid]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        $cal = $r[0][1]['list'][0] ?? null;
        if (!$cal) return false;
        $f = new SyncFolder();
        $f->serverid    = $id;
        $f->parentid    = '0';
        $f->displayname = $cal['name'];
        $f->type        = !empty($cal['isDefault']) ? SYNC_FOLDER_TYPE_APPOINTMENT : SYNC_FOLDER_TYPE_USER_APPOINTMENT;
        return $f;
    }

    private function changeMailbox(string $folderid, ?string $oldid, string $name): string|false {
        if ($oldid) {
            $jid = $this->jmapId($oldid);
            $r = $this->client->call([
                ['Mailbox/set', ['accountId' => $this->accountId, 'update' => [$jid => ['name' => $name]]], '0'],
            ]);
            return array_key_exists($jid, $r[0][1]['updated'] ?? []) ? $oldid : false;
        }
        $create = ['name' => $name];
        if ($folderid) $create['parentId'] = $this->jmapId($folderid);
        $r = $this->client->call([
            ['Mailbox/set', ['accountId' => $this->accountId, 'create' => ['n1' => $create]], '0'],
        ]);
        $newId = $r[0][1]['created']['n1']['id'] ?? null;
        return $newId ? self::PFX_MAILBOX . $newId : false;
    }

    private function changeAddressBook(?string $oldid, string $name): string|false {
        if ($oldid) {
            $jid = $this->jmapId($oldid);
            $r   = $this->client->call([
                ['AddressBook/set', ['accountId' => $this->accountId, 'update' => [$jid => ['name' => $name]]], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
            return array_key_exists($jid, $r[0][1]['updated'] ?? []) ? $oldid : false;
        }
        $r = $this->client->call([
            ['AddressBook/set', ['accountId' => $this->accountId, 'create' => ['ab1' => ['name' => $name]]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        $newId = $r[0][1]['created']['ab1']['id'] ?? null;
        return $newId ? self::PFX_CONTACTS . $newId : false;
    }

    private function changeCalendar(?string $oldid, string $name): string|false {
        if ($oldid) {
            $jid = $this->jmapId($oldid);
            $r   = $this->client->call([
                ['Calendar/set', ['accountId' => $this->accountId, 'update' => [$jid => ['name' => $name]]], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
            return array_key_exists($jid, $r[0][1]['updated'] ?? []) ? $oldid : false;
        }
        $r = $this->client->call([
            ['Calendar/set', ['accountId' => $this->accountId, 'create' => ['cal1' => ['name' => $name]]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        $newId = $r[0][1]['created']['cal1']['id'] ?? null;
        return $newId ? self::PFX_CALENDAR . $newId : false;
    }

    private function deleteMailbox(string $id): bool {
        $jid = $this->jmapId($id);
        $r = $this->client->call([
            ['Mailbox/set', ['accountId' => $this->accountId, 'destroy' => [$jid], 'onDestroyRemoveEmails' => true], '0'],
        ]);
        return array_key_exists($jid, $r[0][1]['destroyed'] ?? []);
    }

    private function deleteAddressBook(string $id): bool {
        $jid = $this->jmapId($id);
        $r   = $this->client->call([
            ['AddressBook/set', ['accountId' => $this->accountId, 'destroy' => [$jid]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return array_key_exists($jid, $r[0][1]['destroyed'] ?? []);
    }

    private function deleteCalendar(string $id): bool {
        $jid = $this->jmapId($id);
        $r   = $this->client->call([
            ['Calendar/set', ['accountId' => $this->accountId, 'destroy' => [$jid]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return array_key_exists($jid, $r[0][1]['destroyed'] ?? []);
    }

    // =========================================================================
    // Private utility helpers
    // =========================================================================

    private function folderType(string $folderid): string {
        if (str_starts_with($folderid, self::PFX_CONTACTS)) return 'contacts';
        if (str_starts_with($folderid, self::PFX_CALENDAR)) return 'calendar';
        return 'mail';
    }

    private function jmapId(string $folderid): string {
        foreach ([self::PFX_CONTACTS, self::PFX_CALENDAR, self::PFX_MAILBOX] as $pfx) {
            if (str_starts_with($folderid, $pfx)) return substr($folderid, strlen($pfx));
        }
        return $folderid;
    }

    private function getAllMailboxes(): array {
        $r = $this->client->call([
            ['Mailbox/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ]);
        return $r[0][1]['list'] ?? [];
    }

    private function getAllAddressBooks(): array {
        $r = $this->client->call([
            ['AddressBook/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return $r[0][1]['list'] ?? [];
    }

    private function getAllCalendars(): array {
        $r = $this->client->call([
            ['Calendar/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        $list = $r[0][1]['list'] ?? [];
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->getAllCalendars(): found %d calendars: %s',
            count($list), json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'] ?? '', 'isDefault' => !empty($c['isDefault'])], $list))
        ));
        return $list;
    }

    /**
     * Return a change-tracking fingerprint for the folder.
     * Detects structural changes (add/remove) via queryState AND
     * property/keyword changes via the account-level Email state.
     */
    private function getFolderQueryState(string $folderid): string {
        return match($this->folderType($folderid)) {
            'contacts' => $this->contactQueryState($this->jmapId($folderid)),
            'calendar' => $this->calendarQueryState($this->jmapId($folderid)),
            default    => $this->emailQueryState($folderid),
        };
    }

    /**
     * Track total emails + queryState to ignore keyword-only modifications.
     */
    private function emailQueryState(string $folderid): string {
        $jid = $this->jmapId($folderid);
        $r = $this->client->call([
            ['Email/query', ['accountId' => $this->accountId, 'filter' => ['inMailbox' => $jid], 'limit' => 1], 'q'],
        ]);
        $resp       = $r[0][1] ?? [];
        $total      = $resp['total'] ?? 0;
        $queryState = $resp['queryState'] ?? '';

        // Also fetch the account-level email state, which changes for ALL
        // modifications including keyword-only changes (flags, categories, etc.)
        // We use any known email ID — the returned 'state' reflects ALL emails.
        $emailState = '';
        $ids = $resp['ids'] ?? [];
        if (!empty($ids)) {
            try {
                $r2 = $this->client->call([
                    ['Email/get', [
                        'accountId' => $this->accountId,
                        'ids'       => [$ids[0]],
                        'properties' => ['id'],
                    ], 'g'],
                ]);
                $emailState = $r2[0][1]['state'] ?? '';
            } catch (\Throwable) {
                // fallback: just use queryState
            }
        }
        $result = $total . '@' . $queryState . '#' . $emailState;
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            'BackendJmap->emailQueryState(%s): %s', $folderid, $result
        ));
        return $result;
    }

    /**
     * Fetch account-level email state + total email count + queryState
     * in a single batch call.  Changes to ANY of these values indicate
     * that emails have been added, removed, or modified.
     *
     * Returns a combined fingerprint:  {Email/get state}#{total emails}@{Email/query state}
     * The Email/query (limit=1, no filter) is a cheap way to detect
     * additions/removals even if Stalwart's Email/get state does not
     * change reliably.
     */
    private function fetchEmailAccountState(): string {
        try {
            $r = $this->client->call([
                ['Email/get', [
                    'accountId'  => $this->accountId,
                    'ids'        => [],
                    'properties' => ['id'],
                ], 'g'],
                ['Email/query', [
                    'accountId' => $this->accountId,
                    'limit'     => 1,
                ], 'q'],
            ]);
            $getState   = '';
            $total      = 0;
            $queryState = '';
            foreach ($r as $resp) {
                $cid = $resp[2] ?? '';
                if ($cid === 'g') {
                    $getState = $resp[1]['state'] ?? '';
                } elseif ($cid === 'q') {
                    $total      = $resp[1]['total'] ?? 0;
                    $queryState = $resp[1]['queryState'] ?? '';
                }
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->fetchEmailAccountState(): accountId=%s raw=%s -> state=%s total=%s queryState=%s',
                $this->accountId, json_encode($r), $getState, $total, $queryState
            ));
            return $getState . '#' . $total . '@' . $queryState;
        } catch (\Throwable) {
            return '';
        }
    }

    private function fetchMailboxListState(): string {
        try {
            $r = $this->client->call([
                ['Mailbox/get', [
                    'accountId'  => $this->accountId,
                    'ids'        => [],
                ], 'm'],
            ]);
            return $r[0][1]['state'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function contactQueryState(string $abId): string {
        $r = $this->client->call([
            ['ContactCard/query', ['accountId' => $this->accountId, 'filter' => ['inAddressBook' => $abId], 'limit' => 1], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return $r[0][1]['queryState'] ?? '';
    }

    private function calendarQueryState(string $calId): string {
        $r = $this->client->call([
            ['CalendarEvent/query', ['accountId' => $this->accountId, 'filter' => ['inCalendar' => $calId], 'limit' => 1], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return $r[0][1]['queryState'] ?? '';
    }

    private function getIdentityId(): string {
        if ($this->identityId === '') {
            $this->identityId = $this->fetchPrimaryIdentityId();
        }
        return $this->identityId;
    }

    private function fetchPrimaryIdentityId(): string {
        $r        = $this->client->call([
            ['Identity/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL, JmapClient::CAP_SUBMIT]);
        $identity = $r[0][1]['list'][0] ?? [];
        $this->identityName = $identity['name'] ?? '';
        return $identity['id'] ?? '';
    }

    private function getIdentityName(): string {
        $this->getIdentityId(); // ensure lazy load
        return $this->identityName;
    }

    private function rewriteFromHeader(string $mime, string $name): string {
        $eol = str_contains($mime, "\r\n") ? "\r\n" : "\n";
        $pattern = '/^(From:[ \t]*)(.+?)(?=' . preg_quote($eol, '/') . '(?![ \t]))/ms';
        return preg_replace_callback($pattern, function ($m) use ($name, $eol) {
            $val = preg_replace('/' . preg_quote($eol, '/') . '[ \t]+/', ' ', $m[2]);
            if (preg_match('/<([^>]+)>/', $val, $em)) {
                $email = $em[1];
            } elseif (preg_match('/\S+@\S+/', $val, $em)) {
                $email = trim($em[0]);
            } else {
                return $m[0];
            }
            $quotedName = str_replace(['"', '\\'], ['\\"', '\\\\'], $name);
            return 'From: "' . $quotedName . '" <' . $email . '>';
        }, $mime);
    }

    private function getMailboxIdByRole(string $role): string|false {
        foreach ($this->getAllMailboxes() as $mb) {
            if (($mb['role'] ?? '') === $role) return $mb['id'];
        }
        return false;
    }

    private function getSentMailboxId(): string|false {
        return $this->getMailboxIdByRole('sent');
    }

    private function setKeywords(string $emailId, array $keywords): bool {
        $patch = [];
        foreach ($keywords as $kw => $value) {
            $patch["keywords/$kw"] = $value ?: null;
        }
        $r = $this->client->call([
            ['Email/set', ['accountId' => $this->accountId, 'update' => [$emailId => $patch]], '0'],
        ]);
        $resp = $r[0][1] ?? [];
        if (array_key_exists($emailId, $resp['updated'] ?? [])) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->setKeywords(%s): OK patch=%s', $emailId, json_encode($patch)
            ));
            return true;
        }
        ZLog::Write(LOGLEVEL_WARN, sprintf(
            'BackendJmap->setKeywords(%s): not updated. patch=%s response=%s',
            $emailId, json_encode($patch), json_encode($resp)
        ));
        return false;
    }

    private function sanitizeText(string $s): string {
        // Ensure well-formed UTF-8, dropping invalid byte sequences.  Invalid
        // UTF-8 / XML abort chars in a header make the whole ActiveSync/WBXML
        // response unparsable for iOS, which then fails to import the message
        // (and triggers loop-detection -> frozen folder export).
        if (!preg_match('//u', $s)) {
            $s = @iconv('UTF-8', 'UTF-8//IGNORE', $s) ?: '';
        }
        // Headers occasionally arrive HTML-encoded (e.g. "verifi&euml;ren").
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // XML 1.0 forbids most C0 control chars; strip them (keep \t \n \r).
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? $s;
    }

    private function formatAddressList(array $addresses): string {
        $parts = [];
        foreach ($addresses as $addr) {
            $email = $this->sanitizeText($addr['email'] ?? '');
            $name  = $this->sanitizeText($addr['name']  ?? '');
            if (!$email && !$name) continue;
            $parts[] = $name ? '"' . addslashes($name) . '" <' . $email . '>' : $email;
        }
        return implode(', ', $parts);
    }

    private function parseJmapDate(string $date): int {
        if (!$date) return 0;
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $date, new \DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : 0;
    }

    private function keywordsHash(array $keywords): string {
        $keys = array_keys($keywords);
        sort($keys);
        return sprintf('%08x', crc32(implode(',', $keys)));
    }

    private function extToMime(string $ext): string {
        static $map = [
            'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif', 'txt' => 'text/plain',
            'html' => 'text/html', 'htm' => 'text/html', 'zip' => 'application/zip',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ics' => 'text/calendar', 'vcf' => 'text/vcard', 'eml' => 'message/rfc822',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * Deep-merge a device patch into the existing server card so that
     * server-only fields (metadata, custom properties, etc.) are preserved
     * while the device's changes take effect.
     *
     * For most Map-type fields individual entries are merged by key so that
     * entries the device did not send are retained.
     *
     * For anniversaries the merge is done by matching on (kind/type) so
     * that a device update for "birth" overwrites the existing birth entry
     * rather than creating a duplicate with a different map key.
     */
    private static function mergeJmapPatch(array $existing, array $patch): array {
        $result = $existing;
        foreach ($patch as $key => $value) {
            if (!is_array($value)) {
                $result[$key] = $value;
                continue;
            }
            if (!is_array($result[$key] ?? null)) {
                $result[$key] = $value;
                continue;
            }
            if ($key === 'anniversaries') {
                // Only merge entries that ActiveSync manages:
                //   patch "birth"        ↔ existing entries with kind containing "birth"
                //   patch "anniversary"  ↔ existing entries with kind "anniversary"/"wedding"
                // All other kinds (death, other, etc.) are left untouched.
                $existingEntries = $result[$key];
                $consumedPatch   = [];
                $consumedExisting= [];
                $merged          = [];
                foreach ($existingEntries as $ek => $ee) {
                    $ekind   = strtolower($ee['kind'] ?? $ee['type'] ?? '');
                    $matched = false;
                    foreach ($value as $nk => $ne) {
                        if (in_array($nk, $consumedPatch, true)) continue;
                        $nkind = strtolower($ne['kind'] ?? $ne['type'] ?? '');
                        $match = false;
                        if ($nkind !== '' && $ekind !== '') {
                            if (str_contains($nkind, 'birth') || str_contains($ekind, 'birth')) {
                                // Both contain "birth" → match
                                $match = (str_contains($nkind, 'birth') && str_contains($ekind, 'birth'));
                            } elseif (($nkind === 'anniversary' || $nkind === 'wedding') &&
                                      ($ekind  === 'anniversary' || $ekind  === 'wedding')) {
                                $match = true;
                            }
                        }
                        if ($match) {
                            $merged[$ek] = array_merge($ee, $ne);
                            $merged[$ek]['kind'] = $ee['kind'] ?? $ne['kind'];
                            $consumedPatch[]    = $nk;
                            $consumedExisting[] = $ek;
                            $matched = true;
                            break;
                        }
                    }
                    // Unmatched non-managed entries (death, other…) are always
                    // kept.  Managed entries (birth, anniversary, wedding)
                    // without a patch match are considered deleted (the phone
                    // has exactly 1 slot per type, so they can't be "beyond
                    // view").
                    if (!$matched) {
                        $m = (stripos($ekind, 'birth') !== false) ||
                             $ekind === 'anniversary' || $ekind === 'wedding';
                        if (!$m) $merged[$ek] = $ee;
                    }
                }
                // Add any patch entry that didn't match an existing entry.
                foreach ($value as $nk => $ne) {
                    if (!in_array($nk, $consumedPatch, true)) {
                        $nkind = strtolower($ne['kind'] ?? $ne['type'] ?? '');
                        $isManaged = str_contains($nkind, 'birth') ||
                                     $nkind === 'anniversary' ||
                                     $nkind === 'wedding';
                        if ($isManaged) {
                            $merged[$nk] = $ne;
                        }
                        // Non-managed patch entries (should never occur) are dropped.
                    }
                }
                $result[$key] = $merged;
            } elseif ($key === 'addresses') {
                // Match addresses by context (work / home / other) so that
                // server entries with different map keys are updated
                // in-place rather than duplicated.
                $existingEntries = $result[$key];
                $consumedPatch   = [];
                $merged          = [];
                $orphans         = [];
                foreach ($existingEntries as $ek => $ee) {
                    $eCtx = isset($ee['contexts']['work']) ? 'work'
                          : (isset($ee['contexts']['private']) || isset($ee['contexts']['home']) ? 'home' : null);
                    $matched = false;
                    foreach ($value as $nk => $ne) {
                        if (in_array($nk, $consumedPatch, true)) continue;
                        $nCtx = isset($ne['contexts']['work']) ? 'work'
                              : (isset($ne['contexts']['private']) || isset($ne['contexts']['home']) ? 'home' : null);
                        if ($nCtx !== null && $nCtx === $eCtx) {
                            $merged[$ek] = array_merge($ee, $ne);
                            // Deep-merge components: keep server-only
                            // component kinds that the phone doesn't produce
                            // (e.g. "name", "number" which the phone merges
                            // into a single "street" component).
                            if (isset($ee['components']) && isset($ne['components'])) {
                                $patchKinds = [];
                                $patchValues = [];
                                foreach ($ne['components'] as $nc) {
                                    if (isset($nc['kind'])) $patchKinds[] = $nc['kind'];
                                    if (isset($nc['value'])) $patchValues[] = $nc['value'];
                                }
                                foreach ($ee['components'] as $ec) {
                                    if (!isset($ec['kind']) || in_array($ec['kind'], $patchKinds, true)) continue;
                                    // Skip server-only components whose VALUE already appears in the
                                    // patch (e.g. server stores street under "name" kind and phone
                                    // writes it back as "street" kind → would duplicate each cycle).
                                    if (isset($ec['value']) && in_array($ec['value'], $patchValues, true)) continue;
                                    $merged[$ek]['components'][] = $ec;
                                }
                            }
                            $consumedPatch[] = $nk;
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) $orphans[$ek] = $ee;
                }
                // Positional fallback for renames (e.g. work→home)
                $newcomers = [];
                foreach ($value as $nk => $ne) {
                    if (!in_array($nk, $consumedPatch, true)) $newcomers[$nk] = $ne;
                }
                $orphanKeys = array_keys($orphans);
                $newcomerKeys = array_keys($newcomers);
                $pairCount = min(count($orphanKeys), count($newcomerKeys));
                for ($i = 0; $i < $pairCount; $i++) {
                    $ek = $orphanKeys[$i];
                    $nk = $newcomerKeys[$i];
                    $merged[$ek] = array_merge($orphans[$ek], $newcomers[$nk]);
                    // Preserve server-only component kinds for address pairs
                    if (isset($orphans[$ek]['components']) && isset($newcomers[$nk]['components'])) {
                        $patchKinds = [];
                        $patchValues = [];
                        foreach ($newcomers[$nk]['components'] as $nc) {
                            if (isset($nc['kind'])) $patchKinds[] = $nc['kind'];
                            if (isset($nc['value'])) $patchValues[] = $nc['value'];
                        }
                        foreach ($orphans[$ek]['components'] as $ec) {
                            if (!isset($ec['kind']) || in_array($ec['kind'], $patchKinds, true)) continue;
                            if (isset($ec['value']) && in_array($ec['value'], $patchValues, true)) continue;
                            $merged[$ek]['components'][] = $ec;
                        }
                    }
                    $consumedPatch[] = $nk;
                }
                // Drop remaining orphans only if ALL server entries fit
                // within the phone's per-context capacity.  The phone can
                // see at most 1 address per context (work / home / other).
                $maxVisible = ['work' => 1, 'home' => 1, 'other' => 1];
                $ctxCounts  = [];
                foreach ($existingEntries as $ee) {
                    $ctx = isset($ee['contexts']['work']) ? 'work'
                         : (isset($ee['contexts']['private']) || isset($ee['contexts']['home']) ? 'home' : 'other');
                    $ctxCounts[$ctx] = ($ctxCounts[$ctx] ?? 0) + 1;
                }
                $allVisible = true;
                foreach ($ctxCounts as $ctx => $cnt) {
                    if ($cnt > ($maxVisible[$ctx] ?? 1)) { $allVisible = false; break; }
                }
                if (!$allVisible) {
                    for ($i = $pairCount; $i < count($orphanKeys); $i++) {
                        $merged[$orphanKeys[$i]] = $orphans[$orphanKeys[$i]];
                    }
                }
                // else: all entries visible → orphans are intentional deletions
                for ($i = $pairCount; $i < count($newcomerKeys); $i++) {
                    $nk = $newcomerKeys[$i];
                    $merged[$nk] = $newcomers[$nk];
                }
                $result[$key] = $merged;
            } elseif (in_array($key, ['emails', 'phones', 'onlineServices', 'links'], true)) {
                // Match entries by content ("address", "number", "uri") so
                // that server entries with different map keys are updated
                // in-place rather than duplicated.  Unmatched orphans and
                // newcomers are paired positionally to handle renames.
                $matchField = match ($key) {
                    'emails'          => 'address',
                    'phones'          => 'number',
                    'onlineServices'  => 'uri',
                    'links'           => 'uri',
                };
                $maxReachable  = match ($key) {
                    'emails'          => 3,
                    'phones'          => 9,
                    'onlineServices'  => 3,
                    'links'           => 1,
                };
                $existingEntries = $result[$key];
                $consumedPatch   = [];
                $merged          = [];
                $orphans         = [];
                foreach ($existingEntries as $ek => $ee) {
                    $ev      = $ee[$matchField] ?? '';
                    $matched = false;
                    if ($ev !== '') {
                        foreach ($value as $nk => $ne) {
                            if (in_array($nk, $consumedPatch, true)) continue;
                            $nv = $ne[$matchField] ?? '';
                            if ($nv !== '' && $nv === $ev) {
                                $merged[$ek] = array_merge($ee, $ne);
                                $consumedPatch[] = $nk;
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if (!$matched) $orphans[$ek] = $ee;
                }
                // Collect newcomers (unmatched patch entries)
                $newcomers = [];
                foreach ($value as $nk => $ne) {
                    if (!in_array($nk, $consumedPatch, true)) {
                        $newcomers[$nk] = $ne;
                    }
                }
                // Pair orphans ↔ newcomers by position (handles renames)
                $orphanKeys = array_keys($orphans);
                $newcomerKeys = array_keys($newcomers);
                $pairCount = min(count($orphanKeys), count($newcomerKeys));
                for ($i = 0; $i < $pairCount; $i++) {
                    $ek = $orphanKeys[$i];
                    $nk = $newcomerKeys[$i];
                    $merged[$ek] = array_merge($orphans[$ek], $newcomers[$nk]);
                    $consumedPatch[] = $nk;
                }
                // Determine which server entries are "phone-visible" (fit in
                // the phone's limited fields).  Replicate the read-side sort
                // (work first, then stable by key) and take the first N.
                $sortedExisting = [];
                foreach ($existingEntries as $ek => $ee) {
                    $ee['_sortKey'] = $ek;
                    $sortedExisting[$ek] = $ee;
                }
                uasort($sortedExisting, function ($a, $b) {
                    $aW = isset($a['contexts']['work']) ? 0 : 1;
                    $bW = isset($b['contexts']['work']) ? 0 : 1;
                    if ($aW !== $bW) return $aW <=> $bW;
                    return strcmp($a['_sortKey'], $b['_sortKey']);
                });
                $visibleKeys = array_keys(array_slice($sortedExisting, 0, $maxReachable, true));
                // Remaining orphans: those from the phone-visible set were
                // intentionally deleted → drop.  Those beyond the phone's
                // view are preserved.
                for ($i = $pairCount; $i < count($orphanKeys); $i++) {
                    $ok = $orphanKeys[$i];
                    if (!in_array($ok, $visibleKeys, true)) {
                        $merged[$ok] = $orphans[$ok];
                    }
                }
                // Remaining newcomers (more patch than existing) → add
                for ($i = $pairCount; $i < count($newcomerKeys); $i++) {
                    $nk = $newcomerKeys[$i];
                    $merged[$nk] = $newcomers[$nk];
                }
                $result[$key] = $merged;
            } elseif (in_array($key, ['notes', 'nicknames', 'titles'], true)) {
                // Phone has at most 1 entry for these — replace server
                // data entirely to avoid duplicates when map keys differ.
                $result[$key] = $value;
            } else {
                $result[$key] = array_merge($result[$key], $value);
            }
        }
        return $result;
    }

    private static function detectImageMimeType(string $data): string {
        if (str_starts_with($data, "\xff\xd8\xff")) return 'image/jpeg';
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) return 'image/png';
        if (str_starts_with($data, "GIF87a") || str_starts_with($data, "GIF89a")) return 'image/gif';
        if (str_starts_with($data, "RIFF") && substr($data, 8, 4) === "WEBP") return 'image/webp';
        if (str_starts_with($data, "BM")) return 'image/bmp';
        return 'image/jpeg';
    }

    /**
     * Process raw photo binary for iOS compatibility:
     *   - Resize to max 480px on longest side
     *   - Convert to JPEG at quality 80
     *   - Falls back to original if GD is unavailable or processing fails
     */
    private static function processContactPhoto(string $data): string {
        if (strlen($data) === 0) {
            return base64_encode($data);
        }

        if (!function_exists('imagecreatefromstring')) {
            return base64_encode($data);
        }

        try {
            $img = @imagecreatefromstring($data);
            if ($img === false) {
                return base64_encode($data);
            }

            $w = imagesx($img);
            $h = imagesy($img);
            $maxDim = 480;

            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap::processContactPhoto(): original %dx%d (%d bytes)',
                $w, $h, strlen($data)
            ));

            if ($w <= $maxDim && $h <= $maxDim) {
                $encoded = base64_encode($data);
                imagedestroy($img);
                if (strlen($encoded) <= SYNC_CONTACTS_MAXPICTURESIZE) {
                    return $encoded;
                }
                ob_start();
                imagejpeg($img, null, 80);
                $jpeg = ob_get_clean();
                imagedestroy($img);
                if ($jpeg === '' || $jpeg === false) {
                    return $encoded; // fall back to original
                }
                return base64_encode($jpeg);
            }

            if ($w > $h) {
                $newW = $maxDim;
                $newH = max(1, (int)($h * $maxDim / $w));
            } else {
                $newH = $maxDim;
                $newW = max(1, (int)($w * $maxDim / $h));
            }

            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($img);

            ob_start();
            imagejpeg($resized, null, 80);
            $jpeg = ob_get_clean();
            imagedestroy($resized);

            if ($jpeg === '' || $jpeg === false) {
                // GD produced empty output, return original
                ZLog::Write(LOGLEVEL_WARN, 'BackendJmap::processContactPhoto(): GD produced empty JPEG, returning original');
                return base64_encode($data);
            }

            $encoded = base64_encode($jpeg);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap::processContactPhoto(): resized to %dx%d JPEG, base64=%d bytes',
                $newW, $newH, strlen($encoded)
            ));
            return $encoded;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'BackendJmap::processContactPhoto(): caught exception, returning original: %s',
                $e->getMessage()
            ));
            return base64_encode($data);
        }
    }
}

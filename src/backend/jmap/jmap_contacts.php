<?php
/***********************************************
* File      :   jmap_contacts.php
* Project   :   Z-Push
* Descr     :   JSContact (RFC 9553) <-> SyncContact conversion for
*               the JMAP backend. No external dependencies.
*
* Copyright 2024 - Z-Push Contributors
* AGPL-3.0 - see LICENSE
************************************************/

class JmapContactConverter {

    // -------------------------------------------------------------------------
    // JSContact → SyncContact
    // -------------------------------------------------------------------------

    public static function cardToSyncContact(array $card): SyncContact {
        $c = new SyncContact();

        // Name — Stalwart uses jCard-like format with components array.
        // Also support standard JSContact format (given/surname/full) as fallback.
        $name = $card['name'] ?? [];
        $c->firstname  = $name['given']      ?? null;
        $c->lastname   = $name['surname']    ?? null;
        $c->middlename = $name['given2'] ?? $name['additional'] ?? null;
        $c->title      = $name['title']  ?? $name['prefix']     ?? null;
        $c->suffix     = $name['credential'] ?? $name['suffix'] ?? null;
        $full          = $name['full']        ?? null;
        // Fallback: parse jCard components array (Stalwart native format)
        // e.g. {"components":[{"kind":"given","value":"Kees"},{"kind":"surname","value":"Huijgen"}],"isOrdered":true}
        if (($c->firstname === null || $c->lastname === null) && !empty($name['components'])) {
            foreach ($name['components'] as $comp) {
                $kind  = $comp['kind']  ?? '';
                $value = $comp['value'] ?? '';
                match ($kind) {
                    'given'      => $c->firstname  ??= $value,
                    'surname'    => $c->lastname   ??= $value,
                    'given2'     => $c->middlename ??= $value,
                    'additional' => $c->middlename ??= $value,
                    'title'      => $c->title      ??= $value,
                    'prefix'     => $c->title      ??= $value,
                    'credential' => $c->suffix     ??= $value,
                    'suffix'     => $c->suffix     ??= $value,
                    default     => null,
                };
            }
        }
        $c->fileas = $full ?: trim(($c->firstname ?? '') . ' ' . ($c->lastname ?? '')) ?: null;

        // Nicknames
        $nicks = array_values($card['nicknames'] ?? []);
        if ($nicks) $c->nickname = $nicks[0]['name'] ?? null;

        // Emails — work first, then by key for stability (matches merge).
        $emailList = $card['emails'] ?? [];
        uksort($emailList, function ($a, $b) use ($emailList) {
            $aW = isset($emailList[$a]['contexts']['work']) ? 0 : 1;
            $bW = isset($emailList[$b]['contexts']['work']) ? 0 : 1;
            if ($aW !== $bW) return $aW <=> $bW;
            return strcmp($a, $b);
        });
        $idx = 0;
        foreach ($emailList as $e) {
            if ($idx >= 3) break;
            $addr = $e['address'] ?? null;
            if     ($idx === 0) $c->email1address = $addr;
            elseif ($idx === 1) $c->email2address = $addr;
            elseif ($idx === 2) $c->email3address = $addr;
            $idx++;
        }

        // Phones — classify by context/features (Stalwart uses "mobile" and "private")
        foreach (array_values($card['phones'] ?? []) as $p) {
            $num      = $p['number']   ?? '';
            $ctx      = $p['contexts'] ?? [];
            $features = $p['features'] ?? [];

            // AS 2.5 specific phone fields tagged with custom features for round-trip
            if (isset($features['companyMain'])) {
                $c->companymainphone ??= $num;
                continue;
            }
            if (isset($features['mms'])) {
                $c->mms ??= $num;
                continue;
            }
            if (isset($features['assistantNumber'])) {
                $c->assistnamephonenumber ??= $num;
                continue;
            }

            if (isset($features['mobile']) || isset($features['cell'])) {
                $c->mobilephonenumber ??= $num;
            } elseif (isset($features['fax'])) {
                if (isset($ctx['work'])) $c->businessfaxnumber ??= $num;
                else                     $c->homefaxnumber     ??= $num;
            } elseif (isset($features['pager'])) {
                $c->pagernumber ??= $num;
            } elseif (isset($ctx['work'])) {
                if (!isset($c->businessphonenumber)) $c->businessphonenumber  = $num;
                else                                  $c->business2phonenumber ??= $num;
            } elseif (isset($ctx['private']) || isset($ctx['home'])) {
                if (!isset($c->homephonenumber)) $c->homephonenumber  = $num;
                else                              $c->home2phonenumber ??= $num;
            } else {
                // Context-less phones (no work/private/home, no cell/fax/pager):
                // chain through fallback fields.
                if (!isset($c->mobilephonenumber)) $c->mobilephonenumber    = $num;
                elseif (!isset($c->carphonenumber)) $c->carphonenumber       = $num;
                elseif (!isset($c->assistnamephonenumber)) $c->assistnamephonenumber ??= $num;
                else                                  $c->radiophonenumber   ??= $num;
            }
        }

        // Addresses
        foreach (array_values($card['addresses'] ?? []) as $addr) {
            $ctx    = $addr['contexts'] ?? [];
            $parsed = self::parseAddressComponents($addr['components'] ?? []);
            if (isset($ctx['work'])) {
                $c->businessstreet     ??= $parsed['street'];
                $c->businesscity       ??= $parsed['city'];
                $c->businessstate      ??= $parsed['state'];
                $c->businesspostalcode ??= $parsed['postal'];
                $c->businesscountry    ??= $parsed['country'];
            } elseif (isset($ctx['private']) || isset($ctx['home'])) {
                $c->homestreet     ??= $parsed['street'];
                $c->homecity       ??= $parsed['city'];
                $c->homestate      ??= $parsed['state'];
                $c->homepostalcode ??= $parsed['postal'];
                $c->homecountry    ??= $parsed['country'];
            } else {
                $c->otherstreet     ??= $parsed['street'];
                $c->othercity       ??= $parsed['city'];
                $c->otherstate      ??= $parsed['state'];
                $c->otherpostalcode ??= $parsed['postal'];
                $c->othercountry    ??= $parsed['country'];
            }
        }

        // Organization, job title, department (Stalwart: titles + organizations.units)
        $orgs   = array_values($card['organizations'] ?? []);
        $titles = array_values($card['titles'] ?? []);
        if ($orgs) {
            $c->companyname = $orgs[0]['name']   ?? null;
            $units = $orgs[0]['units'] ?? [];
            $rawDept = $units[0] ?? null;
            $c->department = is_array($rawDept) ? ($rawDept['name'] ?? null) : $rawDept;
        }
        if ($titles) $c->jobtitle = $titles[0]['name'] ?? null;

        // URLs
        foreach (array_values($card['links'] ?? []) as $link) {
            $c->webpage ??= $link['uri'] ?? null;
        }

        // IM / social profiles
        $ims = array_values($card['onlineServices'] ?? []);
        foreach ($ims as $i => $im) {
            // Extract a displayable address: prefer the username (e.g. "alice"),
            // then a clean user@host from the URI, then the service name as fallback.
            $val = $im['user'] ?? null;
            if ($val === null && !empty($im['uri'])) {
                // Strip URI scheme like "xmpp:", "tel:" or "https://"
                $val = preg_replace('/^[a-zA-Z][a-zA-Z0-9+.-]*:(\\/\\/)?/', '', $im['uri']);
            }
            if ($val === null) {
                $val = $im['service'] ?? null;
            }
            if ($val !== null) {
                match ($i) {
                    0 => $c->imaddress  = $val,
                    1 => $c->imaddress2 = $val,
                    2 => $c->imaddress3 = $val,
                    default => null,
                };
            }
        }

        // Anniversaries — only sync birthday and anniversary/wedding to
        // ActiveSync.  Other types (death, other, etc.) are ignored.
        foreach ($card['anniversaries'] ?? [] as $ann) {
            $dateStr = self::formatAnniversaryDate($ann['date'] ?? null);
            if (!$dateStr) continue;
            // ActiveSync expects Unix timestamps for date fields (use UTC to avoid timezone shifts)
            $parts = sscanf($dateStr, '%d-%d-%d');
            if ($parts === false || count($parts) < 3) continue;
            $dateTs = gmmktime(0, 0, 0, $parts[1], $parts[2], $parts[0]);
            $annType = strtolower($ann['kind'] ?? $ann['type'] ?? '');
            if (str_contains($annType, 'birth')) {
                $c->birthday    ??= $dateTs;
            } elseif ($annType === 'anniversary' || $annType === 'wedding') {
                $c->anniversary ??= $dateTs;
            }
            // Other types (death, other, etc.) are not synced to ActiveSync.
        }

        // Notes → body / asbody
        $notes = array_values($card['notes'] ?? []);
        if ($notes) {
            $text = $notes[0]['note'] ?? '';
            $c->asbody = new SyncBaseBody();
            $c->asbody->type              = SYNC_BODYPREFERENCE_PLAIN;
            $c->asbody->data              = StringStreamWrapper::Open($text);
            $c->asbody->estimatedDataSize = strlen($text);
            $c->asbody->truncated         = 0;
        }

        // Categories (space-separated string for compatibility with all streamer mappings)
        if (!empty($card['categories']) && is_array($card['categories'])) {
            $c->categories = implode(' ', array_keys($card['categories']));
        }

        // Custom / AS 2.5 properties stored directly on the card
        $c->children       = $card['children']       ?? null;
        $c->officelocation = $card['officeLocation'] ?? $card['officelocation'] ?? null;
        $c->customerid     = $card['customerId']     ?? $card['customerid']     ?? null;
        $c->governmentid   = $card['governmentId']   ?? $card['governmentid']   ?? null;
        $c->accountname    = $card['accountName']    ?? $card['accountname']    ?? null;
        $c->yomifirstname  = $card['yomiFirstName']  ?? $card['yomifirstname']  ?? null;
        $c->yomilastname   = $card['yomiLastName']   ?? $card['yomilastname']   ?? null;
        $c->yomicompanyname = $card['yomiCompanyName'] ?? $card['yomicompanyname'] ?? null;

        // Relations
        foreach ($card['relations'] ?? [] as $rel) {
            $roles = $rel['relation'] ?? [];
            $name  = $rel['name']    ?? '';
            if (isset($roles['spouse']))    $c->spouse        ??= $name;
            if (isset($roles['manager']))   $c->managername   ??= $name;
            if (isset($roles['assistant'])) $c->assistantname ??= $name;
        }

        return $c;
    }

    // -------------------------------------------------------------------------
    // SyncContact → JSContact
    // -------------------------------------------------------------------------

    public static function syncContactToCard(SyncContact $c, string $addressBookId): array {
        $card = [
            '@type'          => 'Card',
            'version'        => '1.0',
            'addressBookIds' => [$addressBookId => true],
        ];

        // Name — output in jCard components format (Stalwart native).
        // Kinds: given, given2, surname, title, credential, generation.
        $components = [];
        foreach (['given', 'surname', 'given2', 'title', 'credential'] as $kind) {
            $val = match ($kind) {
                'given'       => $c->firstname  ?? null,
                'surname'     => $c->lastname   ?? null,
                'given2'      => $c->middlename ?? null,
                'title'       => $c->title      ?? null,
                'credential'  => $c->suffix     ?? null,
            };
            if ($val !== null && $val !== '') {
                $components[] = ['kind' => $kind, 'value' => $val];
            }
        }
        $fullName = $c->fileas ?? trim(($c->firstname ?? '') . ' ' . ($c->lastname ?? '')) ?: null;
        if ($components || $fullName) {
            $card['name'] = ['components' => $components, 'isOrdered' => true];
            if ($fullName) {
                $card['name']['full'] = $fullName;
            }
            // Also set standard JSContact keys for compatibility with other JMAP servers
            if ($c->firstname  !== null) $card['name']['given']   = $c->firstname;
            if ($c->lastname   !== null) $card['name']['surname'] = $c->lastname;
        }

        if (!empty($c->nickname)) {
            $card['nicknames'] = ['n1' => ['name' => $c->nickname]];
        }

        // Emails (Stalwart contexts: work, private — NOT home)
        $emails = [];
        if (!empty($c->email1address)) $emails['e1'] = ['address' => $c->email1address, 'contexts' => ['work' => true]];
        if (!empty($c->email2address)) $emails['e2'] = ['address' => $c->email2address, 'contexts' => ['private' => true]];
        if (!empty($c->email3address)) $emails['e3'] = ['address' => $c->email3address];
        if ($emails) $card['emails'] = $emails;

        // Phones (Stalwart contexts: work, private — NOT home; features: mobile — NOT cell)
        $phones = [];
        $pi = 1;
        foreach ([
            [$c->businessphonenumber   ?? null, ['work' => true],    []],
            [$c->business2phonenumber  ?? null, ['work' => true],    []],
            [$c->homephonenumber       ?? null, ['private' => true], []],
            [$c->home2phonenumber      ?? null, ['private' => true], []],
            [$c->mobilephonenumber     ?? null, [],                  ['mobile' => true]],
            [$c->businessfaxnumber     ?? null, ['work' => true],    ['fax'    => true]],
            [$c->homefaxnumber         ?? null, ['private' => true], ['fax'    => true]],
            [$c->pagernumber           ?? null, [],                  ['pager'  => true]],
            [$c->carphonenumber        ?? null, [],                  []],
            [$c->assistnamephonenumber ?? null, [],                  []],
            [$c->radiophonenumber      ?? null, [],                  []],
            [$c->companymainphone      ?? null, ['work' => true],    ['companyMain' => true]],
            [$c->mms                   ?? null, [],                  ['mms' => true]],
        ] as [$num, $ctx, $feat]) {
            if (!$num) continue;
            $entry = ['number' => $num];
            if ($ctx)  $entry['contexts'] = $ctx;
            if ($feat) $entry['features'] = $feat;
            $phones['p' . $pi++] = $entry;
        }
        if ($phones) $card['phones'] = $phones;

        // Addresses
        $addrs = [];
        if (!empty($c->businessstreet) || !empty($c->businesscity)) {
            $addrs['a1'] = self::buildAddress($c->businessstreet, $c->businesscity, $c->businessstate, $c->businesspostalcode, $c->businesscountry, 'work');
        }
        if (!empty($c->homestreet) || !empty($c->homecity)) {
            $addrs['a2'] = self::buildAddress($c->homestreet, $c->homecity, $c->homestate, $c->homepostalcode, $c->homecountry, 'private');
        }
        if (!empty($c->otherstreet) || !empty($c->othercity)) {
            $addrs['a3'] = self::buildAddress($c->otherstreet, $c->othercity, $c->otherstate, $c->otherpostalcode, $c->othercountry, null);
        }
        if ($addrs) $card['addresses'] = $addrs;

        // Organization / job title (Stalwart: organizations.units for department)
        if (!empty($c->companyname) || !empty($c->department)) {
            $org = [];
            if (!empty($c->companyname)) $org['name'] = $c->companyname;
            if (!empty($c->department)) {
                // Normalize to string (server may store units objects with "name" key)
                $dept = is_array($c->department) ? ($c->department['name'] ?? '') : $c->department;
                if ($dept !== '') $org['units'] = [$dept];
            }
            $card['organizations'] = ['o1' => $org];
        }
        if (!empty($c->jobtitle)) $card['titles'] = ['jt1' => ['name' => $c->jobtitle]];

        // URL
        if (!empty($c->webpage)) {
            $card['links'] = ['l1' => ['@type' => 'Link', 'uri' => $c->webpage]];
        }

        // IM / social profiles — ActiveSync has no context classification,
        // so all entries are tagged as work (phone→server direction).
        // Always set the key (even if empty) so the merge can properly
        // handle deletions.  Set `user` alongside `uri` so the read side
        // (which prefers `user` over a scheme-stripped `uri`) returns the
        // correct value after a phone edit.
        $ims = [];
        if (!empty($c->imaddress)) {
            $ims['im1'] = ['@type' => 'OnlineService', 'uri' => $c->imaddress, 'user' => $c->imaddress, 'contexts' => ['work' => true]];
        }
        if (!empty($c->imaddress2)) {
            $ims['im2'] = ['@type' => 'OnlineService', 'uri' => $c->imaddress2, 'user' => $c->imaddress2];
        }
        if (!empty($c->imaddress3)) {
            $ims['im3'] = ['@type' => 'OnlineService', 'uri' => $c->imaddress3, 'user' => $c->imaddress3];
        }
        $card['onlineServices'] = $ims;

        // Anniversaries — convert Unix timestamps from ActiveSync to YYYY-MM-DD strings for JSContact.
        // Always send the key so the merge can remove entries the phone cleared.
        $anns = [];
        if (!empty($c->birthday) && (int)$c->birthday > 0) {
            $dateStr = is_numeric($c->birthday) ? gmdate('Y-m-d', (int)$c->birthday) : (string)$c->birthday;
            $anns['b1'] = ['type' => 'birth', 'kind' => 'birth', 'date' => self::toPartialDate($dateStr)];
        }
        if (!empty($c->anniversary) && (int)$c->anniversary > 0) {
            $dateStr = is_numeric($c->anniversary) ? gmdate('Y-m-d', (int)$c->anniversary) : (string)$c->anniversary;
            $anns['a1'] = ['type' => 'anniversary', 'kind' => 'wedding', 'date' => self::toPartialDate($dateStr)];
        }
        $card['anniversaries'] = $anns;

        // Notes — handle both old-style body (string) and AirSyncBase body (SyncBaseBody)
        $noteText = null;
        if (!empty($c->body)) {
            $noteText = $c->body;
        } elseif (!empty($c->asbody) && $c->asbody instanceof SyncBaseBody) {
            $data = $c->asbody->data;
            if (is_resource($data)) {
                $noteText = stream_get_contents($data);
                rewind($data);
            } elseif (is_string($data) && $data !== '') {
                $noteText = $data;
            }
        }
        if ($noteText !== null && $noteText !== '') {
            $card['notes'] = ['n1' => ['note' => $noteText]];
        }

        // Categories (handle both space-separated string and array from device)
        if (!empty($c->categories)) {
            $cats = [];
            $catList = is_array($c->categories) ? $c->categories : explode(' ', $c->categories);
            foreach ($catList as $cat) {
                $cat = trim($cat);
                if ($cat !== '') $cats[$cat] = true;
            }
            if ($cats) $card['categories'] = $cats;
        }

        // Custom / AS 2.5 properties — no standard JSContact equivalent,
        // stored directly on the card. Stalwart preserves them via vCard.
        if (!empty($c->children))       $card['children']       = $c->children;
        if (!empty($c->officelocation))  $card['officeLocation']  = $c->officelocation;
        if (!empty($c->customerid))     $card['customerId']     = $c->customerid;
        if (!empty($c->governmentid))   $card['governmentId']   = $c->governmentid;
        if (!empty($c->accountname))    $card['accountName']    = $c->accountname;
        if (!empty($c->yomifirstname))  $card['yomiFirstName']  = $c->yomifirstname;
        if (!empty($c->yomilastname))   $card['yomiLastName']   = $c->yomilastname;
        if (!empty($c->yomicompanyname)) $card['yomiCompanyName'] = $c->yomicompanyname;

        // Relations
        $rels = [];
        if (!empty($c->spouse))        $rels['r1'] = ['name' => $c->spouse,        'relation' => ['spouse'    => true]];
        if (!empty($c->managername))   $rels['r2'] = ['name' => $c->managername,   'relation' => ['manager'   => true]];
        if (!empty($c->assistantname)) $rels['r3'] = ['name' => $c->assistantname, 'relation' => ['assistant' => true]];
        if ($rels) $card['relations'] = $rels;

        return $card;
    }

    // -------------------------------------------------------------------------
    // Build a mod hash for change detection
    // -------------------------------------------------------------------------

    public static function cardModHash(array $card): string {
        $key = $card['name']['full'] ?? '';
        // name components
        foreach (($card['name']['components'] ?? []) as $comp) {
            $key .= '|nc:' . ($comp['kind'] ?? '') . '=' . ($comp['value'] ?? '');
        }
        foreach (($card['emails'] ?? []) as $e) {
            $key .= '|e:' . ($e['address'] ?? '');
        }
        foreach (($card['phones'] ?? []) as $p) {
            $key .= '|p:' . ($p['number'] ?? '');
        }
        foreach (($card['addresses'] ?? []) as $a) {
            $key .= '|a:' . implode(',', array_keys($a['contexts'] ?? []));
            foreach (($a['components'] ?? []) as $comp) {
                $key .= ':' . ($comp['kind'] ?? '') . '=' . ($comp['value'] ?? '');
            }
        }
        foreach (($card['organizations'] ?? []) as $o) {
            $units = '';
            foreach (($o['units'] ?? []) as $u) {
                $uName = is_array($u) ? ($u['name'] ?? '') : $u;
                if ($uName !== '') $units .= ($units !== '' ? ',' : '') . $uName;
            }
            $key .= '|o:' . ($o['name'] ?? '') . ':' . $units;
        }
        foreach (($card['titles'] ?? []) as $t) {
            $key .= '|t:' . ($t['name'] ?? '');
        }
        foreach (($card['nicknames'] ?? []) as $n) {
            $key .= '|n:' . ($n['name'] ?? '');
        }
        foreach (($card['notes'] ?? []) as $n) {
            $key .= '|not:' . md5($n['note'] ?? '');
        }
        foreach (($card['onlineServices'] ?? []) as $im) {
            $key .= '|im:' . ($im['uri'] ?? $im['service'] ?? '');
        }
        foreach (($card['links'] ?? []) as $l) {
            $key .= '|l:' . ($l['uri'] ?? '');
        }
        foreach (($card['anniversaries'] ?? []) as $a) {
            $k = $a['kind'] ?? $a['type'] ?? '';
            $d = is_array($a['date'] ?? null)
                ? sprintf('%04d-%02d-%02d', $a['date']['year'] ?? 0, $a['date']['month'] ?? 1, $a['date']['day'] ?? 1)
                : ($a['date'] ?? '');
            $key .= '|ann:' . $k . '=' . $d;
        }
        foreach (($card['relations'] ?? []) as $r) {
            $key .= '|rel:' . ($r['name'] ?? '') . ':' . implode(',', array_keys($r['relation'] ?? []));
        }
        foreach (array_keys($card['categories'] ?? []) as $cat) {
            $key .= '|cat:' . $cat;
        }
        if (!empty($card['media'])) {
            $key .= '|media:' . count($card['media']);
        }
        foreach (($card['children'] ?? []) as $child) {
            $key .= '|child:' . $child;
        }
        $key .= '|ol:'  . ($card['officeLocation']  ?? '');
        $key .= '|cid:' . ($card['customerId']      ?? '');
        $key .= '|gid:' . ($card['governmentId']    ?? '');
        $key .= '|an:'  . ($card['accountName']     ?? '');
        $key .= '|yfn:' . ($card['yomiFirstName']   ?? '');
        $key .= '|yln:' . ($card['yomiLastName']    ?? '');
        $key .= '|ycn:' . ($card['yomiCompanyName'] ?? '');
        $key .= '|' . ($card['updated'] ?? '');
        return sprintf('%08x', crc32($key));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function parseAddressComponents(array $components): array {
        $r = ['street' => '', 'city' => '', 'state' => '', 'postal' => '', 'country' => ''];
        foreach ($components as $comp) {
            $v = $comp['value'] ?? '';
            match ($comp['kind'] ?? '') {
                'street', 'name', 'number' => $r['street']  .= ($r['street'] ? ', ' : '') . $v,
                'locality'                 => $r['city']     = $v,
                'region'                   => $r['state']    = $v,
                'postcode', 'postalCode'   => $r['postal']   = $v,
                'country', 'countryName'   => $r['country']  = $v,
                default                    => null,
            };
        }
        return $r;
    }

    private static function buildAddress(?string $street, ?string $city, ?string $state, ?string $postal, ?string $country, ?string $context): array {
        $components = [];
        if ($street)  $components[] = ['kind' => 'name',   'value' => $street];
        if ($city)    $components[] = ['kind' => 'locality', 'value' => $city];
        if ($state)   $components[] = ['kind' => 'region',   'value' => $state];
        if ($postal)  $components[] = ['kind' => 'postcode', 'value' => $postal];
        if ($country) $components[] = ['kind' => 'country',  'value' => $country];

        $addr = ['@type' => 'Address', 'components' => $components];
        if ($context) $addr['contexts'] = [$context => true];
        return $addr;
    }

    private static function formatAnniversaryDate(mixed $date): ?string {
        if (!$date) return null;
        if (is_string($date)) return $date;
        if (is_array($date)) {
            $y = $date['year']  ?? 0;
            $m = $date['month'] ?? 1;
            $d = $date['day']   ?? 1;
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
        return null;
    }

    private static function toPartialDate(string $date): array {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return ['@type' => 'PartialDate', 'year' => (int)$m[1], 'month' => (int)$m[2], 'day' => (int)$m[3]];
        }
        return ['@type' => 'PartialDate', 'year' => 0, 'month' => 1, 'day' => 1];
    }
}

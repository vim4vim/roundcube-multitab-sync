<?php

/**
 * Multi-Tab Sync
 *
 * Keeps the message list current in every browser tab, not only in the one
 * that happens to poll first.
 *
 * Roundcube detects new mail by comparing a folder's current maxuid/count
 * against a reference value it keeps in the PHP session (see
 * rcube_imap::folder_status()). Every tab of a browser shares that session, so
 * the first tab to poll consumes the difference and writes the new reference;
 * every other tab then compares against the already-advanced value, is told
 * "nothing changed", and its list stops moving.
 *
 * This plugin keeps those reference values per tab instead of per session, so
 * each tab sees a change exactly as if it were the only one open.
 */
class multitab_sync extends rcube_plugin
{
    public $task = 'mail';

    /**
     * Session key prefix. One top-level key per tab on purpose:
     * rcube_session::fixvars() merges stored and new session data per
     * top-level variable, so separate keys survive two tabs polling at once
     * where a single nested array would lose one of the two writes.
     */
    private const PREFIX = 'multitab_sync_';

    /** @var ?string Validated tab identifier for this request */
    private $tabid;

    /** @var ?array Folders swapped in, so swap_out() writes back the same set */
    private $folders;

    #[\Override]
    public function init()
    {
        $rcmail = rcmail::get_instance();

        $this->load_config();

        if (!$rcmail->config->get('multitab_sync_enabled', true)) {
            return;
        }

        // included before the tab id check: on the initial page load there is
        // no tab id yet, this script is what creates one
        if ($rcmail->action == '') {
            $this->include_script('multitab_sync.js');
        }

        // without a usable tab id we register nothing and Roundcube behaves
        // exactly as it does without this plugin
        if (!($this->tabid = $this->tab_id())) {
            return;
        }

        $this->add_hook('check_recent', [$this, 'swap_in']);
        $this->add_hook('refresh', [$this, 'swap_out']);

        // a notifier only stays quiet if it sees the trimmed range, so this
        // handler has to run before it; register_hook() appends, so put ours
        // in front rather than depend on the order of $config['plugins']
        $this->api->handlers['new_messages'] = array_merge(
            [[$this, 'dedupe']], $this->api->handlers['new_messages'] ?? []);
    }

    /**
     * Before the folder loop: put the values this tab last saw where the core
     * reads them. A folder with no stored value is left unset, which makes
     * folder_status() return "no change" and seed a reference - the same thing
     * it does on the first poll of a fresh session.
     */
    public function swap_in($args)
    {
        $bucket = $_SESSION[self::PREFIX . $this->tabid] ?? [];
        $bucket = is_array($bucket) ? $bucket : [];

        $this->folders = (array) $args['folders'];

        foreach ($this->folders as $folder) {
            if (isset($bucket['folders'][$folder])) {
                $_SESSION['folders'][$folder] = $bucket['folders'][$folder];
            } else {
                unset($_SESSION['folders'][$folder]);
            }

            if (isset($bucket['unseen_count'][$folder])) {
                $_SESSION['unseen_count'][$folder] = $bucket['unseen_count'][$folder];
            } else {
                unset($_SESSION['unseen_count'][$folder]);
            }
        }

        // reference for flag changes (IMAP HIGHESTMODSEQ), current folder only
        if (isset($bucket['list_mod_seq'])) {
            $_SESSION['list_mod_seq'] = $bucket['list_mod_seq'];
        } else {
            unset($_SESSION['list_mod_seq']);
        }

        $this->collect_garbage();

        return $args;
    }

    /**
     * After the folder loop: keep what the core just wrote as this tab's
     * private reference for its next poll.
     */
    public function swap_out($args)
    {
        // the refresh hook also fires from rcmail::action_handler() when
        // check_recent returned early, without swap_in() having run
        if ($this->folders === null) {
            return $args;
        }

        $bucket = ['ts' => time(), 'folders' => [], 'unseen_count' => []];

        foreach ($this->folders as $folder) {
            if (isset($_SESSION['folders'][$folder])) {
                $bucket['folders'][$folder] = $_SESSION['folders'][$folder];
            }

            if (isset($_SESSION['unseen_count'][$folder])) {
                $bucket['unseen_count'][$folder] = $_SESSION['unseen_count'][$folder];
            }
        }

        if (isset($_SESSION['list_mod_seq'])) {
            $bucket['list_mod_seq'] = $_SESSION['list_mod_seq'];
        }

        $_SESSION[self::PREFIX . $this->tabid] = $bucket;

        return $args;
    }

    /**
     * Ahead of any notifier plugin: drop the part of a new-message range that
     * another tab has already reported. Roundcube announces new mail once per
     * client; with per-tab reference values every tab is a client, so without
     * this every open tab notifies for the same message.
     */
    public function dedupe($args)
    {
        $range = explode(':', (string) ($args['diff']['new'] ?? ''));
        $last = (int) array_pop($range);
        $first = $range ? (int) $range[0] : $last;

        if (!$last) {
            return $args;
        }

        $known = $this->reported($args['mailbox']);

        if ($known >= $last) {
            // the union in exec_hook() merges per top-level key, so the 'diff'
            // returned here replaces the one passed in - dropping 'diff' itself
            // would let the original come back
            unset($args['diff']['new']);
        } elseif ($known >= $first) {
            // same shape folder_status() builds: "M:N", or "N" for a single UID
            $args['diff']['new'] = ($known + 1 < $last ? ($known + 1) . ':' : '') . $last;
        }

        return $args;
    }

    /**
     * The identifier the client keeps in sessionStorage, or null when it is
     * absent or malformed. The value becomes part of a session key name, so
     * the pattern is deliberately strict rather than merely sanitising.
     */
    private function tab_id()
    {
        $id = rcube_utils::get_input_string('_tabid', rcube_utils::INPUT_GPC);

        return is_string($id) && preg_match('/^[a-z0-9]{16}$/', $id) ? $id : null;
    }

    /**
     * Drop the buckets of tabs that are gone. Without this the session grows
     * by one bucket for every tab the user has ever opened.
     */
    private function collect_garbage()
    {
        $rcmail = rcmail::get_instance();
        $ttl = (int) $rcmail->config->get('multitab_sync_ttl', 3600);
        $max = (int) $rcmail->config->get('multitab_sync_max_tabs', 20);
        $mine = self::PREFIX . $this->tabid;
        $keep = [];

        foreach ($_SESSION as $key => $value) {
            if ($key === $mine || strpos($key, self::PREFIX) !== 0) {
                continue;
            }

            $ts = is_array($value) ? (int) ($value['ts'] ?? 0) : 0;

            if ($ttl > 0 && time() - $ts > $ttl) {
                $this->forget($key);
            } else {
                $keep[$key] = $ts;
            }
        }

        // our own bucket counts towards the limit, hence $max - 1 others
        if ($max > 0 && count($keep) > $max - 1) {
            arsort($keep);

            foreach (array_slice(array_keys($keep), $max - 1) as $key) {
                $this->forget($key);
            }
        }
    }

    /**
     * Remove a session variable for good. A plain unset() would not stick:
     * rcube_session merges this request's data over what is stored, so a key
     * has to be registered as unset to survive that merge.
     */
    private function forget($key)
    {
        rcmail::get_instance()->session->remove($key);
    }

    /**
     * The highest UID any other tab has already seen in this folder, which is
     * the same thing as the highest one the user has already been told about.
     */
    private function reported($folder)
    {
        $mine = self::PREFIX . $this->tabid;
        $max = 0;

        foreach ($_SESSION as $key => $bucket) {
            if ($key === $mine || strpos($key, self::PREFIX) !== 0 || !is_array($bucket)) {
                continue;
            }

            $max = max($max, (int) ($bucket['folders'][$folder]['maxuid'] ?? 0));
        }

        return $max;
    }
}

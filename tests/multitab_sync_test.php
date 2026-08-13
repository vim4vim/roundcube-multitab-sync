<?php

/**
 * Harness for multitab_sync: reproduces the multi-tab bug against a verbatim
 * copy of rcube_imap's folder-status logic, then checks the plugin fixes it.
 */

// --- minimal Roundcube stubs -------------------------------------------------

class rcube_utils
{
    public const INPUT_GPC = 7;
    public static $input = [];

    public static function get_input_string($fname, $source, $allow_html = false, $charset = null)
    {
        return self::$input[$fname] ?? null;
    }
}

class rcube_session_stub
{
    public $removed = [];

    public function remove($var)
    {
        $this->removed[] = $var;
        unset($_SESSION[$var]);

        return true;
    }
}

class rcube_config_stub
{
    public $values = [];

    public function get($key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }
}

class rcmail
{
    public static $instance;
    public $action = '';
    public $config;
    public $session;

    public static function get_instance()
    {
        return self::$instance;
    }
}

class rcube_plugins_stub
{
    public $handlers = [];

    public function register_hook($hook, $callback)
    {
        $this->handlers[$hook][] = $callback;
    }

    /**
     * As rcube_plugin_api::exec_hook() does it: each handler's return is
     * merged over the args with a union, which works per top-level key.
     */
    public function exec_hook($hook, $args = [])
    {
        foreach ($this->handlers[$hook] ?? [] as $callback) {
            $ret = $callback($args);

            if ($ret && is_array($ret)) {
                $args = $ret + $args;
            }
        }

        return $args;
    }
}

abstract class rcube_plugin
{
    public $task;
    public $api;
    public $hooks = [];

    public function __construct($api)
    {
        $this->api = $api;
    }

    abstract public function init();

    public function load_config() {}

    public function include_script($fn) {}

    public function add_hook($name, $cb)
    {
        $this->hooks[$name][] = $cb;
    }
}

require __DIR__ . '/../multitab_sync.php';

// --- verbatim copy of rcube_imap::folder_status() and friends ----------------

class fake_storage
{
    /** what IMAP would actually report right now */
    public $cnt = [];
    public $maxuid = [];

    public function folder_status($folder = null, &$diff = [])
    {
        $old = $this->get_folder_stats($folder);

        // refresh message count -> will update
        $this->countmessages($folder);

        $result = 0;

        if (empty($old)) {
            return $result;
        }

        $new = $this->get_folder_stats($folder);

        if ($new['maxuid'] > $old['maxuid']) {
            $result++;
            $diff['new'] = ($old['maxuid'] + 1 < $new['maxuid'] ? ($old['maxuid'] + 1) . ':' : '') . $new['maxuid'];
        }

        if ($new['cnt'] < $old['cnt']) {
            $result += 2;
        }

        return $result;
    }

    protected function countmessages($folder)
    {
        $this->set_folder_stats($folder, 'cnt', $this->cnt[$folder]);
        $this->set_folder_stats($folder, 'maxuid', $this->maxuid[$folder]);
    }

    protected function set_folder_stats($folder, $name, $data)
    {
        $_SESSION['folders'][$folder][$name] = $data;
    }

    protected function get_folder_stats($folder)
    {
        return isset($_SESSION['folders'][$folder]) ? (array) $_SESSION['folders'][$folder] : [];
    }
}

// --- test rig ----------------------------------------------------------------

$storage = new fake_storage();
$failures = 0;
$notified = null;

function check($label, $got, $want)
{
    global $failures;
    $ok = $got === $want;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %-58s got=%s want=%s\n", $ok ? 'ok' : 'FAIL', $label, json_encode($got), json_encode($want));
}

/** Stand-in for newmail_notifier::notify(): records the range it was handed. */
function register_notifier($api)
{
    $api->register_hook('new_messages', static function ($args) {
        global $notified;

        if (!empty($args['diff']['new'])) {
            $notified = $args['diff']['new'];
        }

        return $args;
    });
}

/**
 * One check-recent request from one tab, with the plugin on or off. $notifier
 * places a notifier plugin 'after' this one in the hook chain or 'before' it -
 * both are valid plugin orders - or leaves it out with 'none', the way the
 * Refresh button does: it posts check-recent, where newmail_notifier binds
 * nothing.
 */
function poll($tabid, $folders, $enabled = true, $notifier = 'after')
{
    global $storage, $notified;

    $notified = null;
    $api = new rcube_plugins_stub();

    if ($notifier == 'before') {
        register_notifier($api);
    }

    rcube_utils::$input['_tabid'] = $tabid;
    rcmail::$instance->config->values['multitab_sync_enabled'] = $enabled;

    $plugin = new multitab_sync($api);
    $plugin->init();

    if ($notifier == 'after') {
        register_notifier($api);
    }

    $args = ['folders' => $folders, 'all' => false];

    foreach ($plugin->hooks['check_recent'] ?? [] as $cb) {
        $args = $cb($args);
    }

    $status = [];
    foreach ($args['folders'] as $f) {
        $diff = [];
        $status[$f] = $storage->folder_status($f, $diff);

        if ($status[$f] & 1) {
            $api->exec_hook('new_messages', ['mailbox' => $f, 'is_current' => true, 'diff' => $diff]);
        }
    }

    foreach ($plugin->hooks['refresh'] ?? [] as $cb) {
        $cb([]);
    }

    return $status;
}

function reset_all($cnt, $maxuid)
{
    global $storage;
    $_SESSION = [];
    $storage->cnt = ['INBOX' => $cnt];
    $storage->maxuid = ['INBOX' => $maxuid];
}

rcmail::$instance = new rcmail();
rcmail::$instance->config = new rcube_config_stub();
rcmail::$instance->session = new rcube_session_stub();

$A = 'aaaaaaaaaaaaaaaa';
$B = 'bbbbbbbbbbbbbbbb';
$F = ['INBOX'];

// 1. baseline: the bug, with the plugin disabled
echo "1. Baseline (plugin disabled) - reproduces the reported bug\n";
reset_all(10, 100);
poll($A, $F, false);
poll($B, $F, false);
$storage->cnt['INBOX'] = 11;
$storage->maxuid['INBOX'] = 105;                       // new mail arrives
check('tab A sees new mail', poll($A, $F, false)['INBOX'], 1);
check('tab B sees new mail (expected to FAIL: the bug)', poll($B, $F, false)['INBOX'], 0);

// 2. the fix
echo "\n2. Plugin enabled - both tabs see the new mail\n";
reset_all(10, 100);
poll($A, $F);                                          // seed tab A
poll($B, $F);                                          // seed tab B
$storage->cnt['INBOX'] = 11;
$storage->maxuid['INBOX'] = 105;
check('tab A sees new mail', poll($A, $F)['INBOX'], 1);
check('tab B sees new mail', poll($B, $F)['INBOX'], 1);
check('tab A polls again, nothing new', poll($A, $F)['INBOX'], 0);
check('tab B polls again, nothing new', poll($B, $F)['INBOX'], 0);

// 3. deletions (status flag 2) reach both tabs
echo "\n3. Deletion reaches both tabs\n";
reset_all(10, 100);
poll($A, $F);
poll($B, $F);
$storage->cnt['INBOX'] = 9;                            // one message deleted
check('tab A sees the deletion', poll($A, $F)['INBOX'], 2);
check('tab B sees the deletion', poll($B, $F)['INBOX'], 2);

// 4. a third tab joining late does not disturb the others
echo "\n4. Late-joining tab seeds without a spurious change\n";
reset_all(10, 100);
poll($A, $F);
poll($B, $F);
$C = 'cccccccccccccccc';
check('tab C first poll seeds quietly', poll($C, $F)['INBOX'], 0);
$storage->cnt['INBOX'] = 11;
$storage->maxuid['INBOX'] = 105;
check('tab A sees new mail', poll($A, $F)['INBOX'], 1);
check('tab B sees new mail', poll($B, $F)['INBOX'], 1);
check('tab C sees new mail', poll($C, $F)['INBOX'], 1);

// 5. one bucket per tab, as separate top-level session keys
echo "\n5. Session layout\n";
$keys = array_values(array_filter(array_keys($_SESSION), static fn($k) => strpos($k, 'multitab_sync_') === 0));
sort($keys);
check('one top-level key per tab', $keys, [
    'multitab_sync_' . $A, 'multitab_sync_' . $B, 'multitab_sync_' . $C,
]);

// 6. garbage collection caps the number of tracked tabs
echo "\n6. Garbage collection\n";
reset_all(10, 100);
rcmail::$instance->config->values['multitab_sync_max_tabs'] = 3;
for ($i = 0; $i < 10; $i++) {
    poll(str_pad((string) $i, 16, 'z'), $F);
}
$kept = count(array_filter(array_keys($_SESSION), static fn($k) => strpos($k, 'multitab_sync_') === 0));
check('buckets capped at max_tabs', $kept, 3);

rcmail::$instance->config->values['multitab_sync_ttl'] = 1;
$_SESSION['multitab_sync_' . $A] = ['ts' => time() - 3600, 'folders' => []];
poll($B, $F);
check('expired bucket dropped', isset($_SESSION['multitab_sync_' . $A]), false);
check('expiry went through session->remove()',
    in_array('multitab_sync_' . $A, rcmail::$instance->session->removed, true), true);

// 7. a missing or malformed tab id leaves Roundcube's behaviour untouched
echo "\n7. Fallback without a usable tab id\n";
rcmail::$instance->config->values['multitab_sync_max_tabs'] = 20;
foreach ([null, '', 'short', 'UPPERCASE123456A', '../../etc/passwd0'] as $bad) {
    rcube_utils::$input['_tabid'] = $bad;
    $api = new rcube_plugins_stub();
    $p = new multitab_sync($api);
    $p->init();
    check('no hooks for tab id ' . json_encode($bad), $p->hooks, []);
    check('no notification handler for tab id ' . json_encode($bad), $api->handlers, []);
}

// 8. a new message is announced once per session, not once per tab
echo "\n8. Notification dedup\n";
rcmail::$instance->config->values['multitab_sync_ttl'] = 3600;

reset_all(10, 100);
poll($A, $F);
poll($B, $F);
$storage->cnt['INBOX'] = 11;
$storage->maxuid['INBOX'] = 105;
poll($A, $F);
check('the first tab to poll announces the new mail', $notified, '101:105');
poll($B, $F);
check('the second tab announces nothing', $notified, null);

// a single arrival comes as a bare UID rather than a range
reset_all(10, 100);
poll($A, $F);
$storage->cnt['INBOX'] = 11;
$storage->maxuid['INBOX'] = 101;
poll($A, $F);
check('a single tab is announced to', $notified, '101');
poll($A, $F);
check('and not again on its next poll', $notified, null);

// a tab that fell behind announces only the part nobody has reported yet
reset_all(10, 100);
poll($A, $F);
poll($B, $F);
$storage->cnt['INBOX'] = 12;
$storage->maxuid['INBOX'] = 103;
poll($A, $F);
check('tab A announces the first arrivals', $notified, '101:103');
$storage->cnt['INBOX'] = 14;
$storage->maxuid['INBOX'] = 105;
poll($B, $F);
check('the tab left behind announces only the rest', $notified, '104:105');

// the notifier plugin listed before this one in $config['plugins']
reset_all(10, 100);
poll($A, $F, true, 'before');
poll($B, $F, true, 'before');
$storage->cnt['INBOX'] = 11;
$storage->maxuid['INBOX'] = 105;
poll($A, $F, true, 'before');
check('a notifier bound first still announces', $notified, '101:105');
poll($B, $F, true, 'before');
check('a notifier bound first still stays quiet in the second tab', $notified, null);

// the Refresh button consumes the change without a notifier, as it does in
// stock Roundcube, and that keeps the other tabs quiet too
reset_all(10, 100);
poll($A, $F);
poll($B, $F);
$storage->cnt['INBOX'] = 11;
$storage->maxuid['INBOX'] = 105;
poll($A, $F, true, 'none');
check('the Refresh button announces nothing', $notified, null);
poll($B, $F);
check('and leaves the other tab quiet as well', $notified, null);

echo "\n" . ($failures ? "{$failures} FAILURE(S)\n" : "All checks passed\n");
exit($failures ? 1 : 0);

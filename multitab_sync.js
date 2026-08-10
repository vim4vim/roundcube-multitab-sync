/**
 * Multi-Tab Sync
 *
 * Two things happen here:
 *
 * 1. Every tab gets an identifier of its own and sends it along with each poll,
 *    so the server can track what a single tab has already seen instead of what
 *    the whole session has. That is the part that makes the message list update
 *    in every tab rather than only in the one that polled first.
 *
 * 2. Tabs tell each other about changes over a BroadcastChannel, and a tab that
 *    becomes visible polls straight away. Neither is needed for correctness -
 *    both cut the delay. A background tab the browser froze does not poll at
 *    all, and a delete in one tab would otherwise sit unnoticed in the others
 *    until their next poll comes round.
 */
if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        var STORAGE_KEY = 'multitab_sync_tabid',
            CHANNEL = 'multitab_sync',
            // server actions after which the other tabs are worth nudging
            MUTATIONS = ['move', 'delete', 'copy', 'mark', 'expunge', 'purge'],
            // an upper bound on how often a nudge may turn into a request
            MIN_INTERVAL = 5000,
            tabid,
            channel,
            last_refresh = 0;

        // 16 chars of [a-z0-9], the shape the server side insists on. This is
        // an identifier, not a secret, so Math.random is enough.
        function make_id() {
            var id = '';

            while (id.length < 16) {
                id += Math.random().toString(36).slice(2);
            }

            return id.slice(0, 16);
        }

        function store_id(id) {
            try {
                sessionStorage.setItem(STORAGE_KEY, id);
            } catch (e) {
                // storage unavailable: the id lives for this page load only,
                // which costs a re-seed on reload but still works
            }
        }

        // sessionStorage is per tab and survives a reload, which is exactly the
        // lifetime we want
        function load_id() {
            var id = null;

            try {
                id = sessionStorage.getItem(STORAGE_KEY);
            } catch (e) {
                // see store_id()
            }

            if (!/^[a-z0-9]{16}$/.test(id || '')) {
                id = make_id();
                store_id(id);
            }

            return id;
        }

        function add_tabid(params) {
            params = params || {};
            params._tabid = tabid;

            return params;
        }

        function announce() {
            if (channel) {
                channel.postMessage({ type: 'changed', tabid: tabid, mbox: rcmail.env.mailbox });
            }
        }

        function refresh() {
            var now = Date.now();

            if (rcmail.busy || now - last_refresh < MIN_INTERVAL) {
                return;
            }

            last_refresh = now;
            rcmail.refresh();
        }

        tabid = load_id();

        // periodic poll - app.js asks plugins to bind here to add own params
        rcmail.addEventListener('requestrefresh', add_tabid);
        // the toolbar's Refresh button, which posts check-recent directly
        rcmail.addEventListener('requestcheck-recent', add_tabid);

        if (window.BroadcastChannel) {
            channel = new BroadcastChannel(CHANNEL);

            channel.onmessage = function (e) {
                var msg = e.data || {};

                // a channel never delivers to its own sender, so anything
                // arriving under our own id comes from a duplicated tab
                if (msg.tabid == tabid) {
                    if (msg.type == 'hello') {
                        channel.postMessage({ type: 'taken', tabid: tabid });
                    } else if (msg.type == 'taken') {
                        tabid = make_id();
                        store_id(tabid);
                    }

                    return;
                }

                if (msg.type == 'changed' && msg.mbox == rcmail.env.mailbox) {
                    // stagger, so a dozen tabs do not all hit the server at once
                    setTimeout(refresh, 100 + Math.floor(Math.random() * 400));
                }
            };

            channel.postMessage({ type: 'hello', tabid: tabid });

            rcmail.addEventListener('responseafter', function (e) {
                var action = e.response ? e.response.action : null;

                if (action && MUTATIONS.indexOf(action) >= 0) {
                    announce();
                }
            });
        }

        // with polling switched off, leave the request rate alone and only keep
        // the tab id, which the Refresh button still benefits from
        if (rcmail.env.refresh_interval) {
            $(document).on('visibilitychange', function () {
                if (!document.hidden) {
                    refresh();
                }
            });

            // this tab has no reference values on the server yet; seeding them
            // now keeps the gap in which another tab can swallow a new message
            // down to about a second
            setTimeout(refresh, 1000);
        }
    });
}

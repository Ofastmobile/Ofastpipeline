/**
 * OFast Pipeline chat widget.
 *
 * Two modes: an AI assistant, and a human support agent. The widget never
 * mentions how staff answer; it only talks to our own REST API.
 *
 * Message tracking uses server message IDs (not counts), so nothing is shown
 * twice or skipped, however many messages a chat has.
 */
(function () {
    'use strict';

    if (typeof OFP_CHAT_DATA === 'undefined') {
        return;
    }

    var CFG = OFP_CHAT_DATA;

    var SESSION_RE = /^[A-Za-z0-9_-]{16,64}$/;
    var KEY_SESSION = 'ofp_chat_session_id';
    var KEY_SEEN = 'ofp_chat_last_seen';
    var KEY_GUEST = 'ofp_chat_guest';

    var POLL_OPEN_MS = 3000;       // chat window open
    var POLL_CLOSED_MS = 15000;    // minimized, only to show an unread badge
    var POLL_MAX_MS = 60000;       // ceiling when the network keeps failing
    var SEND_TIMEOUT_MS = 35000;   // the server may try several AI models before answering

    var TEXT = {
        statusAi: '⚡ AI assistant · replies instantly',
        statusHuman: '💬 Support team · replies appear here',
        placeholderAi: 'Type your message...',
        placeholderHuman: 'Message our support team...'
    };

    // ── Storage that survives private mode ─────────────────────────────

    var store = (function () {
        var mem = {};
        var ls = null;
        try {
            ls = window.localStorage;
            ls.setItem('__ofp_t', '1');
            ls.removeItem('__ofp_t');
        } catch (e) {
            ls = null;
        }
        return {
            get: function (k) {
                try { return ls ? ls.getItem(k) : (mem.hasOwnProperty(k) ? mem[k] : null); } catch (e) { return mem[k] || null; }
            },
            set: function (k, v) {
                try { if (ls) { ls.setItem(k, v); } else { mem[k] = v; } } catch (e) { mem[k] = v; }
            },
            remove: function (k) {
                try { if (ls) { ls.removeItem(k); } else { delete mem[k]; } } catch (e) { delete mem[k]; }
            }
        };
    })();

    function newSessionId() {
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < bytes.length; i++) { bytes[i] = Math.floor(Math.random() * 256); }
        }
        return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    var saved = store.get(KEY_SESSION);
    var hasSavedSession = !!(saved && SESSION_RE.test(saved));
    // Logged-in people share one chat across devices, so a brand-new device must still load it.
    var shouldSync = hasSavedSession || !!CFG.isLoggedIn;
    if (!hasSavedSession) {
        saved = newSessionId();
        store.set(KEY_SESSION, saved);
    }

    // ── State ──────────────────────────────────────────────────────────

    var state = {
        open: false,
        mode: 'ai',
        sessionId: saved,
        sending: false,
        loaded: false,            // chat window has been opened and history fetched
        cursor: parseInt(store.get(KEY_SEEN), 10) || 0,   // highest server message ID we have looked at
        seen: {},                 // server message IDs already on screen
        unread: 0,
        polling: false,
        timer: null,
        errors: 0,
        halted: false,            // team closed the chat; stop polling until the visitor writes again
        pendingHumanText: '',     // a message waiting for the pre-chat form
        fresh: store.get(KEY_SEEN) === null   // this device has never looked at the chat
    };

    var els = {};

    function $(id) { return document.getElementById(id); }

    // ── Text formatting (safe) ─────────────────────────────────────────

    /**
     * Escape everything first, then add a little formatting. Because quotes and
     * angle brackets are escaped up front, message text can never break out into HTML.
     */
    function formatText(text) {
        var links = [];
        var out = String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        // Lift URLs out so the bold/italic rules below cannot touch them.
        out = out.replace(/https?:\/\/(?:(?!&quot;|&#39;|&lt;|&gt;)[^\s<])+/g, function (match) {
            var trail = '';
            var t = match.match(/[.,;:!?)\]]+$/);
            if (t) {
                trail = t[0];
                match = match.slice(0, match.length - trail.length);
            }
            links.push(match);
            return '\u0000' + (links.length - 1) + '\u0000' + trail;
        });

        out = out
            .replace(/^(?:\*|-)\s+/gm, '• ')
            .replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>')
            .replace(/`([^`\n]+)`/g, '<code>$1</code>')
            .replace(/\n/g, '<br/>');

        return out.replace(/\u0000(\d+)\u0000/g, function (_, i) {
            var url = links[parseInt(i, 10)];
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer nofollow">' + url + '</a>';
        });
    }

    // ── Rendering ──────────────────────────────────────────────────────

    function timeLabel(when) {
        var d = when ? new Date(when) : new Date();
        if (isNaN(d.getTime())) { d = new Date(); }
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function stream() { return els.stream; }

    function scrollToBottom() {
        var s = stream();
        if (s) { setTimeout(function () { s.scrollTop = s.scrollHeight; }, 30); }
    }

    function put(node) {
        var s = stream();
        if (!s) { return node; }
        var typing = $('ofp-typing-box');
        if (typing) { s.insertBefore(node, typing); } else { s.appendChild(node); }
        scrollToBottom();
        return node;
    }

    /**
     * kind: user | bot | admin | system | error
     * opts: { time: ISO string }
     */
    function addBubble(kind, text, opts) {
        opts = opts || {};
        var wrap = document.createElement('div');
        wrap.className = 'ofp-rendered ofp-msg ofp-msg-' + kind;

        if (kind === 'system') {
            var sys = document.createElement('div');
            sys.className = 'ofp-msg-system-bubble';
            sys.innerHTML = formatText(text);
            wrap.appendChild(sys);
            return put(wrap);
        }

        var bubble = document.createElement('div');
        bubble.className = 'ofp-msg-bubble';

        if (kind === 'admin') {
            var label = document.createElement('div');
            label.className = 'ofp-msg-admin-label';
            label.textContent = '✓ Support team';
            bubble.appendChild(label);
        }

        var body = document.createElement('div');
        body.innerHTML = formatText(text);
        bubble.appendChild(body);
        wrap.appendChild(bubble);

        if (kind !== 'error') {
            var time = document.createElement('span');
            time.className = 'ofp-msg-time';
            time.textContent = timeLabel(opts.time);
            wrap.appendChild(time);
        }
        return put(wrap);
    }

    function addBanner(html) {
        var div = document.createElement('div');
        div.className = 'ofp-rendered ofp-mode-banner';
        div.innerHTML = html;
        put(div);
    }

    function showTyping() {
        if ($('ofp-typing-box')) { return; }
        var div = document.createElement('div');
        div.id = 'ofp-typing-box';
        div.className = 'ofp-msg ofp-msg-bot';
        div.innerHTML = '<div class="ofp-typing-indicator" aria-label="Assistant is typing"><span class="ofp-typing-dot"></span><span class="ofp-typing-dot"></span><span class="ofp-typing-dot"></span></div>';
        stream().appendChild(div);
        scrollToBottom();
    }

    function hideTyping() {
        var t = $('ofp-typing-box');
        if (t) { t.remove(); }
    }

    function clearRendered() {
        var nodes = stream().querySelectorAll('.ofp-rendered');
        for (var i = 0; i < nodes.length; i++) { nodes[i].remove(); }
    }

    function hideChips() {
        var chips = $('ofp-chat-chips');
        if (chips) { chips.style.display = 'none'; }
    }

    function updateBadge() {
        if (!els.badge) { return; }
        els.badge.textContent = state.unread > 9 ? '9+' : String(state.unread);
        els.badge.style.display = state.unread > 0 ? 'flex' : 'none';
    }

    // ── Server messages ────────────────────────────────────────────────

    function advance(id) {
        if (id > state.cursor) { state.cursor = id; }
    }

    function persistSeen() {
        store.set(KEY_SEEN, String(state.cursor));
    }

    /** Draw one message from the server unless it is already on screen. Returns true if drawn. */
    function renderServerMessage(m) {
        if (state.seen[m.id]) { return false; }
        state.seen[m.id] = true;
        advance(m.id);

        if (m.sender === 'user') {
            addBubble('user', m.message, { time: m.time });
        } else if (m.sender === 'admin') {
            addBubble('admin', m.message, { time: m.time });
        } else if (m.sender === 'system') {
            addBubble('system', m.message);
        } else {
            addBubble('bot', m.message, { time: m.time });
        }
        return true;
    }

    function resetSession() {
        stopPolling();
        store.remove(KEY_SEEN);
        state.sessionId = newSessionId();
        store.set(KEY_SESSION, state.sessionId);
        state.cursor = 0;
        state.seen = {};
        state.unread = 0;
        state.halted = false;
        updateBadge();
        clearRendered();
        var chips = $('ofp-chat-chips');
        if (chips) { chips.style.display = ''; }
        setMode('ai', { silent: true });
    }

    // ── API ────────────────────────────────────────────────────────────

    function api(path, options) {
        options = options || {};
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, options.timeout || 20000) : null;

        var init = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-WP-Nonce': CFG.nonce },
            signal: controller ? controller.signal : undefined
        };
        if (options.body) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }

        return fetch(CFG.apiUrl + path, init).then(function (res) {
            if (timer) { clearTimeout(timer); }
            return res.json().catch(function () { return {}; }).then(function (data) {
                return { ok: res.ok, status: res.status, data: data || {} };
            });
        }, function (err) {
            if (timer) { clearTimeout(timer); }
            throw err;
        });
    }

    function historyPath(afterId) {
        // The timestamp stops phone networks and proxies from replaying an old answer.
        return '/history?session_id=' + encodeURIComponent(state.sessionId) + '&after_id=' + afterId + '&_=' + Date.now();
    }

    function loadHistory() {
        return api(historyPath(0)).then(function (r) {
            var d = r.data;
            if (d.reset) { resetSession(); return; }

            clearRendered();
            state.seen = {};
            var msgs = d.messages || [];
            if (msgs.length) { hideChips(); }
            msgs.forEach(renderServerMessage);
            adoptServerMode(d);
            persistSeen();
        }).catch(function () { /* keep the welcome message; the visitor can still chat */ });
    }

    /** On page load, learn whether a human chat is in progress and whether replies are waiting. */
    function initialSync() {
        api(historyPath(state.cursor)).then(function (r) {
            if (!r.ok) { return; }
            var d = r.data;
            if (d.reset) { resetSession(); return; }
            adoptServerMode(d);
            handleNewMessages(d);
            if (state.fresh) { state.fresh = false; persistSeen(); }
        }).catch(function () {});
    }

    function adoptServerMode(d) {
        if (d.mode === 'human' && d.status !== 'closed' && CFG.allowHuman) {
            setMode('human', { silent: true });
        }
    }

    function handleNewMessages(d) {
        var msgs = d.messages || [];

        if (state.loaded) {
            var fresh = 0;
            msgs.forEach(function (m) {
                if (renderServerMessage(m) && m.sender === 'admin') { fresh++; }
            });
            if (fresh > 0) {
                if (state.open) { persistSeen(); } else { state.unread += fresh; updateBadge(); }
            }
        } else {
            // Window never opened on this page: count, but do not draw.
            msgs.forEach(function (m) {
                advance(m.id);
                if (m.sender === 'admin' && !state.fresh) { state.unread++; }
            });
            updateBadge();
        }

        if (d.status === 'closed' && state.mode === 'human') {
            state.halted = true;
            stopPolling();
        }
    }

    // ── Polling (human mode only) ──────────────────────────────────────

    function nextDelay() {
        var base = state.open ? POLL_OPEN_MS : POLL_CLOSED_MS;
        return Math.min(base * Math.pow(2, state.errors), POLL_MAX_MS);
    }

    function schedule(ms) {
        clearTimeout(state.timer);
        state.timer = setTimeout(tick, ms);
    }

    function startPolling() {
        if (state.polling || state.mode !== 'human' || state.halted) { return; }
        state.polling = true;
        schedule(state.open ? 1500 : POLL_CLOSED_MS);
    }

    function stopPolling() {
        state.polling = false;
        clearTimeout(state.timer);
        state.timer = null;
    }

    function tick() {
        state.timer = null;
        if (!state.polling) { return; }
        if (state.mode !== 'human' || state.halted) { stopPolling(); return; }
        if (document.hidden) { return; } // Resumes from the visibilitychange handler below.

        api(historyPath(state.cursor)).then(function (r) {
            if (!r.ok) { throw new Error('http ' + r.status); }
            if (r.data.reset) { resetSession(); return; }
            state.errors = 0;
            handleNewMessages(r.data);
        }).catch(function () {
            state.errors = Math.min(state.errors + 1, 4);
        }).then(function () {
            if (state.polling) { schedule(nextDelay()); }
        });
    }

    // ── Modes ──────────────────────────────────────────────────────────

    function guestInfo() {
        try {
            var v = JSON.parse(store.get(KEY_GUEST) || '{}');
            return v && typeof v === 'object' ? v : {};
        } catch (e) {
            return {};
        }
    }

    function needsPrechat() {
        return state.mode === 'human' && !CFG.isLoggedIn && !guestInfo().name;
    }

    function syncPrechat() {
        var form = $('ofp-chat-prechat');
        var footer = els.footer;
        if (!form) { return; }
        var show = needsPrechat();
        form.style.display = show ? 'flex' : 'none';
        if (footer) { footer.style.display = show ? 'none' : ''; }
    }

    function setMode(mode, opts) {
        opts = opts || {};
        if (mode === 'human' && !CFG.allowHuman) { return; }

        var previous = state.mode;
        state.mode = mode;

        if (els.tabAi && els.tabHuman) {
            var human = mode === 'human';
            els.tabHuman.classList.toggle('is-active', human);
            els.tabAi.classList.toggle('is-active', !human);
            els.tabHuman.setAttribute('aria-selected', human ? 'true' : 'false');
            els.tabAi.setAttribute('aria-selected', human ? 'false' : 'true');
        }
        if (els.status) { els.status.textContent = mode === 'human' ? TEXT.statusHuman : TEXT.statusAi; }
        if (els.input) { els.input.placeholder = mode === 'human' ? TEXT.placeholderHuman : TEXT.placeholderAi; }

        syncPrechat();

        if (previous !== mode && !opts.silent && state.loaded) {
            addBanner(mode === 'human'
                ? '💬 You are now chatting with our <strong>support team</strong>'
                : '🤖 Back with the <strong>AI assistant</strong>');
        }

        if (mode === 'human') { startPolling(); } else { stopPolling(); }
    }

    // ── Sending ────────────────────────────────────────────────────────

    function setSending(on) {
        state.sending = on;
        if (els.sendBtn) { els.sendBtn.disabled = on; }
    }

    function sendMessage(text, existingBubble) {
        text = String(text || '').trim();
        if (!text || state.sending) { return; }

        if (needsPrechat()) {
            state.pendingHumanText = text;
            syncPrechat();
            return;
        }

        setSending(true);
        hideChips();

        var bubble = existingBubble || addBubble('user', text);
        bubble.classList.remove('is-failed');
        bubble.classList.add('is-sending');
        if (state.mode === 'ai') { showTyping(); }

        var payload = { session_id: state.sessionId, message: text, mode: state.mode };
        if (state.mode === 'human' && !CFG.isLoggedIn) { payload.guest = guestInfo(); }

        api('/send', { method: 'POST', body: payload, timeout: SEND_TIMEOUT_MS }).then(function (r) {
            finishSend(r, text, bubble);
        }, function () {
            finishSend(null, text, bubble);
        });
    }

    function finishSend(r, text, bubble) {
        hideTyping();
        setSending(false);
        bubble.classList.remove('is-sending');

        if (!r) {
            return showSendError(bubble, text, { error: 'We could not reach the server. Check your connection and try again.' });
        }

        var d = r.data || {};

        if (d.reset) {
            resetSession();
            addBubble('system', d.error || 'Your chat session expired. Please send your message again.');
            return;
        }

        if (r.ok && d.success) {
            if (d.user_message_id) { state.seen[d.user_message_id] = true; }

            if (d.mode === 'human') {
                state.halted = false;
                if (d.notice) { addBubble('system', d.notice); }
                if (d.delivered === false) {
                    addBubble('system', 'We could not alert the team right now, but your message is saved and we will reply here as soon as we can.');
                }
                startPolling();
            } else if (d.reply) {
                if (d.reply_id) { state.seen[d.reply_id] = true; }
                addBubble('bot', d.reply);
            }
            return;
        }

        showSendError(bubble, text, d);
    }

    /** A clear message with Try again and, when allowed, Talk to a human agent. */
    function showSendError(bubble, text, d) {
        bubble.classList.add('is-failed');

        var row = addBubble('error', d.error || 'Something went wrong. Please try again.');
        var actions = document.createElement('div');
        actions.className = 'ofp-msg-error-actions';

        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'ofp-retry-btn';
        retry.textContent = 'Try again';
        retry.addEventListener('click', function () {
            if (retry.disabled) { return; }
            row.remove();
            sendMessage(text, bubble);
        });
        actions.appendChild(retry);

        // Show a countdown when the server told us how long to wait.
        var wait = parseInt(d.retry_after, 10) || 0;
        if (wait > 0) {
            retry.disabled = true;
            var left = wait;
            retry.textContent = 'Try again in ' + left + 's';
            var iv = setInterval(function () {
                left--;
                if (left <= 0 || !document.body.contains(retry)) {
                    clearInterval(iv);
                    retry.disabled = false;
                    retry.textContent = 'Try again';
                } else {
                    retry.textContent = 'Try again in ' + left + 's';
                }
            }, 1000);
        }

        if (d.suggest_human && CFG.allowHuman && state.mode === 'ai') {
            var human = document.createElement('button');
            human.type = 'button';
            human.className = 'ofp-retry-btn ofp-retry-human';
            human.textContent = '💬 Talk to a human agent';
            human.addEventListener('click', function () {
                row.remove();
                bubble.remove();
                setMode('human');
                sendMessage(text); // Pass the question on so the visitor does not retype it.
            });
            actions.appendChild(human);
        }

        row.appendChild(actions);

        if (d.debug) { // Only admins receive this field.
            var dbg = document.createElement('div');
            dbg.className = 'ofp-msg-debug';
            dbg.textContent = d.debug;
            row.appendChild(dbg);
        }
        scrollToBottom();
    }

    // ── Open / close ───────────────────────────────────────────────────

    function toggleChat(open) {
        state.open = open;

        els.box.style.display = open ? 'flex' : 'none';
        els.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        els.launcher.setAttribute('aria-label', open ? 'Close chat' : 'Open chat');
        var iconOpen = els.launcher.querySelector('.ofp-icon-open');
        var iconClose = els.launcher.querySelector('.ofp-icon-close');
        if (iconOpen) { iconOpen.style.display = open ? 'none' : 'block'; }
        if (iconClose) { iconClose.style.display = open ? 'block' : 'none'; }

        if (!open) { return; }

        state.unread = 0;
        updateBadge();

        if (!state.loaded) {
            state.loaded = true;
            if (shouldSync) { loadHistory(); }
        } else {
            persistSeen();
        }

        scrollToBottom();
        if (els.input.offsetParent) { els.input.focus(); } // Skipped while the pre-chat form hides the input.
        if (state.mode === 'human') {
            startPolling();
            if (state.polling) { schedule(0); }
        }
    }

    // ── Wiring ─────────────────────────────────────────────────────────

    function init() {
        els.launcher = $('ofp-chat-launcher');
        els.box = $('ofp-chat-box');
        els.stream = $('ofp-chat-messages-body');
        els.input = $('ofp-chat-input');
        els.sendBtn = $('ofp-chat-send-btn');
        els.badge = $('ofp-chat-unread-badge');
        els.status = $('ofp-chat-status-text');
        els.tabAi = $('ofp-tab-ai');
        els.tabHuman = $('ofp-tab-human');
        els.footer = document.querySelector('#ofp-chat-box .ofp-chat-footer');

        if (!els.launcher || !els.box || !els.stream || !els.input) { return; }

        els.launcher.addEventListener('click', function () { toggleChat(!state.open); });

        var minimize = $('ofp-chat-minimize-btn');
        if (minimize) { minimize.addEventListener('click', function () { toggleChat(false); els.launcher.focus(); }); }

        els.box.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { toggleChat(false); els.launcher.focus(); }
        });

        if (els.tabAi) { els.tabAi.addEventListener('click', function () { setMode('ai'); }); }
        if (els.tabHuman) {
            els.tabHuman.addEventListener('click', function () {
                setMode('human');
                if (!needsPrechat()) { els.input.focus(); }
            });
        }

        var humanChip = $('ofp-chip-human-trigger');
        if (humanChip) {
            humanChip.addEventListener('click', function () {
                setMode('human');
                if (!needsPrechat()) { els.input.focus(); }
            });
        }

        // Quick-reply chips always talk to the AI.
        els.stream.addEventListener('click', function (e) {
            var chip = e.target.closest ? e.target.closest('.ofp-chip-btn[data-query]') : null;
            if (!chip) { return; }
            if (state.mode !== 'ai') { setMode('ai'); }
            sendMessage(chip.getAttribute('data-query'));
        });

        $('ofp-chat-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var text = els.input.value.trim();
            if (!text || state.sending) { return; }
            els.input.value = '';
            sendMessage(text);
        });

        var prechat = $('ofp-chat-prechat');
        if (prechat) {
            prechat.addEventListener('submit', function (e) {
                e.preventDefault();
                var name = $('ofp-prechat-name').value.trim();
                var email = $('ofp-prechat-email').value.trim();
                if (!name) { return; }
                store.set(KEY_GUEST, JSON.stringify({ name: name, email: email }));
                syncPrechat();
                els.input.focus();
                if (state.pendingHumanText) {
                    var queued = state.pendingHumanText;
                    state.pendingHumanText = '';
                    sendMessage(queued);
                }
            });
        }

        // Pause polling in background tabs; catch up the moment the tab returns.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && state.polling && !state.timer) { schedule(0); }
        });

        if (shouldSync) { initialSync(); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

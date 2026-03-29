/**
 * Core Chain Chat Widget
 * Auto-injected by the admin panel chatbot deploy feature
 */
(function() {
    const API_BASE = (document.currentScript?.src || '').replace('/assets/js/chatwidget.js', '/api/chat');
    if (!API_BASE) return;

    let sessionToken = null;
    let botName = 'Core Chain Bot';
    let widgetColor = '#4FC3F7';
    let isOpen = false;

    // Create widget HTML
    function createWidget() {
        const style = document.createElement('style');
        style.textContent = `
            #cc-chat-fab{position:fixed;bottom:24px;right:24px;width:56px;height:56px;border-radius:50%;background:${widgetColor};border:none;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,0.3);z-index:99999;display:flex;align-items:center;justify-content:center;transition:transform 0.2s;}
            #cc-chat-fab:hover{transform:scale(1.1);}
            #cc-chat-fab svg{width:28px;height:28px;fill:#fff;}
            #cc-chat-panel{position:fixed;bottom:90px;right:24px;width:370px;max-width:calc(100vw - 48px);height:500px;max-height:calc(100vh - 120px);background:#0d0d14;border:1px solid rgba(255,255,255,0.08);border-radius:16px;z-index:99999;display:none;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.5);}
            #cc-chat-panel.open{display:flex;}
            .cc-header{padding:16px;background:rgba(255,255,255,0.03);border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;gap:10px;}
            .cc-header-name{font-weight:600;color:#f0f0f5;font-size:0.95rem;flex:1;}
            .cc-close{background:none;border:none;color:#666;cursor:pointer;font-size:1.2rem;padding:4px;}
            .cc-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;}
            .cc-msg{max-width:85%;padding:10px 14px;border-radius:12px;font-size:0.85rem;line-height:1.5;word-wrap:break-word;}
            .cc-msg-bot{background:rgba(79,195,247,0.08);color:#ccc;align-self:flex-start;border-bottom-left-radius:4px;}
            .cc-msg-user{background:${widgetColor};color:#fff;align-self:flex-end;border-bottom-right-radius:4px;}
            .cc-msg-admin{background:rgba(34,197,94,0.1);color:#ccc;align-self:flex-start;border-bottom-left-radius:4px;border-left:2px solid #22c55e;}
            .cc-quick-replies{display:flex;flex-wrap:wrap;gap:6px;padding:0 16px 8px;}
            .cc-qr{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#aaa;padding:6px 12px;border-radius:20px;font-size:0.75rem;cursor:pointer;transition:background 0.2s;}
            .cc-qr:hover{background:rgba(79,195,247,0.15);color:#fff;}
            .cc-input-bar{padding:12px;border-top:1px solid rgba(255,255,255,0.06);display:flex;gap:8px;}
            .cc-input{flex:1;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#f0f0f5;padding:10px 14px;border-radius:24px;font-size:0.85rem;outline:none;}
            .cc-input::placeholder{color:#555;}
            .cc-send{background:${widgetColor};border:none;color:#fff;width:36px;height:36px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;}
            .cc-typing{color:#666;font-size:0.8rem;padding:4px 0;}
        `;
        document.head.appendChild(style);

        // FAB button
        const fab = document.createElement('button');
        fab.id = 'cc-chat-fab';
        fab.innerHTML = '<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>';
        fab.onclick = toggleChat;
        document.body.appendChild(fab);

        // Chat panel
        const panel = document.createElement('div');
        panel.id = 'cc-chat-panel';
        panel.innerHTML = `
            <div class="cc-header">
                <div class="cc-header-name">${botName}</div>
                <button class="cc-close" onclick="document.getElementById('cc-chat-panel').classList.remove('open');document.getElementById('cc-chat-fab').style.display='flex';">&times;</button>
            </div>
            <div class="cc-messages" id="cc-messages"></div>
            <div class="cc-quick-replies" id="cc-qr"></div>
            <div class="cc-input-bar">
                <input class="cc-input" id="cc-input" placeholder="Type a message..." autocomplete="off">
                <button class="cc-send" id="cc-send">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="#fff"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </div>
        `;
        document.body.appendChild(panel);

        document.getElementById('cc-input').addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(); });
        document.getElementById('cc-send').addEventListener('click', sendMessage);
    }

    function toggleChat() {
        const panel = document.getElementById('cc-chat-panel');
        if (!isOpen) {
            panel.classList.add('open');
            document.getElementById('cc-chat-fab').style.display = 'none';
            isOpen = true;
            if (!sessionToken) startSession();
            document.getElementById('cc-input').focus();
        } else {
            panel.classList.remove('open');
            document.getElementById('cc-chat-fab').style.display = 'flex';
            isOpen = false;
        }
    }

    function addMessage(text, role, quickReplies) {
        const container = document.getElementById('cc-messages');
        const msg = document.createElement('div');
        msg.className = 'cc-msg cc-msg-' + role;
        msg.innerHTML = text.replace(/\n/g, '<br>').replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        container.appendChild(msg);
        container.scrollTop = container.scrollHeight;

        if (quickReplies && quickReplies.length) {
            const qrContainer = document.getElementById('cc-qr');
            qrContainer.innerHTML = quickReplies.map(q =>
                '<button class="cc-qr" onclick="this.parentElement.innerHTML=\'\';document.getElementById(\'cc-input\').value=\'' + q.replace(/'/g, "\\'") + '\';document.getElementById(\'cc-send\').click();">' + q + '</button>'
            ).join('');
        }
    }

    function startSession() {
        fetch(API_BASE + '?action=start', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}' })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                sessionToken = d.session_token;
                botName = d.bot_name || botName;
                if (d.color) widgetColor = d.color;
                addMessage(d.greeting, 'bot', d.suggested);
            }
        });
    }

    function sendMessage() {
        const input = document.getElementById('cc-input');
        const text = input.value.trim();
        if (!text || !sessionToken) return;

        input.value = '';
        document.getElementById('cc-qr').innerHTML = '';
        addMessage(text, 'user');

        // Show typing indicator
        const typing = document.createElement('div');
        typing.className = 'cc-typing';
        typing.textContent = botName + ' is typing...';
        typing.id = 'cc-typing';
        document.getElementById('cc-messages').appendChild(typing);

        fetch(API_BASE + '?action=message', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ session_token: sessionToken, message: text })
        })
        .then(r => r.json())
        .then(d => {
            const t = document.getElementById('cc-typing');
            if (t) t.remove();
            if (d.success) {
                addMessage(d.response, 'bot', d.quick_replies);
            }
        })
        .catch(() => {
            const t = document.getElementById('cc-typing');
            if (t) t.remove();
        });
    }

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createWidget);
    } else {
        createWidget();
    }
})();

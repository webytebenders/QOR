/**
 * Core Chain Chatbot Widget
 * Embedded on all pages — bottom-right corner
 */

(function() {
    const API_URL = 'admin/api/chat';
    let sessionToken = null;
    let isOpen = false;
    let botName = 'Core Chain Bot';

    // Create widget HTML
    function createWidget() {
        const widget = document.createElement('div');
        widget.id = 'ccChat';
        widget.innerHTML = `
            <button class="cc-toggle" id="ccToggle" aria-label="Open chat">
                <svg class="cc-icon-chat" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#fff" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                <svg class="cc-icon-close" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#fff" stroke-width="2" style="display:none"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
            <div class="cc-window" id="ccWindow" style="display:none">
                <div class="cc-header">
                    <div class="cc-header-info">
                        <div class="cc-avatar">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                        </div>
                        <div>
                            <div class="cc-header-name" id="ccBotName">Core Chain Bot</div>
                            <div class="cc-header-status">Online</div>
                        </div>
                    </div>
                    <button class="cc-header-close" id="ccClose">&times;</button>
                </div>
                <div class="cc-messages" id="ccMessages"></div>
                <div class="cc-suggested" id="ccSuggested"></div>
                <div class="cc-input-wrap">
                    <input type="text" class="cc-input" id="ccInput" placeholder="Type a message..." autocomplete="off">
                    <button class="cc-send" id="ccSend">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(widget);
        injectStyles();
        bindEvents();
    }

    function injectStyles() {
        const style = document.createElement('style');
        style.textContent = `
            #ccChat{position:fixed;bottom:24px;right:24px;z-index:9999;font-family:Inter,-apple-system,sans-serif}
            .cc-toggle{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#4FC3F7,#F97316);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(79,195,247,0.3);transition:transform .2s}
            .cc-toggle:hover{transform:scale(1.08)}
            .cc-window{position:absolute;bottom:72px;right:0;width:370px;max-height:520px;background:#111118;border:1px solid rgba(255,255,255,0.06);border-radius:16px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,0.5);animation:ccSlideUp .25s ease}
            @keyframes ccSlideUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
            .cc-header{display:flex;align-items:center;justify-content:space-between;padding:16px;background:#0d0d14;border-bottom:1px solid rgba(255,255,255,0.06)}
            .cc-header-info{display:flex;align-items:center;gap:10px}
            .cc-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#4FC3F7,#F97316);display:flex;align-items:center;justify-content:center}
            .cc-header-name{font-weight:600;font-size:14px;color:#f0f0f5}
            .cc-header-status{font-size:11px;color:#22c55e}
            .cc-header-close{background:none;border:none;color:#666;font-size:24px;cursor:pointer;padding:0 4px}
            .cc-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px;min-height:280px;max-height:340px}
            .cc-msg{max-width:85%;animation:ccFade .2s ease}
            @keyframes ccFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
            .cc-msg-bot{align-self:flex-start}
            .cc-msg-user{align-self:flex-end}
            .cc-msg-bubble{padding:10px 14px;border-radius:14px;font-size:13px;line-height:1.5;color:#f0f0f5;word-wrap:break-word}
            .cc-msg-bot .cc-msg-bubble{background:#1a1a24;border-bottom-left-radius:4px}
            .cc-msg-user .cc-msg-bubble{background:linear-gradient(135deg,#4FC3F7,#2196F3);border-bottom-right-radius:4px}
            .cc-msg-bubble strong{font-weight:600;color:#fff}
            .cc-msg-bubble a{color:#4FC3F7;text-decoration:underline}
            .cc-msg-time{font-size:10px;color:#555;margin-top:4px;padding:0 4px}
            .cc-msg-bot .cc-msg-time{text-align:left}
            .cc-msg-user .cc-msg-time{text-align:right}
            .cc-suggested{padding:0 16px 8px;display:flex;flex-wrap:wrap;gap:6px}
            .cc-suggest-btn{font-size:11px;padding:6px 12px;background:#1a1a24;border:1px solid rgba(255,255,255,0.06);border-radius:100px;color:#9999aa;cursor:pointer;transition:all .2s;font-family:inherit}
            .cc-suggest-btn:hover{border-color:#4FC3F7;color:#4FC3F7}
            .cc-input-wrap{display:flex;padding:12px;border-top:1px solid rgba(255,255,255,0.06);background:#0d0d14}
            .cc-input{flex:1;padding:10px 14px;font-size:13px;background:#1a1a24;border:1px solid rgba(255,255,255,0.06);border-radius:100px;color:#f0f0f5;outline:none;font-family:inherit}
            .cc-input:focus{border-color:#4FC3F7}
            .cc-input::placeholder{color:#555}
            .cc-send{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#4FC3F7,#F97316);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;margin-left:8px;color:#000;transition:opacity .2s}
            .cc-send:hover{opacity:.9}
            .cc-typing{display:flex;gap:4px;padding:8px 14px;align-self:flex-start}
            .cc-typing span{width:6px;height:6px;border-radius:50%;background:#4FC3F7;animation:ccBounce 1.4s infinite}
            .cc-typing span:nth-child(2){animation-delay:.2s}
            .cc-typing span:nth-child(3){animation-delay:.4s}
            @keyframes ccBounce{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-6px)}}
            @media(max-width:480px){.cc-window{width:calc(100vw - 32px);right:-8px;bottom:66px;max-height:70vh}}
        `;
        document.head.appendChild(style);
    }

    function bindEvents() {
        const toggle = document.getElementById('ccToggle');
        const closeBtn = document.getElementById('ccClose');
        const input = document.getElementById('ccInput');
        const send = document.getElementById('ccSend');

        toggle.addEventListener('click', () => {
            isOpen = !isOpen;
            document.getElementById('ccWindow').style.display = isOpen ? 'flex' : 'none';
            toggle.querySelector('.cc-icon-chat').style.display = isOpen ? 'none' : 'block';
            toggle.querySelector('.cc-icon-close').style.display = isOpen ? 'block' : 'none';
            if (isOpen && !sessionToken) startSession();
            if (isOpen) input.focus();
        });

        closeBtn.addEventListener('click', () => {
            isOpen = false;
            document.getElementById('ccWindow').style.display = 'none';
            toggle.querySelector('.cc-icon-chat').style.display = 'block';
            toggle.querySelector('.cc-icon-close').style.display = 'none';
        });

        send.addEventListener('click', sendMessage);
        input.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendMessage(); });
    }

    function startSession() {
        fetch(API_URL + '?action=start', { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    sessionToken = data.session_token;
                    botName = data.bot_name || 'Core Chain Bot';
                    document.getElementById('ccBotName').textContent = botName;
                    addMessage('bot', data.greeting);

                    // Suggested questions
                    const suggested = document.getElementById('ccSuggested');
                    if (data.suggested && data.suggested.length) {
                        suggested.innerHTML = data.suggested.map(q =>
                            `<button class="cc-suggest-btn" onclick="this.parentElement.innerHTML='';document.getElementById('ccInput').value='${q.replace(/'/g,"\\'")}';document.getElementById('ccSend').click()">${q}</button>`
                        ).join('');
                    }
                }
            })
            .catch(() => addMessage('bot', 'Chat is currently unavailable. Please try again later.'));
    }

    function sendMessage() {
        const input = document.getElementById('ccInput');
        const msg = input.value.trim();
        if (!msg || !sessionToken) return;

        input.value = '';
        addMessage('user', msg);
        document.getElementById('ccSuggested').innerHTML = '';

        // Show typing
        const typing = document.createElement('div');
        typing.className = 'cc-typing';
        typing.innerHTML = '<span></span><span></span><span></span>';
        document.getElementById('ccMessages').appendChild(typing);
        scrollToBottom();

        fetch(API_URL + '?action=message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_token: sessionToken, message: msg })
        })
        .then(r => r.json())
        .then(data => {
            typing.remove();
            if (data.success) {
                addMessage('bot', data.response);
            } else {
                addMessage('bot', 'Sorry, something went wrong. Please try again.');
            }
        })
        .catch(() => {
            typing.remove();
            addMessage('bot', 'Connection error. Please check your internet and try again.');
        });
    }

    function addMessage(role, text) {
        const container = document.getElementById('ccMessages');
        // Convert markdown-style bold and links
        let html = text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank">$1</a>')
            .replace(/\n/g, '<br>');

        const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        const div = document.createElement('div');
        div.className = `cc-msg cc-msg-${role}`;
        div.innerHTML = `<div class="cc-msg-bubble">${html}</div><div class="cc-msg-time">${time}</div>`;
        container.appendChild(div);
        scrollToBottom();
    }

    function scrollToBottom() {
        const msgs = document.getElementById('ccMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    // Init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createWidget);
    } else {
        createWidget();
    }
})();

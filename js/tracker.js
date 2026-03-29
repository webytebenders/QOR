/**
 * Core Chain Analytics Tracker
 * Lightweight page view tracking — no cookies, privacy-friendly
 */
(function() {
    // Generate or retrieve session ID (sessionStorage only — not persistent)
    let sid = sessionStorage.getItem('cc_sid');
    if (!sid) {
        sid = Math.random().toString(36).substr(2) + Date.now().toString(36);
        sessionStorage.setItem('cc_sid', sid);
    }

    const data = {
        path: window.location.pathname + window.location.search,
        title: document.title,
        referrer: document.referrer || '',
        session_id: sid
    };

    // Send after page loads (non-blocking)
    if (document.readyState === 'complete') {
        send();
    } else {
        window.addEventListener('load', send);
    }

    function send() {
        fetch('admin/api/analytics?action=track', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).catch(function() {}); // Silently fail
    }
})();

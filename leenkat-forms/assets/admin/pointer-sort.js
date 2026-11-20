(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.leenfoPointerSort = api;
})(typeof window === 'undefined' ? null : window, function () {
    'use strict';
    function move(items, from, to) {
        const next = [...items];
        next.splice(to, 0, next.splice(from, 1)[0]);
        return next;
    }
    function insertionIndex(from, anchor, after, count) {
        return Math.max(0, Math.min(count - 1, anchor + (after ? 1 : 0) - (from < anchor + (after ? 1 : 0) ? 1 : 0)));
    }
    /** One pointer session owns its helper, capture and RAF; definitions change only on release. */
    function attach(container, callbacks) {
        const doc = container.ownerDocument;
        const win = doc.defaultView;
        let session = null;
        let frame = 0;
        let suppressClickUntil = 0;
        function finish(cancelled) {
            const state = session;
            if (!state) return;
            session = null;
            win.cancelAnimationFrame(frame); frame = 0;
            state.ghost?.remove();
            container.classList.remove('lkf-pointer-active');
            if (container.hasPointerCapture?.(state.pointerId)) container.releasePointerCapture(state.pointerId);
            if (state.started) {
                suppressClickUntil = Date.now() + 180;
                try { if (!cancelled && state.target) callbacks.drop(state.source, state.target); }
                finally { callbacks.end(); }
            }
        }
        function makeGhost(state) {
            const ghost = state.element.cloneNode(true);
            ghost.removeAttribute('id'); ghost.removeAttribute('draggable');
            ghost.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
            ghost.querySelectorAll('input,select,textarea,button,a').forEach(el => { el.tabIndex = -1; if ('disabled' in el) el.disabled = true; });
            ghost.setAttribute('aria-hidden', 'true');
            ghost.classList.add('lkf-pointer-helper');
            if (state.source.kind === 'new') {
                ghost.className = 'lkf-canvas-field lkf-pointer-helper lkf-pointer-new';
                ghost.textContent = state.element.textContent.replace(/^\+/, '').trim();
            }
            ghost.style.width = state.width + 'px';
            container.appendChild(ghost);
            state.ghost = ghost;
        }
        function tick() {
            if (!session?.started) return;
            const state = session;
            const x = state.source.kind === 'choice' ? state.rect.left : state.x - state.offsetX;
            state.ghost.style.transform = 'translate3d(' + x + 'px,' + (state.y - state.offsetY) + 'px,0)';
            const hit = doc.elementFromPoint(state.x, state.y);
            const scroller = hit?.closest('.lkf-canvas-stage,.lkf-drawer-content');
            if (scroller) {
                const bounds = scroller.getBoundingClientRect();
                const edge = Math.min(60, bounds.height / 4);
                const speed = state.y < bounds.top + edge ? -Math.ceil((bounds.top + edge - state.y) / 5) : state.y > bounds.bottom - edge ? Math.ceil((state.y - bounds.bottom + edge) / 5) : 0;
                if (speed) scroller.scrollTop += Math.max(-18, Math.min(18, speed));
            }
            const target = callbacks.target(state.source, { x: state.x, y: state.y }, state.target);
            state.ghost.classList.toggle('is-blocked', !target || !!target.blocked);
            if (JSON.stringify(target) !== JSON.stringify(state.target)) {
                state.target = target;
                callbacks.change(state.source, target, state.height);
            }
            frame = win.requestAnimationFrame(tick);
        }
        function down(event) {
            if (event.button !== 0 || event.isPrimary === false || session) return;
            const resolved = callbacks.source(event.target);
            if (!resolved) return;
            const rect = resolved.element.getBoundingClientRect();
            const canvas = container.querySelector('.lkf-canvas');
            session = { ...resolved, rect, pointerId: event.pointerId, x: event.clientX, y: event.clientY, startX: event.clientX, startY: event.clientY, offsetX: resolved.source.kind === 'new' ? 24 : event.clientX - rect.left, offsetY: resolved.source.kind === 'new' ? 24 : event.clientY - rect.top, width: resolved.source.kind === 'new' ? Math.max(240, (canvas?.clientWidth || 600) - 60) : rect.width, height: resolved.source.kind === 'new' ? 100 : rect.height, started: false, target: null };
            // Leave ordinary clicks, focus and text editing untouched until the movement threshold.
        }
        function motion(event) {
            if (!session || event.pointerId !== session.pointerId) return;
            session.x = event.clientX; session.y = event.clientY;
            if (!session.started && Math.hypot(session.x - session.startX, session.y - session.startY) >= 5) {
                session.started = true;
                container.setPointerCapture?.(session.pointerId);
                container.classList.add('lkf-pointer-active');
                makeGhost(session);
                callbacks.change(session.source, null, session.height);
                win.getSelection()?.removeAllRanges();
                frame = win.requestAnimationFrame(tick);
            }
            if (session.started) event.preventDefault();
        }
        function up(event) {
            if (!session || event.pointerId !== session.pointerId) return;
            if (session.started) {
                event.preventDefault();
                session.target = callbacks.target(session.source, { x: event.clientX, y: event.clientY }, session.target);
            }
            finish(false);
        }
        function cancel(event) { if (!event || !session || event.pointerId === undefined || event.pointerId === session.pointerId) finish(true); }
        function key(event) { if (event.key === 'Escape' && session?.started) { event.preventDefault(); event.stopPropagation(); finish(true); } }
        function click(event) { if (Date.now() < suppressClickUntil) { event.preventDefault(); event.stopPropagation(); } }
        container.addEventListener('pointerdown', down);
        doc.addEventListener('pointermove', motion, { passive: false });
        doc.addEventListener('pointerup', up);
        doc.addEventListener('pointercancel', cancel);
        doc.addEventListener('keydown', key, true);
        container.addEventListener('click', click, true);
        win.addEventListener('blur', cancel);
        return function () {
            finish(true);
            container.removeEventListener('pointerdown', down);
            doc.removeEventListener('pointermove', motion);
            doc.removeEventListener('pointerup', up);
            doc.removeEventListener('pointercancel', cancel);
            doc.removeEventListener('keydown', key, true);
            container.removeEventListener('click', click, true);
            win.removeEventListener('blur', cancel);
        };
    }
    return { attach, move, insertionIndex };
});

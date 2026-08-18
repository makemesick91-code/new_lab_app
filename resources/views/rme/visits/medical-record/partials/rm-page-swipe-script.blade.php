{{-- LEGACY-RME-DOCTOR-WORKSPACE-1A — THE swipe navigator for the RME page
     sequence. Extracted so every surface that renders `#rm-handwriting-swipe`
     shares one gesture implementation instead of growing a second one.

     It navigates by following the prev/next URL the server already put on the
     zone, so a swipe is exactly the same navigation as pressing Sebelumnya /
     Berikutnya — including onto a read-only legacy archive page.

     CLINICAL SAFETY: anything inside [data-ignore-swipe] is excluded, which is
     how a doctor's handwritten stroke can never be read as a page turn — the
     drawing canvas and the open editor overlay both carry that marker, and the
     archive viewer sets it while the page is zoomed (the doctor is panning
     then, not turning pages). Buttons, links and form controls are excluded
     too, and a gesture must be clearly horizontal to count at all. --}}
        <script>
        (function () {
            const zone = document.getElementById('rm-handwriting-swipe');
            if (!zone) return;

            const prevUrl = zone.dataset.prevUrl || '';
            const nextUrl = zone.dataset.nextUrl || '';
            const MIN_SWIPE = 60;
            const H_RATIO = 1.5;

            let startX = 0;
            let startY = 0;
            let tracking = false;
            let activePointerId = null;
            let touchFallbackActive = false;
            let swipeHandled = false;

            function shouldIgnore(target) {
                if (!target || !zone.contains(target)) return true;
                if (target.closest('[data-ignore-swipe]')) return true;
                if (target.closest('button, a, input, textarea, select, label')) return true;
                if (document.getElementById('rm-editor-overlay')?.classList.contains('flex')) return true;

                return false;
            }

            function isHorizontalSwipe(dx, dy) {
                return Math.abs(dx) >= MIN_SWIPE && Math.abs(dx) > Math.abs(dy) * H_RATIO;
            }

            function navigate(dx) {
                if (dx < 0 && nextUrl) {
                    swipeHandled = true;
                    if (window.rememberRmHandwritingScroll) window.rememberRmHandwritingScroll();
                    window.location.href = nextUrl;
                    return true;
                }
                if (dx > 0 && prevUrl) {
                    swipeHandled = true;
                    if (window.rememberRmHandwritingScroll) window.rememberRmHandwritingScroll();
                    window.location.href = prevUrl;
                    return true;
                }

                return false;
            }

            function resetTracking() {
                tracking = false;
                activePointerId = null;
                touchFallbackActive = false;
            }

            function onGestureEnd(clientX, clientY) {
                if (!tracking) return;
                const dx = clientX - startX;
                const dy = clientY - startY;
                resetTracking();
                if (!isHorizontalSwipe(dx, dy)) return;
                navigate(dx);
            }

            function onGestureStart(clientX, clientY, target) {
                if (shouldIgnore(target)) return;
                startX = clientX;
                startY = clientY;
                tracking = true;
            }

            zone.addEventListener('pointerdown', function (e) {
                if (e.pointerType === 'mouse' && e.button !== 0) return;
                if (shouldIgnore(e.target)) return;
                touchFallbackActive = false;
                activePointerId = e.pointerId;
                onGestureStart(e.clientX, e.clientY, e.target);
                if (e.pointerType !== 'mouse') {
                    try { zone.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
                }
            });

            zone.addEventListener('pointerup', function (e) {
                if (activePointerId !== null && e.pointerId !== activePointerId) return;
                onGestureEnd(e.clientX, e.clientY);
                try { zone.releasePointerCapture(e.pointerId); } catch (err) { /* ignore */ }
            });

            zone.addEventListener('pointercancel', function (e) {
                if (activePointerId !== null && e.pointerId !== activePointerId) return;
                resetTracking();
            });

            // Touch fallback when pointer events are cancelled or unavailable on preview surfaces.
            zone.addEventListener('touchstart', function (e) {
                if (activePointerId !== null) return;
                if (shouldIgnore(e.target)) return;
                const t = e.touches[0];
                if (!t) return;
                touchFallbackActive = true;
                onGestureStart(t.clientX, t.clientY, e.target);
            }, { passive: true });

            zone.addEventListener('touchend', function (e) {
                if (!touchFallbackActive || activePointerId !== null) return;
                const t = e.changedTouches[0];
                if (!t) return;
                onGestureEnd(t.clientX, t.clientY);
            }, { passive: true });

            zone.addEventListener('touchcancel', function () {
                if (!touchFallbackActive || activePointerId !== null) return;
                resetTracking();
            }, { passive: true });

            window.__rmHandwritingSwipeHandled = function () {
                if (!swipeHandled) return false;
                swipeHandled = false;
                return true;
            };
        })();
        </script>

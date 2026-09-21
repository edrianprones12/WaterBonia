/**
 * WaterBonia - Client-side JavaScript
 */
(function () {
    'use strict';

    /* ── Sidebar Toggle (mobile) ────────────────────────── */
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    let overlay = null;

    function createOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', closeSidebar);
    }

    function openSidebar() {
        sidebar.classList.add('open');
        if (!overlay) createOverlay();
        overlay.classList.add('active');
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }

    /* ── Auto-dismiss alerts ────────────────────────────── */
    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        setTimeout(function () {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.3s';
            setTimeout(function () { el.remove(); }, 300);
        }, 5000);
    });

    /* ── Confirm actions ────────────────────────────────── */
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    /* ── Water gauge animation ──────────────────────────── */
    document.querySelectorAll('.water-gauge').forEach(function (gauge) {
        var fill = gauge.querySelector('.water-fill');
        var pct = parseInt(gauge.dataset.level || '0', 10);
        pct = Math.max(0, Math.min(100, pct));
        if (fill) {
            setTimeout(function () { fill.style.height = pct + '%'; }, 100);
        }
    });

    /* ── Simple inline chart (sparkline) ────────────────── */
    window.WBChart = {
        line: function (canvasId, labels, data, opts) {
            var canvas = document.getElementById(canvasId);
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var w = canvas.parentElement.clientWidth;
            var h = opts && opts.height || 200;
            canvas.width = w;
            canvas.height = h;

            if (!data || data.length === 0) {
                ctx.fillStyle = '#94a3b8';
                ctx.font = '13px -apple-system, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('No data available', w / 2, h / 2);
                return;
            }

            var max = Math.max.apply(null, data);
            var min = Math.min.apply(null, data);
            if (max === min) { max += 1; min -= 1; }
            var pad = { top: 20, right: 20, bottom: 30, left: 45 };
            var cw = w - pad.left - pad.right;
            var ch = h - pad.top - pad.bottom;
            var color = opts && opts.color || '#0284c7';

            ctx.clearRect(0, 0, w, h);

            // Grid lines
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;
            for (var i = 0; i <= 4; i++) {
                var y = pad.top + ch - (ch * i / 4);
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(pad.left + cw, y);
                ctx.stroke();
                ctx.fillStyle = '#94a3b8';
                ctx.font = '10px -apple-system, sans-serif';
                ctx.textAlign = 'right';
                var val = min + (max - min) * i / 4;
                ctx.fillText(Math.round(val), pad.left - 6, y + 3);
            }

            // Line
            ctx.strokeStyle = color;
            ctx.lineWidth = 2;
            ctx.lineJoin = 'round';
            ctx.beginPath();
            data.forEach(function (v, idx) {
                var x = pad.left + (cw * idx / (data.length - 1 || 1));
                var y = pad.top + ch - (ch * (v - min) / (max - min));
                idx === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
            });
            ctx.stroke();

            // Fill
            ctx.globalAlpha = 0.08;
            ctx.fillStyle = color;
            ctx.lineTo(pad.left + cw, pad.top + ch);
            ctx.lineTo(pad.left, pad.top + ch);
            ctx.closePath();
            ctx.fill();
            ctx.globalAlpha = 1;
        }
    };
})();

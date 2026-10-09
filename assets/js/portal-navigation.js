/* Switch portal sections without changing the dispensing controller or its active session. */
(function () {
    'use strict';

    function initialize() {
        const sections = new Map();
        document.querySelectorAll('[data-portal-section]').forEach(function (section) {
            const key = section.dataset.portalSection;
            if (key && section.id === key) sections.set(key, section);
        });
        if (!sections.size) return;

        const links = document.querySelectorAll('[data-portal-link]');
        const pageHeading = document.getElementById('portal-page-heading');
        const defaultKey = sections.has('dispense-workflow') ? 'dispense-workflow' : sections.keys().next().value;
        let activeKey = defaultKey;

        function keyFromHash(hash) {
            try {
                return decodeURIComponent(hash.replace(/^#/, ''));
            } catch (error) {
                return '';
            }
        }

        function selectSection(key, focus) {
            activeKey = sections.has(key) ? key : defaultKey;
            sections.forEach(function (section, sectionKey) {
                section.hidden = sectionKey !== activeKey;
            });
            const section = sections.get(activeKey);
            if (pageHeading && section.dataset.portalTitle) pageHeading.textContent = section.dataset.portalTitle;
            links.forEach(function (link) {
                if (link.dataset.portalLink === activeKey) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            });
            if (focus) {
                section.setAttribute('tabindex', '-1');
                section.focus({preventScroll: true});
                section.scrollIntoView({block: 'start', behavior: 'auto'});
            }
        }

        function showLocation() {
            const key = keyFromHash(window.location.hash);
            // The skip link targets the main landmark, while keeping the selected section.
            selectSection(key === 'execution-main' ? activeKey : key, false);
        }

        links.forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                if (link.hasAttribute('download') || (link.target && link.target !== '_self')) return;

                const key = link.dataset.portalLink;
                if (!sections.has(key)) return;
                let destination;
                try {
                    destination = new URL(link.href, window.location.href);
                } catch (error) {
                    return;
                }
                if (destination.origin !== window.location.origin || destination.pathname !== window.location.pathname
                    || destination.search !== window.location.search || keyFromHash(destination.hash) !== key) return;

                event.preventDefault();
                if (window.location.hash !== destination.hash) {
                    window.history.pushState(null, '', destination.hash);
                }
                const mobileMenu = link.closest('details');
                if (mobileMenu) mobileMenu.open = false;
                selectSection(key, true);
            });
        });

        window.addEventListener('hashchange', showLocation);
        window.addEventListener('popstate', showLocation);
        showLocation();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once: true});
    else initialize();
}());

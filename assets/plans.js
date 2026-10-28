/* Turns server-rendered category sections into accessible tabs. Without
   JavaScript every category stays visible with its heading. */
(function () {
    'use strict';

    function enhance(root) {
        var tablist = root.querySelector('.plansync__tabs');
        if (!tablist) {
            return;
        }
        var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
        var panels = tabs.map(function (tab) {
            return document.getElementById(tab.getAttribute('aria-controls'));
        });
        if (!tabs.length || panels.indexOf(null) !== -1) {
            return;
        }

        function select(index, focus) {
            tabs.forEach(function (tab, i) {
                var active = i === index;
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
                panels[i].hidden = !active;
            });
            if (focus) {
                tabs[index].focus();
            }
        }

        function indexForHash() {
            if (!window.location.hash) {
                return -1;
            }
            var target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
            for (var i = 0; i < panels.length; i++) {
                if (target && (panels[i] === target || panels[i].contains(target) || tabs[i] === target)) {
                    return i;
                }
            }
            return -1;
        }

        var start = Math.max(0, indexForHash());

        tablist.hidden = false;
        root.classList.add('is-enhanced');
        select(start, false);

        window.addEventListener('hashchange', function () {
            var index = indexForHash();
            if (index >= 0) {
                select(index, false);
            }
        });

        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () {
                select(i, false);
            });
            tab.addEventListener('keydown', function (event) {
                var next = null;
                if (event.key === 'ArrowRight') { next = (i + 1) % tabs.length; }
                if (event.key === 'ArrowLeft') { next = (i - 1 + tabs.length) % tabs.length; }
                if (event.key === 'Home') { next = 0; }
                if (event.key === 'End') { next = tabs.length - 1; }
                if (next !== null) {
                    event.preventDefault();
                    select(next, true);
                }
            });
        });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-plansync].is-tabs'), enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

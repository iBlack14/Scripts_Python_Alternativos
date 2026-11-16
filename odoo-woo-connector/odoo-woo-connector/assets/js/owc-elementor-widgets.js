(function () {
    function parseMoneyText(text) {
        return (text || '').replace(/\s+/g, ' ').trim();
    }

    function updateDynamicCartItems() {
        var amountNode = document.querySelector('.elementor-menu-cart__toggle_button .elementor-button-text, .cart-contents .woocommerce-Price-amount, .site-header-cart .woocommerce-Price-amount');
        var qtyNode = document.querySelector('.elementor-menu-cart__toggle_button .elementor-button-icon-qty, .cart-contents .count, .site-header-cart .count');
        var drawer = getDrawer();
        var drawerTotal = drawer ? drawer.getAttribute('data-owc-cart-subtotal') : null;
        var drawerQty = drawer ? drawer.getAttribute('data-owc-cart-count') : null;
        var total = drawerTotal !== null ? parseMoneyText(drawerTotal) : (amountNode ? parseMoneyText(amountNode.textContent) : '');
        var qty = drawerQty !== null ? parseMoneyText(drawerQty).replace(/[^0-9]/g, '') : (qtyNode ? parseMoneyText(qtyNode.textContent).replace(/[^0-9]/g, '') : '');

        document.querySelectorAll('.owc-action-bar__item[data-owc-dynamic-cart="yes"]').forEach(function (item) {
            var title = item.querySelector('.owc-action-bar__title');
            var subtitle = item.querySelector('.owc-action-bar__subtitle');

            if (title && qty) {
                title.textContent = qty === '1' ? '1 producto' : qty + ' productos';
            }

            if (subtitle) {
                subtitle.textContent = qty && qty !== '0' ? (total || 'Ver carrito') : 'Carrito vacio';
            }
        });
    }

    function getDrawer() {
        return document.querySelector('.owc-cart-drawer');
    }

    function syncDrawerStyles(item) {
        var drawer = getDrawer();
        var actionBar = item ? item.closest('.owc-action-bar') : document.querySelector('.owc-action-bar');

        if (!drawer || !actionBar) {
            return;
        }

        [
            '--owc-drawer-header-bg',
            '--owc-drawer-header-text',
            '--owc-drawer-title-size',
            '--owc-drawer-header-border',
            '--owc-drawer-panel-bg',
            '--owc-drawer-card-bg',
            '--owc-drawer-card-border',
            '--owc-drawer-product-text',
            '--owc-drawer-muted-text',
            '--owc-drawer-button-primary',
            '--owc-drawer-button-primary-hover',
            '--owc-drawer-button-secondary',
            '--owc-drawer-button-secondary-hover',
            '--owc-drawer-button-text',
            '--owc-drawer-button-text-hover',
            '--owc-drawer-close-bg'
        ].forEach(function (name) {
            var value = getComputedStyle(actionBar).getPropertyValue(name);
            if (value) {
                drawer.style.setProperty(name, value.trim());
            }
        });
    }

    function syncOpenDrawerStyles() {
        var drawer = getDrawer();

        if (drawer && drawer.classList.contains('owc-cart-drawer--open')) {
            syncDrawerStyles();
        }
    }

    function openDrawer(event) {
        var drawer = getDrawer();

        if (!drawer) {
            return false;
        }

        event.preventDefault();
        syncDrawerStyles();
        drawer.classList.add('owc-cart-drawer--open');
        drawer.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('owc-cart-drawer-active');
        return true;
    }

    function closeDrawer() {
        var drawer = getDrawer();

        if (!drawer) {
            return;
        }

        drawer.classList.remove('owc-cart-drawer--open');
        drawer.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('owc-cart-drawer-active');
    }

    function openElementorCart(event, trigger) {
        if (!trigger || trigger.getAttribute('aria-expanded') === 'true') {
            return false;
        }

        event.preventDefault();
        trigger.click();
        return true;
    }

    document.addEventListener('click', function (event) {
        var item = event.target.closest('.owc-action-bar__item[data-owc-action="open_cart"]');

        if (!item) {
            return;
        }

        // Prefer the connector drawer. Some Elementor headers show only their
        // backdrop when the menu-cart panel is not rendered on the current page.
        if (openDrawer(event)) {
            syncDrawerStyles(item);
            return;
        }

        var cartToggle = document.querySelector('.elementor-menu-cart__toggle_button');

        if (openElementorCart(event, cartToggle)) {
            return;
        }

        var fallbackToggle = document.querySelector('.elementor-menu-cart__toggle, .site-header-cart a, .cart-contents');

        if (openElementorCart(event, fallbackToggle)) {
            return;
        }

        if (!item.getAttribute('href') || item.getAttribute('href') === '#') {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-owc-cart-close]')) {
            event.preventDefault();
            closeDrawer();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeDrawer();
        }
    });

    updateDynamicCartItems();
    document.addEventListener('DOMContentLoaded', updateDynamicCartItems);
    document.addEventListener('DOMContentLoaded', function () {
        syncDrawerStyles();
    });
    document.addEventListener('input', syncOpenDrawerStyles, true);
    document.addEventListener('change', syncOpenDrawerStyles, true);
    setInterval(syncOpenDrawerStyles, 300);

    if (window.jQuery) {
        window.jQuery(document.body).on('wc_fragments_loaded wc_fragments_refreshed added_to_cart removed_from_cart updated_cart_totals', updateDynamicCartItems);
    }

    function loadExplorer(explorer, url, pushState) {
        if (!explorer || explorer.classList.contains('is-loading')) return;
        // El widget consulta productos internamente; mantener la URL pública limpia.
        url.searchParams.delete('post_type');
        var oldCategory = url.searchParams.get('owc_cat');
        if (oldCategory) {
            var matchingLink = Array.prototype.find.call(explorer.querySelectorAll('.owc-explorer__category-tree a, .owc-explorer__subcats a'), function (link) {
                return new URL(link.href, window.location.href).searchParams.get('owc_cat') === oldCategory;
            });
            if (matchingLink) {
                var readableSlug = matchingLink.getAttribute('data-owc-category-slug') || '';
                if (readableSlug) url.searchParams.set('categoria', readableSlug);
            }
            url.searchParams.delete('owc_cat');
        }
        if (!url.searchParams.get('s')) url.searchParams.delete('s');
        if (!url.searchParams.get('categoria')) url.searchParams.delete('categoria');
        if (url.searchParams.get('product-page') === '1') url.searchParams.delete('product-page');
        if (url.searchParams.get('owc_order') === 'relevance') url.searchParams.delete('owc_order');
        ['owc_min', 'owc_max', 'owc_cat'].forEach(function (key) { if (!url.searchParams.get(key) || url.searchParams.get(key) === '0') url.searchParams.delete(key); });
        explorer.classList.add('is-loading');
        explorer.setAttribute('aria-busy', 'true');

        fetch(url.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var id = explorer.getAttribute('data-owc-explorer');
                var replacement = doc.querySelector('.owc-explorer[data-owc-explorer="' + id + '"]') || doc.querySelector('.owc-explorer');
                if (!replacement) throw new Error('No se encontró el catálogo en la respuesta.');
                explorer.replaceWith(replacement);
                if (pushState) window.history.pushState({ owcExplorer: true }, '', url.toString());
                replacement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.dispatchEvent(new CustomEvent('owc:explorer-updated', { detail: { element: replacement } }));
            })
            .catch(function () { window.location.assign(url.toString()); })
            .finally(function () {
                if (explorer && explorer.isConnected) {
                    explorer.classList.remove('is-loading');
                    explorer.removeAttribute('aria-busy');
                }
            });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('.owc-explorer__category-tree a, .owc-explorer__subcats a, .owc-explorer__pagination a, .owc-explorer__chips a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var explorer = link.closest('.owc-explorer');
        if (!explorer) return;
        event.preventDefault();
        var targetUrl = new URL(link.href, window.location.href);
        if (link.closest('.owc-explorer__chips') && link === link.parentElement.firstElementChild && targetUrl.searchParams.get('categoria') === new URL(window.location.href).searchParams.get('categoria')) {
            targetUrl.searchParams.delete('categoria');
        }
        loadExplorer(explorer, targetUrl, true);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.owc-explorer form');
        if (!form) return;
        var explorer = form.closest('.owc-explorer');
        var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        url.search = new URLSearchParams(new FormData(form)).toString();
        event.preventDefault();
        loadExplorer(explorer, url, true);
    });

    document.addEventListener('change', function (event) {
        var select = event.target.closest('.owc-explorer__toolbar select[name="owc_order"]');
        if (select && select.form) select.form.requestSubmit();
    });

    function filterCategoryPicker(picker, query, letter) {
        query = (query || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
        letter = (letter || '').toLowerCase();
        var visible = 0;
        picker.querySelectorAll('.owc-explorer__category-tree a[data-category-name]').forEach(function (link) {
            var name = link.getAttribute('data-category-name') || '';
            var isAll = name === '';
            var show = isAll ? (!query && !letter) : ((!query || name.indexOf(query) !== -1) && (!letter || name.charAt(0) === letter));
            link.hidden = !show;
            if (show && !isAll) visible++;
        });
        var empty = picker.querySelector('.owc-explorer__category-empty');
        if (empty) empty.hidden = visible > 0 || (!query && !letter);
    }

    document.addEventListener('input', function (event) {
        var input = event.target.closest('.owc-explorer__category-search');
        if (!input) return;
        var picker = input.closest('.owc-explorer__category-picker');
        picker.querySelectorAll('[data-owc-letter]').forEach(function (button) { button.classList.toggle('is-active', button.getAttribute('data-owc-letter') === ''); });
        filterCategoryPicker(picker, input.value, '');
    });

    document.addEventListener('click', function (event) {
        var letterButton = event.target.closest('[data-owc-letter]');
        if (!letterButton) return;
        var picker = letterButton.closest('.owc-explorer__category-picker');
        var input = picker.querySelector('.owc-explorer__category-search');
        picker.querySelectorAll('[data-owc-letter]').forEach(function (button) { button.classList.toggle('is-active', button === letterButton); });
        if (input) input.value = '';
        filterCategoryPicker(picker, '', letterButton.getAttribute('data-owc-letter'));
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.owc-explorer__mobile-filter');
        if (!button) return;
        var explorer = button.closest('.owc-explorer');
        var sidebar = explorer && explorer.querySelector('.owc-explorer__filters');
        if (!sidebar) return;
        var open = sidebar.classList.toggle('is-open');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.textContent = open ? '✕ Ocultar filtros' : '☰ Mostrar filtros';
    });

    window.addEventListener('popstate', function () {
        var explorer = document.querySelector('.owc-explorer');
        if (explorer) loadExplorer(explorer, new URL(window.location.href), false);
    });
}());

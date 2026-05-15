/**
 * Trash Panda Roll-Offs — Admin App Scripts
 * Vanilla JS — no framework dependencies required.
 */

(function () {
    'use strict';

    /* =========================================================================
       1. Sidebar Toggle
       ========================================================================= */
    function initSidebarToggle() {
        var hamburger = document.getElementById('hamburgerBtn');
        var sidebar   = document.getElementById('tpSidebar');
        var overlay   = document.getElementById('tpSidebarOverlay');
        var desktopMq = window.matchMedia('(max-width: 768px)');
        var storageKey = 'tp-admin-sidebar-collapsed';

        if (!hamburger || !sidebar) return;

        function isMobile() {
            return desktopMq.matches;
        }

        function syncButtonState() {
            var expanded;
            if (isMobile()) {
                expanded = sidebar.classList.contains('open');
            } else {
                expanded = !document.body.classList.contains('sidebar-collapsed');
            }

            hamburger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            hamburger.setAttribute('title', expanded ? 'Hide navigation' : 'Show navigation');
            hamburger.setAttribute('aria-label', expanded ? 'Hide navigation' : 'Show navigation');
        }

        function setDesktopCollapsed(collapsed) {
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            try {
                window.localStorage.setItem(storageKey, collapsed ? '1' : '0');
            } catch (err) {}
            syncButtonState();
        }

        function closeMobileSidebar() {
            sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-open');
            syncButtonState();
        }

        function setMobileSidebar(open) {
            sidebar.classList.toggle('open', open);
            document.body.classList.toggle('sidebar-open', open);
            syncButtonState();
        }

        try {
            if (!isMobile() && window.localStorage.getItem(storageKey) === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (err) {}

        syncButtonState();

        hamburger.addEventListener('click', function (e) {
            e.stopPropagation();

            if (isMobile()) {
                setMobileSidebar(!sidebar.classList.contains('open'));
                return;
            }

            setDesktopCollapsed(!document.body.classList.contains('sidebar-collapsed'));
        });

        if (overlay) {
            overlay.addEventListener('click', function () {
                closeMobileSidebar();
            });
        }

        // Close mobile sidebar when clicking anywhere outside of it
        document.addEventListener('click', function (e) {
            if (
                isMobile() &&
                sidebar.classList.contains('open') &&
                !sidebar.contains(e.target) &&
                e.target !== hamburger
            ) {
                closeMobileSidebar();
            }
        });

        // Close sidebar on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isMobile() && sidebar.classList.contains('open')) {
                closeMobileSidebar();
            }
        });

        function handleViewportChange() {
            closeMobileSidebar();
            syncButtonState();
        }

        if (typeof desktopMq.addEventListener === 'function') {
            desktopMq.addEventListener('change', handleViewportChange);
        } else if (typeof desktopMq.addListener === 'function') {
            desktopMq.addListener(handleViewportChange);
        }
    }

    /* =========================================================================
       1b. Page Guide Widget
       ========================================================================= */
    function initPageGuide() {
        var trigger = document.getElementById('tpGuideTrigger');
        var panel   = document.getElementById('tpPageGuide');
        var close   = document.getElementById('tpGuideClose');

        if (!trigger || !panel) return;

        function setOpen(open) {
            panel.classList.toggle('open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        }

        trigger.addEventListener('click', function () {
            setOpen(!panel.classList.contains('open'));
        });

        if (close) {
            close.addEventListener('click', function () {
                setOpen(false);
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('open')) {
                setOpen(false);
            }
        });
    }

    /* =========================================================================
       2. Auto-Dismiss Alerts
       ========================================================================= */
    function initAutoDismissAlerts() {
        var alerts = document.querySelectorAll('.alert.alert-dismissible');

        alerts.forEach(function (alert) {
            setTimeout(function () {
                // Fade out
                alert.style.transition = 'opacity .5s ease';
                alert.style.opacity    = '0';

                setTimeout(function () {
                    if (alert.parentNode) {
                        alert.parentNode.removeChild(alert);
                    }
                }, 500);
            }, 4000);
        });
    }

    /* =========================================================================
       3. Confirm Deletes / Destructive Actions
       ========================================================================= */

    /**
     * Statuses that require an extra, explicit confirmation prompt.
     * These are checked in initStatusConfirm as well, but also guard
     * any data-confirm element whose message mentions these keywords.
     */
    var SENSITIVE_STATUSES = ['canceled', 'completed', 'archived'];

    function initConfirmDeletes() {
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-confirm]');
            if (!el) return;

            if (document.getElementById('tpConfirmModal')) {
                return;
            }

            var message = el.getAttribute('data-confirm') ||
                          'Are you sure you want to delete this item? This action cannot be undone.';

            // Check if this is a sensitive status change
            var isSensitive = SENSITIVE_STATUSES.some(function (s) {
                return message.toLowerCase().indexOf(s) !== -1;
            });

            if (isSensitive) {
                // Double-confirm for irreversible actions
                if (!window.confirm(message)) {
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }
                if (!window.confirm('This action is permanent. Are you absolutely sure?')) {
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }
            } else {
                if (!window.confirm(message)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }
        });
    }

    /* =========================================================================
       4. Quote Calculator
       ========================================================================= */
    function initQuoteCalculator() {
        var fieldIds = [
            'rental_price',
            'delivery_fee',
            'pickup_fee',
            'extra_fees',
            'tax_rate'
        ];

        // Check at least one field exists before attaching listeners
        var hasAny = fieldIds.some(function (id) {
            return document.getElementById(id) !== null;
        });

        if (!hasAny) return;

        function parseNum(id) {
            var el = document.getElementById(id);
            if (!el) return 0;
            var val = parseFloat(el.value);
            return isNaN(val) ? 0 : val;
        }

        function formatCurrency(value) {
            return '$' + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function setDisplay(id, value) {
            var el = document.getElementById(id);
            if (el) {
                el.textContent = formatCurrency(value);
            }
        }

        function recalculate() {
            var rentalPrice  = parseNum('rental_price');
            var deliveryFee  = parseNum('delivery_fee');
            var pickupFee    = parseNum('pickup_fee');
            var extraFees    = parseNum('extra_fees');
            var taxRate      = parseNum('tax_rate');   // percentage, e.g. 8.5 for 8.5 %

            var subtotal  = rentalPrice + deliveryFee + pickupFee + extraFees;
            var taxAmount = subtotal * (taxRate / 100);
            var total     = subtotal + taxAmount;

            setDisplay('calc-subtotal', subtotal);
            setDisplay('calc-tax',      taxAmount);
            setDisplay('calc-total',    total);

            // Also update any hidden total input if present
            var totalInput = document.getElementById('total_amount');
            if (totalInput) {
                totalInput.value = total.toFixed(2);
            }
        }

        fieldIds.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', recalculate);
                el.addEventListener('change', recalculate);
            }
        });

        // Run once on load so display is populated from pre-filled values
        recalculate();
    }

    /* =========================================================================
       5. Search Filter (client-side table filtering)
       ========================================================================= */
    function initSearchFilter() {
        var searchInputs = document.querySelectorAll('[data-search-table]');

        searchInputs.forEach(function (input) {
            var tableId = input.getAttribute('data-search-table');
            var table   = document.getElementById(tableId);

            if (!table) {
                // Fallback: look for the closest table in the same ancestor container
                var container = input.closest('.tp-card, .tp-table-wrap, section, div');
                if (container) {
                    table = container.querySelector('table');
                }
            }

            if (!table) return;

            var noResultsRow = null; // created lazily

            input.addEventListener('keyup', function () {
                var query = input.value.trim().toLowerCase();
                var tbody = table.querySelector('tbody');
                if (!tbody) return;

                var rows        = tbody.querySelectorAll('tr');
                var visibleCount = 0;

                rows.forEach(function (row) {
                    // Skip any "no results" row we injected
                    if (row.classList.contains('tp-no-results-row')) return;

                    var text = row.textContent.toLowerCase();

                    if (query === '' || text.indexOf(query) !== -1) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Show / hide "no results" message
                if (visibleCount === 0 && query !== '') {
                    if (!noResultsRow) {
                        noResultsRow = document.createElement('tr');
                        noResultsRow.className = 'tp-no-results-row';
                        var colCount = table.querySelectorAll('thead th').length || 1;
                        noResultsRow.innerHTML =
                            '<td colspan="' + colCount + '" ' +
                            'style="text-align:center;padding:1.5rem;color:var(--gy);' +
                            'font-family:\'Barlow Condensed\',sans-serif;font-size:.9rem;">' +
                            '<i class="fa-solid fa-magnifying-glass" style="margin-right:.4rem;"></i>' +
                            'No results found for &ldquo;' +
                            escapeHtml(input.value) +
                            '&rdquo;</td>';
                        tbody.appendChild(noResultsRow);
                    } else {
                        // Update the text in case the query changed
                        var td = noResultsRow.querySelector('td');
                        if (td) {
                            td.innerHTML =
                                '<i class="fa-solid fa-magnifying-glass" style="margin-right:.4rem;"></i>' +
                                'No results found for &ldquo;' +
                                escapeHtml(input.value) +
                                '&rdquo;';
                        }
                        noResultsRow.style.display = '';
                    }
                } else if (noResultsRow) {
                    noResultsRow.style.display = 'none';
                }
            });
        });
    }

    /** Minimal HTML-escape for user-supplied strings rendered inside innerHTML. */
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* =========================================================================
       6. Status Confirm
       ========================================================================= */
    /**
     * For status-change controls (select dropdowns or buttons) that carry a
     * data-status-confirm attribute, show an extra confirmation dialog when
     * the target status is "canceled", "completed", or "archived".
     *
     * Usage examples:
     *   <select data-status-confirm>…</select>
     *   <button data-status-confirm="canceled" …>Cancel Order</button>
     *   <a data-status-confirm="archived" href="…">Archive</a>
     */
    function initStatusConfirm() {
        // Handle <select> elements with data-status-confirm
        document.addEventListener('change', function (e) {
            var select = e.target.closest('select[data-status-confirm]');
            if (!select) return;

            var newStatus = select.value.toLowerCase();

            if (SENSITIVE_STATUSES.indexOf(newStatus) === -1) return;

            var label   = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
            var message = 'Set status to "' + label + '"? This action may be irreversible.';

            if (!window.confirm(message)) {
                // Revert to previous value stored in data attribute
                var prev = select.getAttribute('data-prev-value');
                if (prev !== null) {
                    select.value = prev;
                }
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        });

        // Store "previous value" on selects with data-status-confirm before change fires
        document.addEventListener('focus', function (e) {
            var select = e.target.closest('select[data-status-confirm]');
            if (select) {
                select.setAttribute('data-prev-value', select.value);
            }
        }, true);

        // Handle buttons / anchors with data-status-confirm="<status>"
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-status-confirm]');

            // Skip selects — handled above
            if (!el || el.tagName === 'SELECT') return;

            var targetStatus = (el.getAttribute('data-status-confirm') || '').toLowerCase();

            if (!targetStatus || SENSITIVE_STATUSES.indexOf(targetStatus) === -1) return;

            var label   = targetStatus.charAt(0).toUpperCase() + targetStatus.slice(1);
            var message = 'Mark this record as "' + label + '"? This may be irreversible.';

            if (!window.confirm(message)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }

    /* =========================================================================
       Bootstrap
       ========================================================================= */
    function init() {
        initSidebarToggle();
        initPageGuide();
        initAutoDismissAlerts();
        initConfirmDeletes();
        initQuoteCalculator();
        initSearchFilter();
        initStatusConfirm();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOMContentLoaded already fired
        init();
    }

}());

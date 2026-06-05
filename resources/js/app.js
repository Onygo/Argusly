import './bootstrap';
import './legal-navigation';

const applyResponsiveTables = () => {
    document.querySelectorAll('table').forEach((table) => {
        if (table.dataset.noResponsive === 'true') {
            return;
        }

        const headers = Array.from(table.querySelectorAll('thead th')).map((th) =>
            (th.textContent || '').trim()
        );

        if (headers.length === 0) {
            return;
        }

        table.classList.add('pl-responsive-table');

        table.querySelectorAll('tbody tr').forEach((row) => {
            const cells = Array.from(row.querySelectorAll('td'));
            cells.forEach((cell, index) => {
                const hasSingleValue = (cell.colSpan || 1) > 1;
                if (hasSingleValue) {
                    cell.dataset.label = '';
                    cell.classList.add('pl-no-label');
                    return;
                }

                const label = headers[index] || '';
                cell.dataset.label = label;
                if (label === '') {
                    cell.classList.add('pl-no-label');
                } else {
                    cell.classList.remove('pl-no-label');
                }
            });
        });
    });
};

const initGlobalSearch = () => {
    const searchInputs = Array.from(document.querySelectorAll('[data-global-search]'));
    if (searchInputs.length === 0) {
        return;
    }

    const escapeHtml = (value) =>
        String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    searchInputs.forEach((input) => {
        const form = input.closest('form');
        const dropdown = form?.querySelector('[data-search-dropdown]');
        const results = form?.querySelector('[data-search-results]');
        const allLink = form?.querySelector('[data-search-all-link]');
        const endpoint = input.dataset.searchEndpoint || '';
        if (!form || !dropdown || !results || endpoint === '') {
            return;
        }

        let activeRequest = null;

        const hideDropdown = () => {
            dropdown.classList.add('hidden');
        };

        const showDropdown = () => {
            dropdown.classList.remove('hidden');
        };

        const buildAllHref = (query) => {
            if (!allLink) {
                return;
            }

            const action = form.getAttribute('action') || '';
            if (action === '') {
                return;
            }

            const url = new URL(action, window.location.origin);
            if (query.trim() !== '') {
                url.searchParams.set('q', query.trim());
            } else {
                url.searchParams.delete('q');
            }

            allLink.setAttribute('href', url.pathname + url.search);
        };

        const renderItems = (items, query) => {
            buildAllHref(query);

            if (!Array.isArray(items) || items.length === 0) {
                results.innerHTML = '<p class="px-3 py-2 text-xs text-textSecondary">No matches found.</p>';
                showDropdown();
                return;
            }

            results.innerHTML = items
                .map((item) => {
                    const label = escapeHtml(item.label || '');
                    const subtitle = escapeHtml(item.subtitle || '');
                    const url = escapeHtml(item.url || '#');

                    return `<a href="${url}" class="block px-3 py-2 hover:bg-surfaceSubtle">
                        <p class="truncate text-sm font-medium text-textPrimary">${label}</p>
                        <p class="truncate text-xs text-textSecondary">${subtitle}</p>
                    </a>`;
                })
                .join('');
            showDropdown();
        };

        const fetchSuggestions = async (query) => {
            if (activeRequest && typeof activeRequest.abort === 'function') {
                activeRequest.abort();
            }

            if (query.trim().length < 2) {
                results.innerHTML = '';
                hideDropdown();
                buildAllHref(query);
                return;
            }

            const controller = new AbortController();
            activeRequest = controller;

            try {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('q', query.trim());

                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(`Search request failed with status ${response.status}`);
                }

                const payload = await response.json();
                renderItems(payload.items || [], query);
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    results.innerHTML = '<p class="px-3 py-2 text-xs text-danger">Unable to load search results.</p>';
                    showDropdown();
                }
            }
        };

        let debounceTimer = null;
        input.addEventListener('input', () => {
            const query = input.value || '';
            if (debounceTimer) {
                window.clearTimeout(debounceTimer);
            }
            debounceTimer = window.setTimeout(() => {
                fetchSuggestions(query);
            }, 160);
        });

        input.addEventListener('focus', () => {
            const query = input.value || '';
            if (query.trim().length >= 2) {
                fetchSuggestions(query);
            }
        });

        document.addEventListener('click', (event) => {
            if (!form.contains(event.target)) {
                hideDropdown();
            }
        });

        form.addEventListener('submit', () => {
            hideDropdown();
        });
    });
};

const initPublicMobileNav = () => {
    const toggle = document.querySelector('[data-mobile-nav-toggle]');
    const menu = document.querySelector('[data-mobile-nav-menu]');
    if (!toggle || !menu) {
        return;
    }

    const openIcon = toggle.querySelector('[data-mobile-nav-icon-open]');
    const closeIcon = toggle.querySelector('[data-mobile-nav-icon-close]');

    const setOpen = (isOpen) => {
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        menu.classList.toggle('hidden', !isOpen);
        document.body.classList.toggle('overflow-hidden', isOpen);
        if (openIcon) {
            openIcon.classList.toggle('hidden', isOpen);
        }
        if (closeIcon) {
            closeIcon.classList.toggle('hidden', !isOpen);
        }
    };

    setOpen(false);

    toggle.addEventListener('click', () => {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';
        setOpen(!isOpen);
    });

    menu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    document.addEventListener('click', (event) => {
        if (!menu.classList.contains('hidden') && !menu.contains(event.target) && !toggle.contains(event.target)) {
            setOpen(false);
        }
    });
};

const initWorkspaceIntelligenceHub = () => {
    const root = document.querySelector('[data-workspace-intelligence]');
    if (!root) {
        return;
    }

    const triggers = Array.from(root.querySelectorAll('[data-workspace-tab-trigger]'));
    const panels = Array.from(root.querySelectorAll('[data-workspace-tab-panel]'));
    const quickLinks = Array.from(root.querySelectorAll('[data-workspace-tab-link]'));

    if (triggers.length === 0 || panels.length === 0) {
        return;
    }

    const findPanel = (tabId) => panels.find((panel) => panel.dataset.workspaceTabPanel === tabId);
    const findTrigger = (tabId) => triggers.find((trigger) => trigger.dataset.workspaceTabTrigger === tabId);

    const setTab = (tabId, options = {}) => {
        const { updateHistory = true } = options;
        const nextTab = findPanel(tabId) ? tabId : triggers[0]?.dataset.workspaceTabTrigger;
        if (!nextTab) {
            return;
        }

        root.dataset.workspaceIntelligenceActiveTab = nextTab;

        triggers.forEach((trigger) => {
            const isActive = trigger.dataset.workspaceTabTrigger === nextTab;
            trigger.setAttribute('aria-selected', isActive ? 'true' : 'false');
            trigger.setAttribute('tabindex', isActive ? '0' : '-1');
            trigger.classList.toggle('bg-background', isActive);
            trigger.classList.toggle('text-textPrimary', isActive);
            trigger.classList.toggle('shadow-sm', isActive);
            trigger.classList.toggle('text-textSecondary', !isActive);
        });

        panels.forEach((panel) => {
            const isActive = panel.dataset.workspaceTabPanel === nextTab;
            panel.classList.toggle('hidden', !isActive);
            panel.classList.toggle('block', isActive);
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });

        if (updateHistory) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', nextTab);
            window.history.replaceState({}, '', url.toString());
        }
    };

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            const href = trigger.getAttribute('href') || '';
            if (href !== '') {
                event.preventDefault();
            }

            setTab(trigger.dataset.workspaceTabTrigger);
        });

        trigger.addEventListener('keydown', (event) => {
            const currentIndex = triggers.indexOf(trigger);
            if (currentIndex === -1) {
                return;
            }

            let targetIndex = currentIndex;
            if (event.key === 'ArrowRight') {
                targetIndex = (currentIndex + 1) % triggers.length;
            } else if (event.key === 'ArrowLeft') {
                targetIndex = (currentIndex - 1 + triggers.length) % triggers.length;
            } else if (event.key === 'Home') {
                targetIndex = 0;
            } else if (event.key === 'End') {
                targetIndex = triggers.length - 1;
            } else {
                return;
            }

            event.preventDefault();
            const nextTrigger = triggers[targetIndex];
            if (!nextTrigger) {
                return;
            }

            setTab(nextTrigger.dataset.workspaceTabTrigger);
            nextTrigger.focus();
        });
    });

    quickLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            const tabId = link.dataset.workspaceTabLink || '';
            if (!tabId) {
                return;
            }

            const href = link.getAttribute('href') || '';
            if (href !== '') {
                event.preventDefault();
            }

            setTab(tabId);

            const hash = link.hash || '';
            if (hash !== '') {
                const target = document.querySelector(hash);
                if (target instanceof HTMLElement) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    });

    setTab(root.dataset.workspaceIntelligenceActiveTab || triggers[0]?.dataset.workspaceTabTrigger, { updateHistory: false });
};

const initLlmTrackingDetail = () => {
    const root = document.querySelector('[data-llm-tracking-detail]');
    if (!root) {
        return;
    }

    const triggers = Array.from(root.querySelectorAll('[data-llm-tracking-tab-trigger]'));
    const panels = Array.from(root.querySelectorAll('[data-llm-tracking-tab-panel]'));
    const links = Array.from(root.querySelectorAll('[data-llm-tracking-tab-link]'));

    if (triggers.length === 0 || panels.length === 0) {
        return;
    }

    const findPanel = (tabId) => panels.find((panel) => panel.dataset.llmTrackingTabPanel === tabId);

    const setTab = (tabId, options = {}) => {
        const { updateHistory = true } = options;
        const nextTab = findPanel(tabId) ? tabId : triggers[0]?.dataset.llmTrackingTabTrigger;
        if (!nextTab) {
            return;
        }

        root.dataset.llmTrackingActiveTab = nextTab;

        triggers.forEach((trigger) => {
            const isActive = trigger.dataset.llmTrackingTabTrigger === nextTab;
            trigger.setAttribute('aria-selected', isActive ? 'true' : 'false');
            trigger.setAttribute('tabindex', isActive ? '0' : '-1');
            trigger.classList.toggle('bg-background', isActive);
            trigger.classList.toggle('text-textPrimary', isActive);
            trigger.classList.toggle('shadow-sm', isActive);
            trigger.classList.toggle('text-textSecondary', !isActive);
        });

        panels.forEach((panel) => {
            const isActive = panel.dataset.llmTrackingTabPanel === nextTab;
            panel.classList.toggle('hidden', !isActive);
            panel.classList.toggle('block', isActive);
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });

        if (updateHistory) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', nextTab);
            window.history.replaceState({}, '', url.toString());
        }
    };

    triggers.forEach((trigger, index) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            setTab(trigger.dataset.llmTrackingTabTrigger);
        });

        trigger.addEventListener('keydown', (event) => {
            let targetIndex = index;
            if (event.key === 'ArrowRight') {
                targetIndex = (index + 1) % triggers.length;
            } else if (event.key === 'ArrowLeft') {
                targetIndex = (index - 1 + triggers.length) % triggers.length;
            } else if (event.key === 'Home') {
                targetIndex = 0;
            } else if (event.key === 'End') {
                targetIndex = triggers.length - 1;
            } else {
                return;
            }

            event.preventDefault();
            const nextTrigger = triggers[targetIndex];
            if (!nextTrigger) {
                return;
            }

            setTab(nextTrigger.dataset.llmTrackingTabTrigger);
            nextTrigger.focus();
        });
    });

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            const tabId = link.dataset.llmTrackingTabLink || '';
            if (!tabId) {
                return;
            }

            event.preventDefault();
            setTab(tabId);

            const hash = link.hash || '';
            if (hash !== '') {
                const target = document.querySelector(hash);
                if (target instanceof HTMLElement) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    });

    setTab(root.dataset.llmTrackingActiveTab || triggers[0]?.dataset.llmTrackingTabTrigger, { updateHistory: false });
};

const initNotificationBells = () => {
    const bells = Array.from(document.querySelectorAll('[data-notification-bell]'));
    if (bells.length === 0) {
        return;
    }

    const genericErrorMessage = 'Unable to update notifications right now.';

    bells.forEach((bell) => {
        const content = bell.querySelector('[data-notification-bell-content]');
        const toggle = bell.querySelector('[data-notification-bell-toggle]');
        const menu = bell.querySelector('[data-notification-bell-menu]');
        const errorBox = bell.querySelector('[data-notification-bell-error]');

        if (!content || !toggle || !menu) {
            return;
        }

        const badgeClasses = [
            'absolute',
            '-right-1',
            '-top-1',
            'inline-flex',
            'min-h-4',
            'min-w-4',
            'items-center',
            'justify-center',
            'rounded-full',
            'bg-primary',
            'px-1',
            'text-[10px]',
            'font-semibold',
            'text-white',
        ];

        const resolveBadge = () => bell.querySelector('[data-notification-bell-badge]');

        const ensureBadge = () => {
            const existingBadge = resolveBadge();
            if (existingBadge) {
                return existingBadge;
            }

            const badge = document.createElement('span');
            badge.setAttribute('data-notification-bell-badge', '');
            badge.className = badgeClasses.join(' ');
            toggle.appendChild(badge);

            return badge;
        };

        const clearError = () => {
            if (!errorBox) {
                return;
            }

            errorBox.textContent = '';
            errorBox.classList.add('hidden');
        };

        const showError = (message) => {
            if (!errorBox) {
                return;
            }

            errorBox.textContent = message || genericErrorMessage;
            errorBox.classList.remove('hidden');
        };

        const setBadgeCount = (count) => {
            const unreadCount = Number.parseInt(String(count ?? 0), 10);
            if (!Number.isFinite(unreadCount) || unreadCount <= 0) {
                resolveBadge()?.remove();

                return;
            }

            const badge = ensureBadge();
            badge.textContent = String(Math.min(99, unreadCount));
        };

        bell.addEventListener('submit', async (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('[data-notification-bell-form]')) {
                return;
            }

            event.preventDefault();
            clearError();

            const submitButton = form.querySelector('button[type="submit"]');
            const originalText = submitButton?.textContent ?? '';
            const loadingLabel = submitButton?.dataset.loadingLabel || 'Saving...';

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
                submitButton.textContent = loadingLabel;
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const payload = await response.json().catch(() => null);

                if (!response.ok || !payload) {
                    throw new Error(payload?.message || genericErrorMessage);
                }

                if (typeof payload.menu_html === 'string') {
                    content.innerHTML = payload.menu_html;
                }

                setBadgeCount(payload.notificationBell?.unread_count ?? 0);
                clearError();
                menu.classList.remove('hidden');
            } catch (error) {
                const message =
                    error instanceof Error && error.message !== ''
                        ? error.message
                        : genericErrorMessage;

                showError(message);

                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.removeAttribute('aria-busy');
                    submitButton.textContent = originalText;
                }
            }
        });
    });
};

const initCalendarPlanning = () => {
    const calendarPage = document.querySelector('[data-calendar-page]');
    if (!calendarPage) {
        return;
    }

    const dayCards = Array.from(calendarPage.querySelectorAll('[data-calendar-day-card]'));
    const modeLinks = Array.from(calendarPage.querySelectorAll('[data-calendar-mode-link]'));
    const draggableItems = Array.from(calendarPage.querySelectorAll('[data-calendar-item-draggable="true"]'));
    const desktopSidebar = document.querySelector('[data-calendar-sidebar]');
    const desktopDateInput = desktopSidebar?.querySelector('[data-calendar-sidebar-date-input]') ?? null;
    const desktopTitleInput = desktopSidebar?.querySelector('[data-calendar-sidebar-title-input]') ?? null;
    const mobileSheet = document.querySelector('[data-calendar-mobile-sheet]');
    const mobileDateInput = mobileSheet?.querySelector('[data-calendar-sidebar-date-input]') ?? null;
    const mobileTitleInput = mobileSheet?.querySelector('[data-calendar-sidebar-title-input]') ?? null;
    const mobileBackdrop = document.getElementById('mobile-sidebar-backdrop');
    const mobileFab = document.getElementById('mobile-sidebar-fab');
    const mobileCloseButton = document.getElementById('mobile-sidebar-close');
    const overflowBackdrop = document.querySelector('[data-calendar-overflow-backdrop]');
    const overflowPanel = document.querySelector('[data-calendar-overflow-panel]');
    const overflowTitle = overflowPanel?.querySelector('[data-calendar-overflow-title]') ?? null;
    const overflowBody = overflowPanel?.querySelector('[data-calendar-overflow-body]') ?? null;
    const overflowCloseButton = overflowPanel?.querySelector('[data-calendar-overflow-close]') ?? null;
    const miniPickerToggle = calendarPage.querySelector('[data-calendar-mini-picker-toggle]');
    const miniPicker = calendarPage.querySelector('[data-calendar-mini-picker]');
    const miniPickerLabel = miniPicker?.querySelector('[data-calendar-mini-picker-label]') ?? null;
    const miniPickerGrid = miniPicker?.querySelector('[data-calendar-mini-picker-grid]') ?? null;
    const miniPickerPrev = miniPicker?.querySelector('[data-calendar-mini-picker-prev]') ?? null;
    const miniPickerNext = miniPicker?.querySelector('[data-calendar-mini-picker-next]') ?? null;

    const currentMode = calendarPage.dataset.calendarCurrentMode || 'month';
    const currentAnchorDate = calendarPage.dataset.calendarCurrentDate || '';
    const csrfToken = calendarPage.dataset.calendarCsrf || '';
    const showWeekNumbers = calendarPage.dataset.calendarWeekNumbers === 'true';

    const formatDateYmd = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    };

    const parseYmdDate = (value) => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) {
            return null;
        }

        return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    };

    const addDays = (date, amount) => {
        const nextDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        nextDate.setDate(nextDate.getDate() + amount);

        return nextDate;
    };

    const startOfIsoWeek = (date) => {
        const nextDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const day = nextDate.getDay();
        const diff = day === 0 ? -6 : 1 - day;
        nextDate.setDate(nextDate.getDate() + diff);

        return nextDate;
    };

    const startOfCalendarMonthGrid = (date) => {
        const monthStart = new Date(date.getFullYear(), date.getMonth(), 1);
        return startOfIsoWeek(monthStart);
    };

    const currentUrl = () => new URL(window.location.href);
    const isMobileViewport = () => window.innerWidth < 1024;
    const isVisible = (element) => Boolean(element && element.offsetParent !== null);
    const isMobileSheetOpen = () =>
        Boolean(mobileSheet && !mobileSheet.classList.contains('translate-y-full'));

    const dayCardMap = new Map(
        dayCards.map((card) => [card.getAttribute('data-day-key') || '', card])
    );

    const cardAtPosition = (row, col) =>
        dayCards.find(
            (card) =>
                Number.parseInt(card.dataset.calendarRow || '-1', 10) === row &&
                Number.parseInt(card.dataset.calendarCol || '-1', 10) === col
        ) ?? null;

    let currentSelectedDayKey =
        calendarPage.dataset.calendarSelectedDate ||
        dayCards.find((card) => card.dataset.calendarSelected === 'true')?.dataset.dayKey ||
        (currentMode === 'day' ? currentAnchorDate : '');
    let activeOverflowCard = null;
    let activeOverflowButton = null;
    let miniPickerMonth =
        parseYmdDate(miniPicker?.dataset.calendarMiniPickerAnchor || currentSelectedDayKey || currentAnchorDate) ||
        new Date();
    let dragState = null;
    let dropTargetCard = null;

    const monthTitleFormatter = new Intl.DateTimeFormat('nl-NL', {
        month: 'long',
        year: 'numeric',
    });

    const updateHistoryUrl = (dayKey) => {
        const nextUrl = buildCalendarUrl({
            mode: currentMode,
            date: currentMode === 'day' ? dayKey : currentAnchorDate,
            selectedDate: dayKey,
        });
        window.history.replaceState({}, '', nextUrl);
    };

    const buildCalendarUrl = ({ mode = currentMode, date = currentAnchorDate, selectedDate = null, weekNumbers = showWeekNumbers } = {}) => {
        const url = currentUrl();
        url.searchParams.set('mode', mode);
        url.searchParams.set('date', date || currentAnchorDate);

        const nextSelectedDate = selectedDate || '';
        if (nextSelectedDate !== '') {
            url.searchParams.set('selected_date', nextSelectedDate);
        } else if (mode === 'day') {
            url.searchParams.set('selected_date', date || currentAnchorDate);
        } else {
            url.searchParams.delete('selected_date');
        }

        if (weekNumbers) {
            url.searchParams.set('week_numbers', '1');
        } else {
            url.searchParams.delete('week_numbers');
        }

        return `${url.pathname}${url.search}`;
    };

    const updateModeLinks = (selectedDateKey) => {
        const resolvedDayKey =
            selectedDateKey ||
            currentSelectedDayKey ||
            currentAnchorDate ||
            dayCards[0]?.dataset.dayKey ||
            '';
        modeLinks.forEach((link) => {
            const nextMode = link.dataset.calendarModeValue || 'month';
            link.setAttribute(
                'href',
                buildCalendarUrl({
                    mode: nextMode,
                    date: resolvedDayKey,
                    selectedDate: resolvedDayKey,
                })
            );
        });
    };

    const syncMonthCardClasses = (card, isSelected) => {
        const isToday = card.dataset.dayIsToday === 'true';
        const isPast = card.dataset.dayIsPast === 'true';
        const isInAnchorMonth = card.dataset.dayInAnchorMonth === 'true';
        const dayNumber = card.querySelector('[data-calendar-day-number]');

        if (isSelected) {
            card.dataset.calendarSelected = 'true';
        } else {
            delete card.dataset.calendarSelected;
        }

        card.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        card.tabIndex = isSelected ? 0 : -1;

        card.classList.remove(
            'bg-primary/[0.03]',
            'bg-primary/[0.06]',
            'bg-surfaceSubtle/70',
            'bg-surfaceSubtle/40',
            'bg-surface',
            'hover:bg-surfaceSubtle/50',
            'ring-1',
            'ring-2',
            'ring-inset',
            'ring-primary/20',
            'ring-primary/30'
        );

        if (isSelected) {
            card.classList.add('bg-primary/[0.06]', 'ring-2', 'ring-inset', 'ring-primary/30');
        } else if (isToday) {
            card.classList.add('bg-primary/[0.03]', 'ring-1', 'ring-inset', 'ring-primary/20');
        } else if (!isInAnchorMonth) {
            card.classList.add('bg-surfaceSubtle/70');
        } else if (isPast) {
            card.classList.add('bg-surfaceSubtle/40');
        } else {
            card.classList.add('bg-surface', 'hover:bg-surfaceSubtle/50');
        }

        if (!dayNumber) {
            return;
        }

        dayNumber.classList.remove(
            'bg-primary',
            'text-textInverse',
            'shadow-sm',
            'bg-primary/15',
            'text-primary',
            'font-bold',
            'text-textPrimary',
            'text-textMuted',
            'text-textFaint'
        );

        if (isToday) {
            dayNumber.classList.add('bg-primary', 'text-textInverse', 'shadow-sm');
        } else if (isSelected) {
            dayNumber.classList.add('bg-primary/15', 'text-primary', 'font-bold');
        } else if (!isInAnchorMonth) {
            dayNumber.classList.add('text-textFaint');
        } else if (isPast) {
            dayNumber.classList.add('text-textMuted');
        } else {
            dayNumber.classList.add('text-textPrimary');
        }
    };

    const syncWeekCardClasses = (card, isSelected) => {
        const isToday = card.dataset.dayIsToday === 'true';
        const isPast = card.dataset.dayIsPast === 'true';
        const dayNumber = card.querySelector('[data-calendar-day-number]');

        if (isSelected) {
            card.dataset.calendarSelected = 'true';
        } else {
            delete card.dataset.calendarSelected;
        }

        card.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        card.tabIndex = isSelected ? 0 : -1;

        card.classList.remove(
            'border-primary/30',
            'border-primary/40',
            'border-border',
            'bg-surface',
            'bg-primary/[0.02]',
            'bg-surfaceSubtle/50',
            'hover:border-borderStrong',
            'ring-2',
            'ring-primary/10'
        );

        if (isSelected) {
            card.classList.add('border-primary/30', 'bg-surface', 'ring-2', 'ring-primary/10');
        } else if (isToday) {
            card.classList.add('border-primary/40', 'bg-primary/[0.02]');
        } else if (isPast) {
            card.classList.add('border-border', 'bg-surfaceSubtle/50');
        } else {
            card.classList.add('border-border', 'bg-surface', 'hover:border-borderStrong');
        }

        if (!dayNumber) {
            return;
        }

        dayNumber.classList.remove(
            'bg-primary',
            'text-textInverse',
            'shadow-sm',
            'bg-primary/10',
            'text-primary',
            'text-textPrimary',
            'text-textMuted'
        );

        if (isToday) {
            dayNumber.classList.add('bg-primary', 'text-textInverse', 'shadow-sm');
        } else if (isSelected) {
            dayNumber.classList.add('bg-primary/10', 'text-primary');
        } else if (isPast) {
            dayNumber.classList.add('text-textMuted');
        } else {
            dayNumber.classList.add('text-textPrimary');
        }
    };

    const syncDayCardClasses = (card, isSelected) => {
        if ((card.dataset.calendarDayView || 'month') === 'week') {
            syncWeekCardClasses(card, isSelected);
            return;
        }

        syncMonthCardClasses(card, isSelected);
    };

    const setDateInputs = (dayKey) => {
        if (desktopDateInput) {
            desktopDateInput.value = dayKey;
        }

        if (mobileDateInput) {
            mobileDateInput.value = dayKey;
        }
    };

    const syncToolbarSelectionInputs = (dayKey) => {
        calendarPage.querySelectorAll('form').forEach((form) => {
            const modeInput = form.querySelector('input[name="mode"]');
            if (!(modeInput instanceof HTMLInputElement)) {
                return;
            }

            let selectedDateInput = form.querySelector('input[name="selected_date"]');
            if (!(selectedDateInput instanceof HTMLInputElement)) {
                selectedDateInput = document.createElement('input');
                selectedDateInput.type = 'hidden';
                selectedDateInput.name = 'selected_date';
                form.appendChild(selectedDateInput);
            }
            selectedDateInput.value = dayKey;

            if (currentMode === 'day') {
                const dateInput = form.querySelector('input[name="date"]');
                if (dateInput instanceof HTMLInputElement) {
                    dateInput.value = dayKey;
                }
            }
        });
    };

    const scrollSidebarIntoView = () => {
        if (!desktopSidebar || !isVisible(desktopSidebar)) {
            return;
        }

        const rect = desktopSidebar.getBoundingClientRect();
        const sidebarIsClipped = rect.top < 88 || rect.bottom > window.innerHeight - 16;
        if (window.innerWidth < 1280 || sidebarIsClipped) {
            desktopSidebar.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    const openMobileSidebar = () => {
        if (!mobileBackdrop || !mobileSheet) {
            return;
        }

        mobileBackdrop.classList.remove('pointer-events-none', 'opacity-0');
        mobileBackdrop.classList.add('pointer-events-auto', 'opacity-100');
        mobileSheet.classList.remove('translate-y-full');
        mobileSheet.classList.add('translate-y-0');
        document.body.style.overflow = 'hidden';
    };

    const closeMobileSidebar = () => {
        if (!mobileBackdrop || !mobileSheet) {
            return;
        }

        mobileBackdrop.classList.add('pointer-events-none', 'opacity-0');
        mobileBackdrop.classList.remove('pointer-events-auto', 'opacity-100');
        mobileSheet.classList.add('translate-y-full');
        mobileSheet.classList.remove('translate-y-0');
        document.body.style.overflow = '';
    };

    const focusTitleInput = ({ preferMobile = false, delay = 0 } = {}) => {
        const input = preferMobile ? mobileTitleInput : desktopTitleInput;
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        window.setTimeout(() => {
            input.focus({ preventScroll: preferMobile });
        }, delay);
    };

    const focusDayCard = (card) => {
        if (!card) {
            return;
        }

        dayCards.forEach((dayCard) => {
            dayCard.tabIndex = dayCard === card ? 0 : -1;
        });
        card.focus({ preventScroll: false });
    };

    const renderMiniPicker = () => {
        if (!miniPicker || !miniPickerLabel || !miniPickerGrid) {
            return;
        }

        miniPickerLabel.textContent = monthTitleFormatter.format(miniPickerMonth);
        miniPickerGrid.innerHTML = '';

        const gridStart = startOfCalendarMonthGrid(miniPickerMonth);
        const todayKey = formatDateYmd(new Date());

        for (let index = 0; index < 42; index += 1) {
            const date = addDays(gridStart, index);
            const dateKey = formatDateYmd(date);
            const isCurrentMonth = date.getMonth() === miniPickerMonth.getMonth();
            const isSelected = currentSelectedDayKey === dateKey;
            const isToday = todayKey === dateKey;

            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.dayKey = dateKey;
            button.className =
                'flex h-9 items-center justify-center rounded-lg text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30';

            if (isSelected) {
                button.classList.add('bg-primary', 'font-semibold', 'text-textInverse');
            } else if (isToday) {
                button.classList.add('bg-primary/10', 'font-semibold', 'text-primary');
            } else if (!isCurrentMonth) {
                button.classList.add('text-textFaint', 'hover:bg-surfaceSubtle');
            } else {
                button.classList.add('text-textPrimary', 'hover:bg-surfaceSubtle');
            }

            button.textContent = String(date.getDate());
            button.addEventListener('click', () => {
                const destination = buildCalendarUrl({
                    mode: currentMode,
                    date: dateKey,
                    selectedDate: dateKey,
                });

                setDateInputs(dateKey);
                window.location.assign(destination);
            });

            miniPickerGrid.appendChild(button);
        }
    };

    const openMiniPicker = () => {
        if (!miniPicker || !miniPickerToggle) {
            return;
        }

        miniPickerMonth =
            parseYmdDate(currentSelectedDayKey || currentAnchorDate) ||
            parseYmdDate(miniPicker.dataset.calendarMiniPickerAnchor) ||
            new Date();
        renderMiniPicker();
        miniPicker.classList.remove('hidden');
        miniPicker.setAttribute('aria-hidden', 'false');
        miniPickerToggle.setAttribute('aria-expanded', 'true');
    };

    const closeMiniPicker = ({ restoreFocus = true } = {}) => {
        if (!miniPicker || !miniPickerToggle) {
            return;
        }

        miniPicker.classList.add('hidden');
        miniPicker.setAttribute('aria-hidden', 'true');
        miniPickerToggle.setAttribute('aria-expanded', 'false');

        if (restoreFocus) {
            miniPickerToggle.focus();
        }
    };

    const selectDay = (dayKey, { scrollSidebar = true, updateHistory = true } = {}) => {
        if (!dayKey) {
            return null;
        }

        currentSelectedDayKey = dayKey;

        dayCards.forEach((card) => {
            syncDayCardClasses(card, card.dataset.dayKey === dayKey);
        });

        const selectedCard = dayCardMap.get(dayKey) ?? null;
        if (selectedCard) {
            selectedCard.tabIndex = 0;
        }

        setDateInputs(dayKey);
        syncToolbarSelectionInputs(dayKey);
        updateModeLinks(dayKey);
        renderMiniPicker();

        if (updateHistory) {
            updateHistoryUrl(dayKey);
        }

        if (scrollSidebar) {
            scrollSidebarIntoView();
        }

        return selectedCard;
    };

    const clearDropTargetState = () => {
        if (!dropTargetCard) {
            return;
        }

        dropTargetCard.classList.remove('ring-2', 'ring-emerald-400', 'ring-inset', 'bg-primary/[0.08]');
        delete dropTargetCard.dataset.calendarDropActive;
        dropTargetCard = null;
    };

    const closeOverflowPanel = ({ restoreFocus = true } = {}) => {
        if (!overflowPanel || !overflowBody) {
            return;
        }

        overflowPanel.classList.add('hidden');
        overflowPanel.setAttribute('aria-hidden', 'true');
        overflowPanel.style.top = '';
        overflowPanel.style.right = '';
        overflowPanel.style.bottom = '';
        overflowPanel.style.left = '';
        overflowPanel.style.width = '';
        overflowPanel.style.visibility = '';
        overflowBody.innerHTML = '';

        if (overflowBackdrop) {
            overflowBackdrop.classList.add('hidden');
        }

        if (activeOverflowButton) {
            activeOverflowButton.setAttribute('aria-expanded', 'false');
        }

        const focusTarget = activeOverflowButton;
        activeOverflowCard = null;
        activeOverflowButton = null;

        if (restoreFocus && focusTarget instanceof HTMLElement) {
            focusTarget.focus();
        }
    };

    const positionOverflowPanel = (card, trigger) => {
        if (!overflowPanel) {
            return;
        }

        const safePadding = 16;

        if (isMobileViewport()) {
            overflowPanel.style.left = `${safePadding}px`;
            overflowPanel.style.right = `${safePadding}px`;
            overflowPanel.style.bottom = `${safePadding}px`;
            overflowPanel.style.top = 'auto';
            overflowPanel.style.width = 'auto';

            return;
        }

        overflowPanel.style.right = 'auto';
        overflowPanel.style.bottom = 'auto';
        overflowPanel.style.width = '22rem';

        const anchorRect = trigger.getBoundingClientRect();
        const cardRect = card.getBoundingClientRect();
        const panelRect = overflowPanel.getBoundingClientRect();

        let left = cardRect.left;
        if (left + panelRect.width > window.innerWidth - safePadding) {
            left = window.innerWidth - panelRect.width - safePadding;
        }
        left = Math.max(safePadding, left);

        let top = anchorRect.bottom + 8;
        if (top + panelRect.height > window.innerHeight - safePadding) {
            top = Math.max(safePadding, anchorRect.top - panelRect.height - 8);
        }

        overflowPanel.style.left = `${Math.round(left)}px`;
        overflowPanel.style.top = `${Math.round(top)}px`;
    };

    const openOverflowPanel = (card, trigger) => {
        const template = card?.querySelector('[data-calendar-day-items]');
        if (!(template instanceof HTMLTemplateElement) || !overflowPanel || !overflowBody || !trigger) {
            return;
        }

        if (activeOverflowButton === trigger && !overflowPanel.classList.contains('hidden')) {
            closeOverflowPanel();
            return;
        }

        closeMiniPicker({ restoreFocus: false });
        closeOverflowPanel({ restoreFocus: false });
        selectDay(card.dataset.dayKey || '', { scrollSidebar: false });

        overflowBody.appendChild(template.content.cloneNode(true));
        if (overflowTitle) {
            overflowTitle.textContent = card.dataset.dayLabel || card.dataset.dayKey || 'Dagdetails';
        }

        activeOverflowCard = card;
        activeOverflowButton = trigger;
        activeOverflowButton.setAttribute('aria-expanded', 'true');

        if (overflowBackdrop) {
            overflowBackdrop.classList.remove('hidden');
        }

        overflowPanel.classList.remove('hidden');
        overflowPanel.setAttribute('aria-hidden', 'false');
        overflowPanel.style.visibility = 'hidden';

        window.requestAnimationFrame(() => {
            positionOverflowPanel(card, trigger);
            overflowPanel.style.visibility = '';
        });
    };

    const handleFallbackItemClick = (button) => {
        const dayKey = button.getAttribute('data-day-key') || '';
        const card = dayCardMap.get(dayKey);
        if (!card) {
            return;
        }

        selectDay(dayKey);

        if (activeOverflowCard === card && overflowPanel && !overflowPanel.classList.contains('hidden')) {
            return;
        }

        const trigger = card.querySelector('[data-calendar-day-overflow]') || button;
        openOverflowPanel(card, trigger);
    };

    dayCards.forEach((card) => {
        syncDayCardClasses(card, card.dataset.calendarSelected === 'true');

        card.addEventListener('focus', () => {
            dayCards.forEach((dayCard) => {
                dayCard.tabIndex = dayCard === card ? 0 : -1;
            });
        });

        card.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            if (target.closest('a, button, input, select, textarea, label, form')) {
                return;
            }

            const dayKey = card.getAttribute('data-day-key') || '';
            selectDay(dayKey);
        });

        card.addEventListener('keydown', (event) => {
            if (event.target instanceof Element && event.target !== card) {
                if (event.target.closest('a, button, input, select, textarea, label, form')) {
                    return;
                }
            }

            const key = event.key;
            const currentRow = Number.parseInt(card.dataset.calendarRow || '0', 10);
            const currentCol = Number.parseInt(card.dataset.calendarCol || '0', 10);
            let nextCard = null;

            if (key === 'Enter' || key === ' ') {
                event.preventDefault();
                selectDay(card.dataset.dayKey || '');
                return;
            }

            if (key === 'ArrowLeft') {
                nextCard = cardAtPosition(currentRow, currentCol - 1) || dayCards[Math.max(0, dayCards.indexOf(card) - 1)] || null;
            } else if (key === 'ArrowRight') {
                nextCard = cardAtPosition(currentRow, currentCol + 1) || dayCards[Math.min(dayCards.length - 1, dayCards.indexOf(card) + 1)] || null;
            } else if (key === 'ArrowUp') {
                nextCard = cardAtPosition(currentRow - 1, currentCol);
            } else if (key === 'ArrowDown') {
                nextCard = cardAtPosition(currentRow + 1, currentCol);
            } else if (key === 'Home') {
                nextCard = cardAtPosition(currentRow, 0);
            } else if (key === 'End') {
                nextCard = cardAtPosition(currentRow, 6);
            }

            if (nextCard) {
                event.preventDefault();
                focusDayCard(nextCard);
            }
        });

        card.addEventListener('dragenter', (event) => {
            if (!dragState) {
                return;
            }

            event.preventDefault();
            clearDropTargetState();
            dropTargetCard = card;
            card.dataset.calendarDropActive = 'true';
            card.classList.add('ring-2', 'ring-emerald-400', 'ring-inset', 'bg-primary/[0.08]');
        });

        card.addEventListener('dragover', (event) => {
            if (!dragState) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        });

        card.addEventListener('dragleave', (event) => {
            if (event.relatedTarget && card.contains(event.relatedTarget)) {
                return;
            }

            if (dropTargetCard === card) {
                clearDropTargetState();
            }
        });

        card.addEventListener('drop', async (event) => {
            if (!dragState) {
                return;
            }

            event.preventDefault();

            const targetDayKey = card.dataset.dayKey || '';
            clearDropTargetState();

            if (
                targetDayKey === '' ||
                targetDayKey === dragState.originDayKey ||
                dragState.scheduleUrl === ''
            ) {
                dragState = null;
                return;
            }

            const timeValue = String(dragState.dateTime || '').slice(11, 16) || '09:00';
            const nextDateTime = `${targetDayKey}T${timeValue}`;
            card.classList.add('opacity-70');

            try {
                const formData = new FormData();
                formData.append('_token', csrfToken);
                formData.append('scheduled_publish_at', nextDateTime);

                const response = await fetch(dragState.scheduleUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(`Schedule update failed with status ${response.status}`);
                }

                window.location.assign(
                    buildCalendarUrl({
                        mode: currentMode === 'day' ? 'day' : currentMode,
                        date: targetDayKey,
                        selectedDate: targetDayKey,
                    })
                );
            } catch (error) {
                console.error(error);
                card.classList.remove('opacity-70');
            } finally {
                dragState = null;
            }
        });
    });

    draggableItems.forEach((item) => {
        item.addEventListener('dragstart', (event) => {
            dragState = {
                id: item.dataset.calendarItemId || '',
                originDayKey: item.dataset.calendarItemDayKey || '',
                scheduleUrl: item.dataset.calendarItemScheduleUrl || '',
                dateTime: item.dataset.calendarItemDatetime || '',
            };

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', dragState.id);
            }

            item.classList.add('opacity-60');
            closeOverflowPanel({ restoreFocus: false });
            closeMiniPicker({ restoreFocus: false });
        });

        item.addEventListener('dragend', () => {
            dragState = null;
            item.classList.remove('opacity-60');
            clearDropTargetState();
        });
    });

    calendarPage.querySelectorAll('[data-calendar-day-add]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const card = button.closest('[data-calendar-day-card]');
            const dayKey =
                button.getAttribute('data-day-key') ||
                card?.getAttribute('data-day-key') ||
                currentSelectedDayKey ||
                currentAnchorDate ||
                dayCards[0]?.dataset.dayKey ||
                '';

            selectDay(dayKey);
            closeOverflowPanel({ restoreFocus: false });
            closeMiniPicker({ restoreFocus: false });

            if (isMobileViewport() && mobileSheet) {
                openMobileSidebar();
                focusTitleInput({ preferMobile: true, delay: 320 });
                return;
            }

            focusTitleInput();
        });
    });

    calendarPage.querySelectorAll('[data-calendar-day-overflow]').forEach((button) => {
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-expanded', 'false');

        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const card = button.closest('[data-calendar-day-card]');
            if (!card) {
                return;
            }

            openOverflowPanel(card, button);
        });
    });

    calendarPage.querySelectorAll('[data-calendar-day-item-fallback]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            handleFallbackItemClick(button);
        });
    });

    if (overflowBody) {
        overflowBody.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            const fallbackButton = target.closest('[data-calendar-day-item-fallback]');
            if (!(fallbackButton instanceof HTMLButtonElement)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            handleFallbackItemClick(fallbackButton);
        });
    }

    if (miniPickerToggle) {
        miniPickerToggle.addEventListener('click', () => {
            if (miniPicker?.classList.contains('hidden')) {
                closeOverflowPanel({ restoreFocus: false });
                openMiniPicker();
            } else {
                closeMiniPicker();
            }
        });
    }

    if (miniPickerPrev) {
        miniPickerPrev.addEventListener('click', () => {
            miniPickerMonth = new Date(miniPickerMonth.getFullYear(), miniPickerMonth.getMonth() - 1, 1);
            renderMiniPicker();
        });
    }

    if (miniPickerNext) {
        miniPickerNext.addEventListener('click', () => {
            miniPickerMonth = new Date(miniPickerMonth.getFullYear(), miniPickerMonth.getMonth() + 1, 1);
            renderMiniPicker();
        });
    }

    if (mobileFab) {
        mobileFab.addEventListener('click', openMobileSidebar);
    }

    if (mobileBackdrop) {
        mobileBackdrop.addEventListener('click', closeMobileSidebar);
    }

    if (mobileCloseButton) {
        mobileCloseButton.addEventListener('click', closeMobileSidebar);
    }

    if (overflowBackdrop) {
        overflowBackdrop.addEventListener('click', () => closeOverflowPanel());
    }

    if (overflowCloseButton) {
        overflowCloseButton.addEventListener('click', () => closeOverflowPanel());
    }

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Node)) {
            return;
        }

        if (
            miniPicker &&
            !miniPicker.classList.contains('hidden') &&
            !miniPicker.contains(target) &&
            !miniPickerToggle?.contains(target)
        ) {
            closeMiniPicker({ restoreFocus: false });
        }

        if (!activeOverflowCard || !overflowPanel || overflowPanel.classList.contains('hidden')) {
            return;
        }

        if (overflowPanel.contains(target)) {
            return;
        }

        if (activeOverflowButton?.contains(target)) {
            return;
        }

        closeOverflowPanel({ restoreFocus: false });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeOverflowCard) {
            closeOverflowPanel();
            return;
        }

        if (event.key === 'Escape' && miniPicker && !miniPicker.classList.contains('hidden')) {
            closeMiniPicker();
            return;
        }

        if (event.key === 'Escape' && isMobileSheetOpen()) {
            closeMobileSidebar();
        }
    });

    window.addEventListener('resize', () => {
        if (!isMobileViewport() && isMobileSheetOpen()) {
            closeMobileSidebar();
        }

        if (miniPicker && !miniPicker.classList.contains('hidden')) {
            renderMiniPicker();
        }

        if (!activeOverflowCard || !activeOverflowButton || !overflowPanel) {
            return;
        }

        positionOverflowPanel(activeOverflowCard, activeOverflowButton);
    });

    updateModeLinks(currentSelectedDayKey);
    renderMiniPicker();
    if (currentSelectedDayKey) {
        selectDay(currentSelectedDayKey, { scrollSidebar: false, updateHistory: false });
    }
};

const initContentSiteTabs = () => {
    const siteTabsContainer = document.querySelector('[data-site-tabs]');
    if (!siteTabsContainer) {
        return;
    }

    const storageKey = 'publishlayer.content.lastSiteId.v1';
    const siteTabs = Array.from(siteTabsContainer.querySelectorAll('[data-site-tab]'));

    if (siteTabs.length === 0) {
        return;
    }

    // Store site selection on click
    siteTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const siteId = tab.dataset.siteTab;
            try {
                if (siteId === 'all') {
                    window.localStorage.removeItem(storageKey);
                } else {
                    window.localStorage.setItem(storageKey, siteId);
                }
            } catch (e) {
                // Ignore storage errors
            }
        });
    });

    // On load, if no site param in URL and we have a stored site, redirect
    const currentUrl = new URL(window.location.href);
    const hasSiteParam = currentUrl.searchParams.has('site');

    if (!hasSiteParam) {
        try {
            const storedSiteId = window.localStorage.getItem(storageKey);
            if (storedSiteId) {
                // Check if there's a tab for this site (it might have been deleted)
                const matchingTab = siteTabs.find((tab) => tab.dataset.siteTab === storedSiteId);
                if (matchingTab) {
                    // Navigate to the stored site
                    currentUrl.searchParams.set('site', storedSiteId);
                    window.location.replace(currentUrl.toString());
                    return;
                }
                // Site no longer exists, clear storage
                window.localStorage.removeItem(storageKey);
            }
        } catch (e) {
            // Ignore storage errors
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    applyResponsiveTables();
    initGlobalSearch();
    initPublicMobileNav();
    initNotificationBells();
    initWorkspaceIntelligenceHub();
    initLlmTrackingDetail();
    initCalendarPlanning();
    initContentSiteTabs();
});

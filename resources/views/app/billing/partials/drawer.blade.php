<div id="billing-drawer-overlay" class="fixed inset-0 z-40 hidden bg-black/30" aria-hidden="true"></div>
<aside id="billing-drawer" class="fixed right-0 top-0 z-50 h-full w-full max-w-xl translate-x-full border-l border-border bg-surface shadow-2xl transition-transform duration-200">
    <div class="flex items-center justify-between border-b border-border px-4 py-3">
        <h3 id="billing-drawer-title" class="text-sm font-semibold text-textPrimary">Details</h3>
        <button type="button" id="billing-drawer-close" class="rounded border border-border p-1.5" aria-label="Close details drawer">
            <i data-lucide="x" class="h-4 w-4"></i>
        </button>
    </div>
    <div id="billing-drawer-body" class="h-[calc(100%-57px)] overflow-y-auto p-4 text-sm"></div>
</aside>

<script>
(() => {
    // Simple client-side drawer with JSON payloads from each list row.
    const overlay = document.getElementById('billing-drawer-overlay');
    const drawer = document.getElementById('billing-drawer');
    const title = document.getElementById('billing-drawer-title');
    const body = document.getElementById('billing-drawer-body');
    const close = document.getElementById('billing-drawer-close');

    const esc = (value) => {
        if (value === null || value === undefined || value === '') return '-';
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    };

    const idRow = (label, value, copy = false) => `
        <div class="flex items-center justify-between gap-2 rounded border border-border px-3 py-2">
            <div>
                <p class="text-[11px] uppercase tracking-wide text-textSecondary">${esc(label)}</p>
                <p class="break-all text-sm text-textPrimary">${esc(value)}</p>
            </div>
            ${copy ? `<button type="button" data-copy="${esc(value)}" class="shrink-0 rounded border border-border px-2 py-1 text-xs">Copy</button>` : ''}
        </div>
    `;

    const amount = (cents, currency) => {
        const n = Number(cents || 0) / 100;
        return `${n.toFixed(2)} ${currency || ''}`.trim();
    };

    const lineItemRows = (meta, currency) => {
        const items = Array.isArray(meta?.line_items) ? meta.line_items.filter((item) => Number(item?.amount_cents || 0) > 0) : [];

        if (!items.length) {
            return '';
        }

        return `
            <div class="rounded border border-border px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-textSecondary">Checkout summary</p>
                <div class="mt-2 space-y-2">
                    ${items.map((item) => `
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm text-textPrimary">${esc(item.label || 'Line item')}</p>
                                <p class="text-[11px] uppercase tracking-wide text-textSecondary">${esc(item.type || 'one_time')}</p>
                            </div>
                            <p class="text-sm text-textPrimary">${esc(amount(item.amount_cents, currency))}</p>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    };

    const ledgerReferenceRows = (meta) => {
        const keys = ['payment_intent_id', 'payment_id', 'pack_purchase_id', 'content_id', 'draft_id', 'reservation_entry_id'];
        return keys.filter((key) => meta && meta[key]).map((key) => idRow(key, meta[key], true)).join('');
    };

    const render = (payload) => {
        if (payload.kind === 'wallet') {
            title.textContent = 'Wallet details';
            body.innerHTML = `
                <div class="space-y-2">
                    ${idRow('Wallet ID', payload.id || '-', Boolean(payload.id))}
                    ${idRow('Site', payload.site_name)}
                    ${idRow('Site ID', payload.site_id, true)}
                    ${idRow('Available', payload.available)}
                    ${idRow('Reserved', payload.reserved)}
                    ${idRow('Balance', payload.balance)}
                    ${idRow('Updated at', payload.updated_at)}
                </div>
            `;
            return;
        }

        if (payload.kind === 'ledger') {
            title.textContent = 'Ledger entry details';
            body.innerHTML = `
                <div class="space-y-2">
                    ${idRow('Entry ID', payload.id, true)}
                    ${idRow('Type', payload.type)}
                    ${idRow('Amount', payload.amount)}
                    ${idRow('Created at', payload.created_at)}
                    ${idRow('Site', payload.site_name)}
                    ${idRow('Site ID', payload.site_id, true)}
                    ${idRow('Note', payload.note || '-')}
                    ${idRow('Source type', payload.source_type || '-')}
                    ${idRow('Source ID', payload.source_id || '-', Boolean(payload.source_id))}
                    ${idRow('Brief ID', payload.brief_id || '-', Boolean(payload.brief_id))}
                    ${idRow('User ID', payload.user_id || '-', Boolean(payload.user_id))}
                    ${ledgerReferenceRows(payload.meta || {})}
                    <div class="rounded border border-border px-3 py-2">
                        <p class="text-[11px] uppercase tracking-wide text-textSecondary">Meta</p>
                        <pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs text-textPrimary">${esc(JSON.stringify(payload.meta || {}, null, 2))}</pre>
                    </div>
                </div>
            `;
            return;
        }

        if (payload.kind === 'payment') {
            title.textContent = 'Payment details';
            body.innerHTML = `
                <div class="space-y-2">
                    ${idRow('Payment ID', payload.id, true)}
                    ${idRow('Provider payment ID', payload.provider_payment_id || '-', Boolean(payload.provider_payment_id))}
                    ${idRow('Status', payload.status)}
                    ${idRow('Provider', payload.provider)}
                    ${idRow('Amount', amount(payload.amount_cents, payload.currency))}
                    ${idRow('Created', payload.created_at)}
                    ${idRow('Paid', payload.paid_at || '-')}
                    ${idRow('Failed', payload.failed_at || '-')}
                    ${idRow('Canceled', payload.canceled_at || '-')}
                    ${idRow('Site', payload.site_name)}
                    ${idRow('Site ID', payload.site_id || '-', Boolean(payload.site_id))}
                    ${idRow('Related object ID', payload.billable_id || '-', Boolean(payload.billable_id))}
                    ${idRow('Related object type', payload.billable_type || '-')}
                    ${payload.checkout_url ? `<a href="${esc(payload.checkout_url)}" target="_blank" rel="noopener" class="inline-flex rounded border border-border px-3 py-2 text-xs">Open provider link</a>` : ''}
                    ${lineItemRows(payload.meta || {}, payload.currency)}
                    <div class="rounded border border-border px-3 py-2">
                        <p class="text-[11px] uppercase tracking-wide text-textSecondary">Meta</p>
                        <pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs text-textPrimary">${esc(JSON.stringify(payload.meta || {}, null, 2))}</pre>
                    </div>
                </div>
            `;
            return;
        }

        if (payload.kind === 'subscription') {
            title.textContent = 'Subscription details';
            body.innerHTML = `
                <div class="space-y-2">
                    ${idRow('Subscription ID', payload.id, true)}
                    ${idRow('Plan', payload.plan_name)}
                    ${idRow('Status', payload.status)}
                    ${idRow('Renewal status', payload.status_reason || '-')}
                    ${idRow('Site', payload.site_name)}
                    ${idRow('Site ID', payload.site_id || '-', Boolean(payload.site_id))}
                    ${idRow('Renewal / ends at', payload.current_period_end || '-')}
                    ${idRow('Next payment', payload.next_payment_at || payload.current_period_end || '-')}
                    ${idRow('Monthly price', payload.price_cents ? `${(Number(payload.price_cents) / 100).toFixed(2)} ${payload.currency || 'EUR'}` : '-')}
                    ${idRow('Credits per month', payload.included_credits_per_interval ?? '-')}
                    ${idRow('Provider', payload.provider || '-')}
                    ${idRow('Provider customer ID', payload.provider_customer_id || '-', Boolean(payload.provider_customer_id))}
                    ${idRow('Provider subscription ID', payload.provider_subscription_id || '-', Boolean(payload.provider_subscription_id))}
                    ${idRow('Canceled at', payload.canceled_at || '-')}
                    <div class="rounded border border-border px-3 py-2">
                        <p class="text-[11px] uppercase tracking-wide text-textSecondary">Meta</p>
                        <pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs text-textPrimary">${esc(JSON.stringify(payload.meta || {}, null, 2))}</pre>
                    </div>
                </div>
            `;
            return;
        }

        title.textContent = 'Details';
        body.innerHTML = `<pre class="whitespace-pre-wrap text-xs text-textPrimary">${esc(JSON.stringify(payload, null, 2))}</pre>`;
    };

    const openDrawer = (payload) => {
        render(payload);
        overlay.classList.remove('hidden');
        drawer.classList.remove('translate-x-full');
    };

    const closeDrawer = () => {
        drawer.classList.add('translate-x-full');
        overlay.classList.add('hidden');
    };

    document.querySelectorAll('[data-open-drawer]').forEach((el) => {
        el.addEventListener('click', () => {
            const raw = el.getAttribute('data-drawer') || '{}';
            try {
                openDrawer(JSON.parse(raw));
            } catch (_) {
                openDrawer({});
            }
        });
    });

    document.addEventListener('click', async (event) => {
        const copyButton = event.target.closest('[data-copy]');
        if (!copyButton) {
            return;
        }

        const value = copyButton.getAttribute('data-copy') || '';
        if (!value || value === '-') {
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            copyButton.textContent = 'Copied';
            window.setTimeout(() => {
                copyButton.textContent = 'Copy';
            }, 1000);
        } catch (_) {
            copyButton.textContent = 'Failed';
        }
    });

    overlay.addEventListener('click', closeDrawer);
    close.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDrawer();
        }
    });
})();
</script>

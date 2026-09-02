/**
 * Admin Quick Menu — Admin2 plugin page.
 *
 * Reimplements the classic-admin "Quick Add Content" page as a component
 * mode plugin page: reads `menu_shortcuts` / `custom_links` via the
 * plugin's own GET /plugin/admin-quick-menu/shortcuts endpoint and, on
 * click, creates the page via the generic POST /pages endpoint. This
 * mirrors the classic-admin version's one-click flow, which posted straight
 * into Grav Admin's built-in "add page" task.
 *
 * The shortcuts endpoint is deliberately its own route rather than the
 * generic GET /config/plugins/admin-quick-menu one — that one is gated on
 * api.config.read, the same blanket permission that unlocks the whole
 * Configuration section, which would force granting full Configuration
 * access just to use Quick Add. The dedicated endpoint is gated on
 * api.pages.write instead, the same permission page creation already needs.
 */

const TAG = window.__GRAV_PAGE_TAG;
const API_BASE = (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
const API_TOKEN = window.__GRAV_API_TOKEN;

function slugify(str) {
    return String(str)
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

class AdminQuickMenuPage extends HTMLElement {
    constructor() {
        super();
        this._shortcuts = [];
        this._customLinks = [];
    }

    connectedCallback() {
        this.dispatchEvent(new CustomEvent('page-state', {
            detail: { title: 'Quick Add Content', icon: 'fa-plus-circle' },
        }));
        this._renderLoading();
        this._load();
    }

    async _load() {
        try {
            const res = await this._fetch('/plugin/admin-quick-menu/shortcuts');
            const data = res.data ?? {};
            this._shortcuts = Array.isArray(data.menu_shortcuts) ? data.menu_shortcuts : [];
            this._customLinks = Array.isArray(data.custom_links) ? data.custom_links : [];
            this._render();
        } catch (err) {
            this._renderError(err?.message || 'Failed to load configuration');
        }
    }

    async _fetch(path, options = {}) {
        const res = await fetch(API_BASE + path, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...(API_TOKEN ? { Authorization: `Bearer ${API_TOKEN}` } : {}),
                ...(options.headers || {}),
            },
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(body?.error?.message || body?.message || `Request failed (${res.status})`);
        }
        return body;
    }

    async _createFromShortcut(shortcut, cardEl) {
        const statusEl = cardEl.querySelector('.aqm-card-status');
        const buttonEl = cardEl.querySelector('.aqm-card-button');
        const label = String(shortcut.label || '').trim();
        const template = String(shortcut.template || '').trim();
        const parentPath = shortcut.parent_path ? '/' + String(shortcut.parent_path).replace(/^\/|\/$/g, '') : '';

        if (!label || !template) {
            return;
        }

        const slug = slugify(label) || 'trang-moi';
        const route = (parentPath === '/' ? '' : parentPath) + '/' + slug;

        buttonEl.disabled = true;
        statusEl.textContent = 'Đang tạo…';
        statusEl.className = 'aqm-card-status aqm-status-pending';

        try {
            const created = await this._fetch('/pages', {
                method: 'POST',
                body: JSON.stringify({ route, title: label, template }),
            });
            statusEl.textContent = `Đã tạo tại ${created.data?.route || route}`;
            statusEl.className = 'aqm-card-status aqm-status-success';
        } catch (err) {
            statusEl.textContent = err.message || 'Tạo trang thất bại';
            statusEl.className = 'aqm-card-status aqm-status-error';
            buttonEl.disabled = false;
        }
    }

    _renderLoading() {
        this.innerHTML = `
            ${this._styles()}
            <div class="aqm-wrapper"><p class="aqm-loading">Đang tải…</p></div>
        `;
    }

    _renderError(message) {
        this.innerHTML = `
            ${this._styles()}
            <div class="aqm-wrapper"><p class="aqm-error">${this._escape(message)}</p></div>
        `;
    }

    _render() {
        const shortcuts = this._shortcuts.filter((s) => s.label && s.template);
        const links = this._customLinks.filter((l) => l.label && l.url);

        this.innerHTML = `
            ${this._styles()}
            <div class="aqm-wrapper">
                <section>
                    <h2 class="aqm-section-title">Thêm mới</h2>
                    ${shortcuts.length ? `
                        <div class="aqm-grid">
                            ${shortcuts.map((s, i) => `
                                <div class="aqm-card" data-index="${i}">
                                    <button type="button" class="aqm-card-button">
                                        <i class="fa fa-fw fa-plus aqm-card-icon"></i>
                                        <span class="aqm-card-label">${this._escape(s.label)}</span>
                                        <span class="aqm-card-meta">${this._escape(s.template)}${s.parent_path ? ' · ' + this._escape(s.parent_path) : ''}</span>
                                    </button>
                                    <div class="aqm-card-status"></div>
                                </div>
                            `).join('')}
                        </div>
                    ` : `<p class="aqm-empty">Chưa có shortcut nào — cấu hình ở phần Settings của plugin.</p>`}
                </section>
                ${links.length ? `
                    <section>
                        <h2 class="aqm-section-title">Liên kết</h2>
                        <ul class="aqm-links">
                            ${links.map((l) => `
                                <li><a href="${this._escape(l.url)}" class="aqm-link">
                                    <i class="fa fa-fw fa-link"></i> ${this._escape(l.label)}
                                </a></li>
                            `).join('')}
                        </ul>
                    </section>
                ` : ''}
            </div>
        `;

        this.querySelectorAll('.aqm-card').forEach((cardEl) => {
            const shortcut = shortcuts[Number(cardEl.dataset.index)];
            cardEl.querySelector('.aqm-card-button').addEventListener('click', () => {
                this._createFromShortcut(shortcut, cardEl);
            });
        });
    }

    _escape(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }

    _styles() {
        return `
            <style>
                .aqm-wrapper { display: flex; flex-direction: column; gap: 24px; font-family: inherit; padding: 4px; }
                .aqm-section-title { font-size: 13px; font-weight: 600; color: var(--muted-foreground, #6b7280); text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 12px; }
                .aqm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
                .aqm-card { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; overflow: hidden; background: var(--card, #fff); }
                .aqm-card-button { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 20px 12px; background: transparent; border: none; cursor: pointer; color: var(--foreground, #1f2937); }
                .aqm-card-button:hover:not(:disabled) { background: var(--accent, #f3f4f6); }
                .aqm-card-button:disabled { cursor: default; opacity: 0.6; }
                .aqm-card-icon { font-size: 20px; color: var(--primary, #3b82f6); }
                .aqm-card-label { font-size: 13px; font-weight: 500; text-align: center; }
                .aqm-card-meta { font-size: 11px; color: var(--muted-foreground, #6b7280); text-align: center; }
                .aqm-card-status { font-size: 11px; text-align: center; padding: 4px 8px 8px; min-height: 14px; }
                .aqm-status-pending { color: var(--muted-foreground, #6b7280); }
                .aqm-status-success { color: var(--success, #16a34a); }
                .aqm-status-error { color: var(--destructive, #dc2626); }
                .aqm-links { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
                .aqm-link { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 6px; color: var(--foreground, #1f2937); text-decoration: none; font-size: 13px; }
                .aqm-link:hover { background: var(--accent, #f3f4f6); }
                .aqm-empty, .aqm-loading, .aqm-error { font-size: 13px; color: var(--muted-foreground, #6b7280); }
                .aqm-error { color: var(--destructive, #dc2626); }
            </style>
        `;
    }
}

customElements.define(TAG, AdminQuickMenuPage);

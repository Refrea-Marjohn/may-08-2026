function toggleNotifications() {
    const panel = document.getElementById('notificationPanel');
    if (!panel) {
        return;
    }
    panel.classList.toggle('active');
}

document.addEventListener('DOMContentLoaded', () => {
    // Keep modal out of .nav-icons flex row — otherwise it can render inline and push the profile (fixed + styles may not escape nested layout).
    const modalHost = document.getElementById('notificationDeleteModal');
    if (modalHost && modalHost.parentNode) {
        document.body.appendChild(modalHost);
    }

    const button = document.querySelector('.notification-button');
    const panel = document.getElementById('notificationPanel');

    if (!button || !panel) {
        return;
    }

    const getBadgeCount = () => {
        const badge = button.querySelector('.notification-badge');
        const raw = badge ? badge.textContent.trim() : '0';
        const count = parseInt(raw, 10);
        return Number.isFinite(count) ? count : 0;
    };

    const setBadgeCount = (count) => {
        const safeCount = Math.max(0, count);
        let badge = button.querySelector('.notification-badge');

        if (safeCount === 0) {
            if (badge) {
                badge.remove();
            }
            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'notification-badge';
            button.appendChild(badge);
        }

        badge.textContent = safeCount;
    };

    const markItemReadUI = (item) => {
        if (!item) {
            return;
        }
        item.classList.add('is-read');
        item.classList.remove('is-unread');
        item.setAttribute('data-is-read', '1');
        const markButton = item.querySelector('.notification-item-mark');
        if (markButton) {
            markButton.remove();
        }
    };

    const markAllReadUI = () => {
        panel.querySelectorAll('.notification-item').forEach(markItemReadUI);
        setBadgeCount(0);
        const actionsRow = panel.querySelector('.notification-actions');
        if (actionsRow) {
            const markAllBtn = actionsRow.querySelector('.notification-mark-btn');
            if (markAllBtn) {
                markAllBtn.style.display = 'none';
            }
        }
    };

    const sendAction = async (payload) => {
        const body = new URLSearchParams({
            ...payload,
            ajax: '1'
        });

        const response = await fetch('notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body
        });

        if (!response.ok) {
            return { success: false };
        }

        try {
            return await response.json();
        } catch (error) {
            return { success: false };
        }
    };

    // Button click to toggle panel
    button.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleNotifications();
    });

    // Click outside to close panel
    document.addEventListener('click', (event) => {
        if (!button.contains(event.target) && !panel.contains(event.target)) {
            panel.classList.remove('active');
        }
    });

    // Prevent panel clicks from closing it
    panel.addEventListener('click', (event) => {
        event.stopPropagation();
    });

    // Mark all as read button
    const markAllButton = panel.querySelector('.notification-mark-btn');
    if (markAllButton) {
        markAllButton.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();

            const result = await sendAction({ action: 'mark_all_read' });
            if (!result.success) {
                return;
            }

            markAllReadUI();
        });
    }

    const showEmptyState = () => {
        const itemCount = panel.querySelectorAll('.notification-item').length;
        const isEmpty = itemCount === 0;
        let emptyEl = panel.querySelector('.notification-empty');
        if (isEmpty && !emptyEl) {
            emptyEl = document.createElement('div');
            emptyEl.className = 'notification-empty';
            emptyEl.textContent = 'No notifications yet.';
            panel.querySelector('.notification-header')?.insertAdjacentElement('afterend', emptyEl);
        } else if (emptyEl && !isEmpty) {
            emptyEl.remove();
        }
    };

    const deleteModal = document.getElementById('notificationDeleteModal');
    let pendingDelete = null;
    let lastFocusBeforeModal = null;

    const openDeleteModal = (payload) => {
        pendingDelete = payload;
        lastFocusBeforeModal = document.activeElement;
        if (deleteModal) {
            deleteModal.classList.add('is-open');
            deleteModal.setAttribute('aria-hidden', 'false');
            const confirmBtn = deleteModal.querySelector('[data-notification-modal-confirm]');
            if (confirmBtn) {
                confirmBtn.focus();
            }
        }
    };

    const closeDeleteModal = () => {
        pendingDelete = null;
        if (deleteModal) {
            deleteModal.classList.remove('is-open');
            deleteModal.setAttribute('aria-hidden', 'true');
        }
        if (lastFocusBeforeModal && typeof lastFocusBeforeModal.focus === 'function') {
            lastFocusBeforeModal.focus();
        }
        lastFocusBeforeModal = null;
    };

    if (deleteModal) {
        deleteModal.querySelectorAll('[data-notification-modal-dismiss]').forEach((el) => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                closeDeleteModal();
            });
        });

        deleteModal.querySelector('[data-notification-modal-confirm]')?.addEventListener('click', async (e) => {
            e.preventDefault();
            if (!pendingDelete) {
                return;
            }
            const { item, notificationId, wasUnread } = pendingDelete;
            closeDeleteModal();

            const result = await sendAction({
                action: 'delete_notification',
                notification_id: notificationId
            });

            if (!result.success) {
                return;
            }

            item.remove();
            if (wasUnread) {
                const currentCount = getBadgeCount();
                setBadgeCount(currentCount - 1);
            }
            showEmptyState();

            const actionsRow = panel.querySelector('.notification-actions');
            if (actionsRow && getBadgeCount() === 0) {
                const markAllBtn = actionsRow.querySelector('.notification-mark-btn');
                if (markAllBtn) {
                    markAllBtn.style.display = 'none';
                }
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && deleteModal.classList.contains('is-open')) {
                closeDeleteModal();
            }
        });
    }

    // Per-notification delete — themed modal (not browser confirm)
    panel.addEventListener('click', (event) => {
        const deleteButton = event.target.closest('.notification-item-delete');
        if (!deleteButton) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const item = deleteButton.closest('.notification-item');
        const notificationId = item ? item.dataset.notificationId : '';
        const wasUnread = item && item.getAttribute('data-is-read') === '0';
        if (!notificationId || !item) {
            return;
        }

        openDeleteModal({ item, notificationId, wasUnread });
    });

    // Individual notification mark as read
    panel.addEventListener('click', async (event) => {
        const markButton = event.target.closest('.notification-item-mark');
        if (!markButton) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const item = markButton.closest('.notification-item');
        const notificationId = item ? item.dataset.notificationId : '';
        if (!notificationId) {
            return;
        }

        const result = await sendAction({
            action: 'mark_one_read',
            notification_id: notificationId
        });

        if (!result.success) {
            return;
        }

        markItemReadUI(item);
        const currentCount = getBadgeCount();
        setBadgeCount(currentCount - 1);

        if (getBadgeCount() === 0) {
            const markAllBtn = panel.querySelector('.notification-mark-btn');
            if (markAllBtn) {
                markAllBtn.style.display = 'none';
            }
        }
    });
});

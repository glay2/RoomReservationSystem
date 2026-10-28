// assets/js/main.js - Interactive behaviors, live conflict checking, and UI animations

document.addEventListener('DOMContentLoaded', () => {
    initModals();
    initNotifications();
    initLiveConflictCheck();
    initSidebar();
});

// Modal Dialog Management
function initModals() {
    window.openModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    };

    window.closeModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('open');
            document.body.style.overflow = '';
        }
    };

    // Close on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.classList.remove('open');
                document.body.style.overflow = '';
            }
        });
    });

    // Close on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(modal => {
                modal.classList.remove('open');
            });
            document.body.style.overflow = '';
        }
    });
}

// Sidebar toggle for mobile
function initSidebar() {
    const toggleBtn = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.app-sidebar');
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
    }
}

// Notifications toggle & polling
function initNotifications() {
    const notifBtn = document.getElementById('notifToggleBtn');
    const notifDropdown = document.getElementById('notifDropdown');

    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');
        });

        document.addEventListener('click', (e) => {
            if (!notifDropdown.contains(e.target) && e.target !== notifBtn) {
                notifDropdown.classList.remove('show');
            }
        });
    }

    const markAllReadBtn = document.getElementById('markAllReadBtn');
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', () => {
            fetch('api/notifications.php?action=mark_all_read', { credentials: 'same-origin' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const badge = document.getElementById('notifBadge');
                        if (badge) badge.style.display = 'none';
                        document.querySelectorAll('.notif-item.unread').forEach(item => {
                            item.classList.remove('unread');
                        });
                    }
                })
                .catch(err => console.error("Notification error:", err));
        });
    }
}

// Live Conflict Checking during Reservation Creation
function initLiveConflictCheck() {
    const roomSelect = document.getElementById('reserve_room_id');
    const dateInput = document.getElementById('reserve_date');
    const startInput = document.getElementById('reserve_start');
    const endInput = document.getElementById('reserve_end');
    const attendanceInput = document.getElementById('reserve_attendance');
    const feedbackBox = document.getElementById('conflict_feedback');
    const submitBtn = document.getElementById('submitReservationBtn');

    if (!roomSelect || !dateInput || !startInput || !endInput || !feedbackBox) {
        return;
    }

    let checkTimeout = null;

    function triggerCheck() {
        clearTimeout(checkTimeout);
        checkTimeout = setTimeout(() => {
            performConflictCheck();
        }, 300);
    }

    roomSelect.addEventListener('change', triggerCheck);
    dateInput.addEventListener('change', triggerCheck);
    startInput.addEventListener('change', triggerCheck);
    endInput.addEventListener('change', triggerCheck);
    if (attendanceInput) attendanceInput.addEventListener('input', triggerCheck);

    function performConflictCheck() {
        const roomId = roomSelect.value;
        const dateVal = dateInput.value;
        const startVal = startInput.value;
        const endVal = endInput.value;
        const attendanceVal = attendanceInput ? attendanceInput.value : '';

        if (!roomId || !dateVal || !startVal || !endVal) {
            feedbackBox.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
            return;
        }

        // Validate start < end
        if (startVal >= endVal) {
            feedbackBox.style.display = 'block';
            feedbackBox.innerHTML = `
                <div class="conflict-callout">
                    <div class="conflict-icon">⚠️</div>
                    <div class="conflict-content">
                        <h4>Invalid Time Window</h4>
                        <p>Reservation End Time must be later than Start Time.</p>
                    </div>
                </div>
            `;
            if (submitBtn) submitBtn.disabled = true;
            return;
        }

        const params = new URLSearchParams({
            room_id: roomId,
            date: dateVal,
            start_time: startVal,
            end_time: endVal,
            attendance: attendanceVal
        });

        fetch(`api/check_availability.php?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                feedbackBox.style.display = 'block';
                if (!data.available) {
                    let message = data.message || 'Room is not available for this time window.';
                    feedbackBox.innerHTML = `
                        <div class="conflict-callout" style="animation: slideDown 0.25s ease;">
                            <div class="conflict-icon">🚫</div>
                            <div class="conflict-content">
                                <h4>Scheduling Conflict Detected!</h4>
                                <p>${escapeHtml(message)}</p>
                                ${data.conflict ? `<div style="margin-top:6px; font-size:0.78rem; color:#fca5a5;">Overlap: ${escapeHtml(data.conflict.purpose)} (${data.conflict.start_time.substring(0,5)} - ${data.conflict.end_time.substring(0,5)})</div>` : ''}
                            </div>
                        </div>
                    `;
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    let warningNote = '';
                    if (data.capacity_warning) {
                        warningNote = `<div style="margin-top:4px; color:#fcd34d; font-size:0.78rem;">⚠️ Notice: Requested attendance (${attendanceVal}) exceeds room capacity (${data.room_capacity}).</div>`;
                    }
                    feedbackBox.innerHTML = `
                        <div style="background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.35); border-radius: var(--radius-md); padding: 12px 16px; margin-bottom: 20px; display:flex; align-items:center; gap:12px;">
                            <span style="font-size:1.2rem;">✨</span>
                            <div style="font-size:0.85rem; color:#6ee7b7; font-weight:600;">
                                Room is available for this slot! No overlapping approved reservations.
                                ${warningNote}
                            </div>
                        </div>
                    `;
                    if (submitBtn) submitBtn.disabled = false;
                }
            })
            .catch(err => {
                console.error("Conflict checking error:", err);
            });
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, '&amp;')
               .replace(/</g, '&lt;')
               .replace(/>/g, '&gt;')
               .replace(/"/g, '&quot;')
               .replace(/'/g, '&#039;');
}

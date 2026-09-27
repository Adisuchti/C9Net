<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    redirectToLogin();
}

$userId  = $_SESSION['user_id'];
$isAdmin = ($userId === -1);

// Check if the current user is a calendar editor
$canEdit = $isAdmin;
if (!$canEdit) {
    $editorStmt = $pdo->prepare("SELECT 1 FROM calendar_editors WHERE User_Id = ?");
    $editorStmt->execute([$userId]);
    $canEdit = (bool)$editorStmt->fetch();
}

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="calendar-preview-container preview-container">
    <div class="calendar-layout">
        
        <!-- Left Pane: Calendar Grid (70%) -->
        <div class="calendar-left-pane">
            <div class="calendar-header">
                <div class="calendar-header-actions">
                    <button class="btn-industrial" onclick="changeMonth(-1)" title="Previous month">&laquo;</button>
                    <h3 id="calendarMonthYear" class="calendar-month-year"></h3>
                    <button class="btn-industrial" onclick="changeMonth(1)" title="Next month">&raquo;</button>
                    <button class="btn-industrial" onclick="goToToday()">Today</button>
                </div>
                <?php if ($canEdit): ?>
                    <button class="btn-industrial btn-calendar-add" onclick="openEventModal()">+ New Event</button>
                <?php endif; ?>
            </div>

            <!-- Weekday labels -->
            <div class="calendar-weekdays">
                <div>Mon</div>
                <div>Tue</div>
                <div>Wed</div>
                <div>Thu</div>
                <div>Fri</div>
                <div>Sat</div>
                <div>Sun</div>
            </div>

            <!-- Calendar Grid -->
            <div class="calendar-grid" id="calendarGrid"></div>
        </div>

        <!-- Right Pane: Event Details (30%) -->
        <div class="calendar-right-pane">
            
            <!-- Selected Event (Top 70%) -->
            <div class="calendar-selected-event" id="selectedEventPanel">
                <h3 class="calendar-section-title">Selected Event</h3>
                
                <div id="noEventSelected" class="calendar-no-event">
                    Select an event to view details
                </div>

                <div id="eventDetailContent" class="calendar-event-detail calendar-event-detail-content">
                    <img id="detailImage" src="" alt="Event Image" class="calendar-detail-img calendar-detail-img-extra">
                    <h2 id="detailTitle" class="calendar-detail-title"></h2>
                    <p id="detailDate" class="calendar-detail-date"></p>
                    <p id="detailTime" class="calendar-detail-time"></p>
                    <p id="detailCreator" class="calendar-detail-creator"></p>
                    <div id="detailDescription" class="calendar-detail-desc"></div>
                    
                    <?php if ($canEdit): ?>
                        <div class="calendar-detail-actions">
                            <button class="btn-industrial calendar-detail-btn-edit" onclick="editCurrentEvent()">Edit</button>
                            <button class="btn-industrial btn-calendar-delete" onclick="deleteCurrentEvent()">Delete</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Next Event (Bottom 30%) -->
            <div class="calendar-next-event">
                <h3 class="calendar-section-title">Next Event</h3>
                <div id="nextEventContent" class="calendar-next-event-content">
                    <!-- populated by JS -->
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Event Create/Edit Modal -->
<?php if ($canEdit): ?>
<div class="overlay-backdrop" id="eventFormOverlay">
    <div class="overlay-content calendar-modal-content">
        <h3 id="formTitle" class="calendar-modal-title">New Event</h3>
        <form id="eventForm" class="calendar-form">
            <input type="hidden" id="formEventId" value="">
            <div class="calendar-form-group">
                <label for="formEventTitle" class="calendar-form-label">Title *</label>
                <input type="text" id="formEventTitle" required maxlength="255" placeholder="Event title" class="calendar-form-input">
            </div>
            
            <div class="calendar-form-group">
                <label for="formEventImage" class="calendar-form-label">Event Image</label>
                <input type="file" id="formEventImage" accept="image/*" class="calendar-form-input">
            </div>

            <div class="calendar-form-group">
                <label for="formEventDesc" class="calendar-form-label">Description</label>
                <textarea id="formEventDesc" rows="4" placeholder="Optional description" class="calendar-form-textarea"></textarea>
            </div>

            <div class="calendar-form-row">
                <div class="calendar-form-group">
                    <label for="formEventDate" class="calendar-form-label">Start Date *</label>
                    <input type="date" id="formEventDate" required class="calendar-form-input">
                </div>
                <div class="calendar-form-group">
                    <label for="formEventTime" class="calendar-form-label">Start Time (British)</label>
                    <input type="time" id="formEventTime" class="calendar-form-input">
                    <small id="formTimeLocalHint" class="calendar-form-hint"></small>
                </div>
            </div>

            <div class="calendar-form-row">
                <div class="calendar-form-group">
                    <label for="formEventEndDate" class="calendar-form-label">End Date</label>
                    <input type="date" id="formEventEndDate" class="calendar-form-input">
                </div>
                <div class="calendar-form-group">
                    <label for="formEventEndTime" class="calendar-form-label">End Time (British)</label>
                    <input type="time" id="formEventEndTime" class="calendar-form-input">
                    <small id="formEndTimeLocalHint" class="calendar-form-hint"></small>
                </div>
            </div>

            <div class="calendar-form-group">
                <label for="formEventColor" class="calendar-form-label">Color</label>
                <div class="calendar-color-picker">
                    <input type="color" id="formEventColor" value="#c89b3c" class="calendar-color-input">
                    <div class="calendar-color-swatches">
                        <span class="calendar-color-swatch calendar-swatch-gold" onclick="setColor('#c89b3c')" title="Gold"></span>
                        <span class="calendar-color-swatch calendar-swatch-red" onclick="setColor('#dc3545')" title="Red"></span>
                        <span class="calendar-color-swatch calendar-swatch-green" onclick="setColor('#28a745')" title="Green"></span>
                        <span class="calendar-color-swatch calendar-swatch-blue" onclick="setColor('#3498db')" title="Blue"></span>
                        <span class="calendar-color-swatch calendar-swatch-purple" onclick="setColor('#6f42c1')" title="Purple"></span>
                    </div>
                </div>
            </div>

            <div class="overlay-actions calendar-modal-actions">
                <button type="submit" class="btn-industrial btn-calendar-save">Save</button>
                <button type="button" class="btn-industrial btn-calendar-cancel" onclick="closeFormOverlay()">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    const canEdit = <?php echo $canEdit ? 'true' : 'false'; ?>;
    let currentYear  = new Date().getFullYear();
    let currentMonth = new Date().getMonth(); // 0-indexed
    let eventsCache  = [];
    let currentDetailEvent = null;

    // ===== Calendar Rendering =====

    function renderCalendar() {
        const grid = document.getElementById('calendarGrid');
        const label = document.getElementById('calendarMonthYear');

        const monthNames = ['January','February','March','April','May','June',
                            'July','August','September','October','November','December'];
        label.textContent = monthNames[currentMonth] + ' ' + currentYear;

        const firstDay = new Date(currentYear, currentMonth, 1);
        const lastDay  = new Date(currentYear, currentMonth + 1, 0);
        let startDow = (firstDay.getDay() + 6) % 7;
        const totalDays = lastDay.getDate();

        const today = new Date();
        const todayStr = today.getFullYear() + '-' +
                            String(today.getMonth() + 1).padStart(2, '0') + '-' +
                            String(today.getDate()).padStart(2, '0');

        let html = '';

        function getDayHtml(y, m, d, isCurrentMonth) {
            const dateStr = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            const isToday = dateStr === todayStr;
            const dayEvents = getEventsForDate(dateStr);

            let dayClass = 'cal-day';
            let textClass = 'cal-day-number';
            if (isCurrentMonth) {
                dayClass += isToday ? ' cal-day-current-today' : ' cal-day-current';
                textClass += isToday ? ' cal-day-text-today' : ' cal-day-text-current';
            } else {
                dayClass += ' cal-day-outside';
                textClass += ' cal-day-text-outside';
            }

            let cellStyle = '';
            if (dayEvents.length === 1 && dayEvents[0].Color) {
                // Apply a semi-transparent background to the square
                cellStyle = ` style="background-color: ${dayEvents[0].Color}33; border-color: ${dayEvents[0].Color}80;"`;
            }
            
            let cellHtml = `<div class="${dayClass}" data-date="${dateStr}"${cellStyle}`;
            if (canEdit) {
                cellHtml += ` ondblclick="openEventModal('${dateStr}')"`;
            }
            cellHtml += ` onclick="handleDayClick(event, '${dateStr}')">`;
            cellHtml += `<span class="${textClass}">${d}</span>`;

            if (dayEvents.length > 0) {
                const listClass = isCurrentMonth ? 'cal-event-list' : 'cal-event-list outside';
                cellHtml += `<div class="${listClass}">`;
                for (const ev of dayEvents) {
                    let timeStr = '';
                    if (ev.Event_Time) {
                        timeStr = ev.Event_Time.substring(0, 5) + ' ';
                    }
                    let chipStyle = ev.Color ? ` style="background-color: ${ev.Color}; border-left: 3px solid rgba(255,255,255,0.3);"` : '';
                    cellHtml += `<div class="cal-event-chip calendar-event-chip-extra" onclick="showEventDetail(${ev.Event_Id}, event)" title="${escapeHtml(ev.Title)}"${chipStyle}>`;
                    cellHtml += `<strong>${timeStr}</strong>${escapeHtml(ev.Title)}`;
                    cellHtml += `</div>`;
                }
                cellHtml += `</div>`;
            }
            cellHtml += `</div>`;
            return cellHtml;
        }

        // Previous month padding
        const prevLast = new Date(currentYear, currentMonth, 0).getDate();
        const prevMonth = currentMonth === 0 ? 11 : currentMonth - 1;
        const prevYear = currentMonth === 0 ? currentYear - 1 : currentYear;
        for (let i = startDow - 1; i >= 0; i--) {
            html += getDayHtml(prevYear, prevMonth, prevLast - i, false);
        }

        // Current month days
        for (let d = 1; d <= totalDays; d++) {
            html += getDayHtml(currentYear, currentMonth, d, true);
        }

        // Next month padding
        const totalCells = startDow + totalDays;
        const remaining = (7 - (totalCells % 7)) % 7;
        const nextMonth = currentMonth === 11 ? 0 : currentMonth + 1;
        const nextYear = currentMonth === 11 ? currentYear + 1 : currentYear;
        for (let i = 1; i <= remaining; i++) {
            html += getDayHtml(nextYear, nextMonth, i, false);
        }

        grid.innerHTML = html;
        
        // After rendering, calculate the next event based on today
        updateNextEvent();
    }

    function handleDayClick(e, dateStr) {
        if (e.target.closest('.cal-event-chip')) return;
        // e.g. to create event on single click if we wanted:
        // if (canEdit) openEventModal(dateStr);
    }

    function getEventsForDate(dateStr) {
        return eventsCache.filter(ev => {
            const start = ev.Event_Date;
            const end   = ev.Event_End_Date || ev.Event_Date;
            return dateStr >= start && dateStr <= end;
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ===== Timezone Utilities (Europe/London) =====
    function britishToUTCDate(dateStr, timeStr) {
        if (!dateStr || !timeStr) return null;
        const [Y, M, D] = dateStr.split('-').map(Number);
        const [h, m]    = timeStr.split(':').map(Number);
        let guess = new Date(Date.UTC(Y, M - 1, D, h, m));
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/London', hour: '2-digit', minute: '2-digit', hour12: false
        }).formatToParts(guess);
        const lh = parseInt(parts.find(p => p.type === 'hour').value);
        const lm = parseInt(parts.find(p => p.type === 'minute').value);
        const diffMins = (h * 60 + m) - (lh * 60 + lm);
        return new Date(guess.getTime() - diffMins * 60000);
    }

    function getBritishTZAbbr(utcDate) {
        return new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/London', timeZoneName: 'short'
        }).formatToParts(utcDate).find(p => p.type === 'timeZoneName').value;
    }

    function getLocalTZAbbr(utcDate) {
        return new Intl.DateTimeFormat('en', { timeZoneName: 'short' }).formatToParts(utcDate).find(p => p.type === 'timeZoneName').value;
    }

    function formatLocalHM(utcDate) {
        return utcDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
    }

    function formatTimeRangeWithLocal(startDateStr, startTimeStr, endDateStr, endTimeStr) {
        if (!startTimeStr) return '';
        const startUTC  = britishToUTCDate(startDateStr, startTimeStr);
        if (!startUTC) return startTimeStr.substring(0, 5);
        const britishAbbr = getBritishTZAbbr(startUTC);
        const localAbbr   = getLocalTZAbbr(startUTC);
        const showLocal   = britishAbbr !== localAbbr;

        let british = startTimeStr.substring(0, 5);
        let local   = showLocal ? formatLocalHM(startUTC) : '';

        if (endTimeStr) {
            const endUTC = britishToUTCDate(endDateStr || startDateStr, endTimeStr);
            british += ' \u2013 ' + endTimeStr.substring(0, 5);
            if (showLocal && endUTC) local += ' \u2013 ' + formatLocalHM(endUTC);
        }

        british += '\u00a0' + britishAbbr;
        return showLocal ? british + '\u00a0(' + local + '\u00a0' + localAbbr + ')' : british;
    }

    // ===== Data Loading =====

    async function loadEvents() {
        try {
            const resp = await fetch(`../db/misc/calendarEvents.php?year=${currentYear}&month=${currentMonth + 1}`);
            const data = await resp.json();
            if (data.success) {
                eventsCache = data.events;
                window.nextOverallEvent = data.next_event || null;
                if (window.nextOverallEvent && !eventsCache.find(x => x.Event_Id == window.nextOverallEvent.Event_Id)) {
                    eventsCache.push(window.nextOverallEvent);
                }
            } else {
                if (typeof showError === "function") showError(data.error || 'Failed to load events');
                eventsCache = [];
                window.nextOverallEvent = null;
            }
        } catch (e) {
            if (typeof showError === "function") showError('Failed to load calendar events');
            eventsCache = [];
            window.nextOverallEvent = null;
        }
        renderCalendar();
    }

    // ===== Navigation =====

    function changeMonth(delta) {
        currentMonth += delta;
        if (currentMonth < 0) { currentMonth = 11; currentYear--; }
        if (currentMonth > 11) { currentMonth = 0; currentYear++; }
        loadEvents();
    }

    function goToToday() {
        const now = new Date();
        currentYear  = now.getFullYear();
        currentMonth = now.getMonth();
        loadEvents();
    }

    // ===== Event Detail Panel =====

    function showEventDetail(eventId, e) {
        if (e) e.stopPropagation();
        
        const ev = eventsCache.find(ev => ev.Event_Id == eventId);
        if (!ev) return;
        currentDetailEvent = ev;

        document.getElementById('noEventSelected').style.display = 'none';
        const detailContent = document.getElementById('eventDetailContent');
        detailContent.style.display = 'flex';

        document.getElementById('detailTitle').textContent = ev.Title;
        document.getElementById('detailTitle').style.color = ev.Color || '#3498db';

        // Image
        const imgEl = document.getElementById('detailImage');
        if (ev.Image_Url) {
            imgEl.src = ev.Image_Url;
            imgEl.style.display = 'block';
        } else {
            imgEl.style.display = 'none';
        }

        // Format dates
        let dateText = formatDate(ev.Event_Date);
        if (ev.Event_End_Date && ev.Event_End_Date !== ev.Event_Date) {
            dateText += ' \u2014 ' + formatDate(ev.Event_End_Date);
        }
        document.getElementById('detailDate').textContent = dateText;

        // Format time (British + local equivalent)
        const timeText = formatTimeRangeWithLocal(
            ev.Event_Date, ev.Event_Time,
            ev.Event_End_Date || ev.Event_Date, ev.Event_End_Time
        );
        document.getElementById('detailTime').textContent = timeText;
        document.getElementById('detailTime').style.display = timeText ? 'block' : 'none';

        document.getElementById('detailCreator').textContent = ev.creator_name
            ? 'Created by ' + ev.creator_name
            : '';

        const descEl = document.getElementById('detailDescription');
        descEl.textContent = ev.Description || '';
        descEl.style.display = ev.Description ? 'block' : 'none';
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }

    // ===== Next Event =====
    async function updateNextEvent() {
        const nextEventContent = document.getElementById('nextEventContent');
        
        try {
            const nextEv = window.nextOverallEvent;
            
            if (nextEv) {
                let timeText = formatTimeRangeWithLocal(
                    nextEv.Event_Date, nextEv.Event_Time,
                    nextEv.Event_End_Date || nextEv.Event_Date, nextEv.Event_End_Time
                );

                let imageHtml = '';
                if (nextEv.Image_Url) {
                    imageHtml = `<img src="${escapeHtml(nextEv.Image_Url)}" alt="" class="next-event-image">`;
                }

                nextEventContent.innerHTML = `
                    <div class="next-event-card calendar-event-detail-content0" onclick="showEventDetail(${nextEv.Event_Id})">
                        ${imageHtml}
                        <div class="next-event-info">
                            <h4 class="next-event-title calendar-event-detail-content1">${escapeHtml(nextEv.Title)}</h4>
                            <p class="next-event-date">${formatDate(nextEv.Event_Date)}</p>
                            <p class="next-event-time">${timeText}</p>
                        </div>
                    </div>
                `;
            } else {
                nextEventContent.innerHTML = `<div class="next-event-empty">No upcoming events scheduled.</div>`;
            }
            
        } catch (err) {
            console.error(err);
        }
    }

    // ===== Event Form (Create / Edit) =====

    function openEventModal(prefillDate) {
        if (!canEdit) return;
        document.getElementById('formTitle').textContent = 'New Event';
        document.getElementById('formEventId').value = '';
        document.getElementById('formEventTitle').value = '';
        document.getElementById('formEventDesc').value = '';
        document.getElementById('formEventImage').value = ''; 
        document.getElementById('formEventDate').value = prefillDate || '';
        document.getElementById('formEventTime').value = '';
        document.getElementById('formEventEndDate').value = '';
        document.getElementById('formEventEndTime').value = '';
        document.getElementById('formEventColor').value = '#c89b3c';
        document.getElementById('eventFormOverlay').classList.add('active');
        updateTimeHints();
    }

    function editCurrentEvent() {
        if (!canEdit || !currentDetailEvent) return;
        const ev = currentDetailEvent;

        document.getElementById('formTitle').textContent = 'Edit Event';
        document.getElementById('formEventId').value = ev.Event_Id;
        document.getElementById('formEventTitle').value = ev.Title;
        document.getElementById('formEventDesc').value = ev.Description || '';
        document.getElementById('formEventImage').value = ''; 
        document.getElementById('formEventDate').value = ev.Event_Date;
        document.getElementById('formEventTime').value = ev.Event_Time ? ev.Event_Time.substring(0, 5) : '';
        document.getElementById('formEventEndDate').value = ev.Event_End_Date || '';
        document.getElementById('formEventEndTime').value = ev.Event_End_Time ? ev.Event_End_Time.substring(0, 5) : '';
        document.getElementById('formEventColor').value = ev.Color || '#c89b3c';
        
        document.getElementById('eventFormOverlay').classList.add('active');
        updateTimeHints();
    }

    function closeFormOverlay() {
        document.getElementById('eventFormOverlay').classList.remove('active');
    }

    function setColor(hex) {
        document.getElementById('formEventColor').value = hex;
        // visual update of swatches
        const swatches = document.querySelectorAll('span[onclick^="setColor"]');
        swatches.forEach(s => s.style.border = '1px solid transparent');
        event.target.style.border = '1px solid #fff';
    }

    function updateTimeHints() {
        const dateStr    = document.getElementById('formEventDate')?.value;
        const startTime  = document.getElementById('formEventTime')?.value;
        const endDateStr = document.getElementById('formEventEndDate')?.value || dateStr;
        const endTime    = document.getElementById('formEventEndTime')?.value;
        const startHint  = document.getElementById('formTimeLocalHint');
        const endHint    = document.getElementById('formEndTimeLocalHint');

        if (startHint) {
            if (dateStr && startTime) {
                const utcDate = britishToUTCDate(dateStr, startTime);
                if (utcDate) {
                    const localAbbr   = getLocalTZAbbr(utcDate);
                    const britishAbbr = getBritishTZAbbr(utcDate);
                    startHint.textContent = localAbbr !== britishAbbr
                        ? '= ' + formatLocalHM(utcDate) + ' ' + localAbbr
                        : '';
                }
            } else {
                startHint.textContent = '';
            }
        }

        if (endHint) {
            if (endDateStr && endTime) {
                const utcDate = britishToUTCDate(endDateStr, endTime);
                if (utcDate) {
                    const localAbbr   = getLocalTZAbbr(utcDate);
                    const britishAbbr = getBritishTZAbbr(utcDate);
                    endHint.textContent = localAbbr !== britishAbbr
                        ? '= ' + formatLocalHM(utcDate) + ' ' + localAbbr
                        : '';
                }
            } else {
                endHint.textContent = '';
            }
        }
    }

    ['formEventDate', 'formEventTime', 'formEventEndDate', 'formEventEndTime'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', updateTimeHints);
        document.getElementById(id)?.addEventListener('change', updateTimeHints);
    });

    // Form submission
    document.getElementById('eventForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const eventId = document.getElementById('formEventId').value;
        
        const formData = new FormData();
        formData.append('action', eventId ? 'update' : 'create');
        if (eventId) formData.append('event_id', eventId);
        
        formData.append('title', document.getElementById('formEventTitle').value.trim());
        formData.append('description', document.getElementById('formEventDesc').value.trim());
        formData.append('event_date', document.getElementById('formEventDate').value);
        formData.append('event_time', document.getElementById('formEventTime').value);
        formData.append('event_end_date', document.getElementById('formEventEndDate').value);
        formData.append('event_end_time', document.getElementById('formEventEndTime').value);
        formData.append('color', document.getElementById('formEventColor').value);
        
        const imageFile = document.getElementById('formEventImage').files[0];
        if (imageFile) {
            formData.append('image', imageFile);
        }

        try {
            const resp = await fetch('../db/misc/calendarEvents.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            if (data.success) {
                closeFormOverlay();
                if (typeof showToast === "function") showToast(eventId ? 'Event updated' : 'Event created', 'success');
                
                if (eventId && currentDetailEvent) {
                    await loadEvents();
                    showEventDetail(eventId); 
                } else {
                    loadEvents();
                }
            } else {
                if (typeof showError === "function") showError(data.error || 'Failed to save event');
            }
        } catch (err) {
            if (typeof showError === "function") showError('Failed to save event: ' + err.message);
        }
    });

    // ===== Delete =====

    async function deleteCurrentEvent() {
        if (!canEdit || !currentDetailEvent) return;
        if (!confirm('Delete this event?')) return;

        try {
            const resp = await fetch('../db/misc/calendarEvents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', event_id: currentDetailEvent.Event_Id })
            });
            const data = await resp.json();
            if (data.success) {
                if (typeof showToast === "function") showToast('Event deleted', 'success');
                document.getElementById('eventDetailContent').style.display = 'none';
                document.getElementById('noEventSelected').style.display = 'block';
                currentDetailEvent = null;
                loadEvents();
            } else {
                if (typeof showError === "function") showError(data.error || 'Failed to delete event');
            }
        } catch (err) {
            if (typeof showError === "function") showError('Failed to delete event: ' + err.message);
        }
    }

    // ===== Init =====
    loadEvents();
</script>

<?php include '../includes/footer.php'; ?>


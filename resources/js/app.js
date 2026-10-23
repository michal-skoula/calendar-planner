import './bootstrap';

import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin, { Draggable } from '@fullcalendar/interaction';

const pad = (n) => String(n).padStart(2, '0');

/**
 * Format a Date as floating wall clock, with no zone or offset.
 *
 * The server sends and expects wall clock in the configured display timezone and
 * FullCalendar runs in its default `local` zone, so the two agree without either
 * side doing arithmetic. See App\Livewire\Planner.
 */
const wall = (date) =>
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;

const withinElement = (element, x, y) => {
    if (!element) return false;
    const box = element.getBoundingClientRect();
    return x >= box.left && x <= box.right && y >= box.top && y <= box.bottom;
};

export function bootPlanner(root, wire, options = {}) {
    const host = root.querySelector('[data-calendar]');
    const inbox = root.querySelector('[data-inbox]');

    const calendar = new Calendar(host, {
        plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
        initialView: 'timeGridWeek',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'timeGridWeek,timeGridDay',
        },
        locale: options.locale ?? 'en',
        firstDay: 1,
        height: '100%',
        expandRows: true,
        nowIndicator: true,
        scrollTime: '07:00:00',
        slotDuration: '00:30:00',
        snapDuration: '00:15:00',
        allDaySlot: true,
        editable: true,
        droppable: true,
        eventDurationEditable: true,
        dragRevertDuration: 0,
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },

        events: (info, success, failure) => {
            wire.events(wall(info.start), wall(info.end)).then(success).catch(failure);
        },

        // A move or a resize is the same write: a new span.
        eventDrop: (info) => persistSpan(info),
        eventResize: (info) => persistSpan(info),

        // An inbox card dropped onto the grid. The event FullCalendar would create
        // optimistically is reverted in eventReceive; the authoritative one arrives
        // with the refetch, which keeps one source of truth for what is scheduled.
        drop: (info) => {
            const id = Number(info.draggedEl.dataset.taskId);
            if (!id) return;

            const minutes = Number(info.draggedEl.dataset.duration) || options.defaultDuration || null;

            wire.scheduleFromInbox(id, wall(info.date), minutes).then(() => calendar.refetchEvents());
        },

        eventReceive: (info) => info.revert(),

        // Dragging an event back onto the inbox unschedules it.
        eventDragStop: (info) => {
            if (!withinElement(inbox, info.jsEvent.clientX, info.jsEvent.clientY)) return;

            const id = Number(info.event.id);
            if (!id) return;

            info.event.remove();
            wire.unscheduleEvent(id);
        },

        eventClick: (info) => {
            const id = Number(info.event.id);
            if (!id) return;

            wire.toggleComplete(id);
        },
    });

    calendar.render();

    if (inbox) {
        new Draggable(inbox, {
            itemSelector: '[data-task-id]',
            eventData: (element) => ({
                title: element.dataset.title,
                duration: { minutes: Number(element.dataset.duration) || options.defaultDuration || 30 },
            }),
        });
    }

    return calendar;
}

window.bootPlanner = bootPlanner;

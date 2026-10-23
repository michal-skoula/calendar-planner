<?php

return [

    /*
    | Apple Calendar signs in with the planner account itself — the same email and
    | password as the web UI — so there is no CalDAV credential to configure here.
    | The account's email is also the DAV principal: principals/{email}.
    |
    | This is only the Basic-auth realm the browser shows in its password prompt.
    */
    'realm' => env('CALDAV_REALM', 'Tasks'),

    /*
    | The one calendar. `uri` is the path segment Apple sees and must not change
    | after an account has been added on a device.
    */
    'calendar_uri' => env('CALDAV_CALENDAR_URI', 'tasks'),
    'calendar_name' => env('CALDAV_CALENDAR_NAME', 'Tasks'),
    'calendar_color' => env('CALDAV_CALENDAR_COLOR', '#0d6b68ff'),

    /*
    | iCalendar has no way to mark a VEVENT done, so a completed task carries this
    | prefix in its SUMMARY. Changing it only affects events written from now on.
    */
    'done_prefix' => env('CALDAV_DONE_PREFIX', '✅ '),

    /*
    | Everything is stored in UTC. This is the wall clock the browser renders in.
    */
    'display_timezone' => env('PLANNER_TIMEZONE', 'Europe/Prague'),

    /*
    | Block length used when an inbox task without its own estimate is dropped
    | onto the calendar.
    */
    'default_duration_min' => (int) env('PLANNER_DEFAULT_DURATION', 30),

];

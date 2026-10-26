/**
 * Date helpers for the edge of the application.
 *
 * Track phase boundaries are days, not instants: an `*_open_at` boundary means
 * the start of its day and an `*_close_at` boundary the end of it, which is
 * exactly how `Track::phaseOpensAt()` and `Track::phaseClosesAt()` read them on
 * the server. Any window check in the browser has to use the same two instants,
 * or a page offers a button the server then refuses.
 *
 * The days are UTC days: boundaries are stored and compared in UTC, so using the
 * browser's own timezone would show a visitor west of UTC the day before the one
 * the server is enforcing.
 */

/**
 * The UTC year/month/day of a serialised date, or null when there is none.
 */
function utcDay(
    value?: string | null,
): { year: number; month: number; day: number } | null {
    if (!value) return null;

    const date = new Date(value);
    if (isNaN(date.getTime())) return null;

    return {
        year: date.getUTCFullYear(),
        month: date.getUTCMonth(),
        day: date.getUTCDate(),
    };
}

/**
 * The instant an `*_open_at` boundary opens: midnight of its day.
 */
export function phaseOpensAt(value?: string | null): Date | null {
    const day = utcDay(value);

    return day ? new Date(Date.UTC(day.year, day.month, day.day)) : null;
}

/**
 * The instant a `*_close_at` boundary closes: the last millisecond of its day,
 * so a track closing today is still open tonight.
 */
export function phaseClosesAt(value?: string | null): Date | null {
    const day = utcDay(value);

    return day
        ? new Date(Date.UTC(day.year, day.month, day.day, 23, 59, 59, 999))
        : null;
}

/**
 * Format a date, e.g. "Jun 1, 2026". Unparseable input is returned as-is rather
 * than swallowed, so a bad value is visible instead of blank.
 */
export function formatDate(value?: string | null): string {
    return formatWith(value, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/**
 * Format a date without the year, e.g. "Jun 1", for ranges where the year is
 * already implied.
 */
export function formatDayMonth(value?: string | null): string {
    return formatWith(value, { month: 'short', day: 'numeric' });
}

/**
 * Prefill an `<input type="date">`, which only accepts `yyyy-MM-dd`.
 */
export function toDateInputValue(value?: string | null): string {
    const day = utcDay(value);
    if (!day) return '';

    const month = String(day.month + 1).padStart(2, '0');
    const paddedDay = String(day.day).padStart(2, '0');

    return `${day.year}-${month}-${paddedDay}`;
}

function formatWith(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions,
): string {
    if (!value) return '';

    const date = new Date(value);
    if (isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('en-US', {
        ...options,
        timeZone: 'UTC',
    }).format(date);
}

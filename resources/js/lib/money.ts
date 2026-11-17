const NANODOLLARS_PER_DOLLAR = 1_000_000_000;

/**
 * Display a nanodollar amount. Sub-cent amounts keep enough digits to stay meaningful.
 */
export function formatUsd(nanos: number): string {
    const dollars = nanos / NANODOLLARS_PER_DOLLAR;
    const digits = Math.abs(dollars) >= 1 || dollars === 0 ? 2 : 4;

    return dollars.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

/**
 * The exact amount to the nanodollar, using integer maths only, e.g. "$0.004612500".
 */
export function formatUsdExact(nanos: number): string {
    const sign = nanos < 0 ? '-' : '';
    const absolute = Math.abs(nanos);
    const whole = Math.floor(absolute / NANODOLLARS_PER_DOLLAR);
    const fraction = String(absolute % NANODOLLARS_PER_DOLLAR).padStart(9, '0');

    return `${sign}$${whole}.${fraction}`;
}

/**
 * The first message out of an Inertia error bag, for showing as a toast when
 * an action that is not a form (a button) is refused.
 */
export function firstError(
    errors: Record<string, string>,
    fallback = 'Something went wrong.',
): string {
    return Object.values(errors)[0] ?? fallback;
}

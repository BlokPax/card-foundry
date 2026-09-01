// Card-selection logic, kept pure so it can run instantly in the browser
// (no server roundtrip per click) and still be unit tested. The acting set
// is what the header checkbox operates on — the filtered cards while a
// filter is active, every card otherwise. Selections outside the acting set
// are never touched.

/**
 * One click of the header checkbox: none selects the acting set, all clears
 * it, partial inverts it — and the click right after an invert clears.
 *
 * @returns {{selected: string[], inverted: boolean}}
 */
export function cycleSelection(selected, acting, inverted) {
    if (acting.length === 0) {
        return { selected, inverted: false };
    }

    const actingSet = new Set(acting);
    const chosen = new Set(selected.filter((ref) => actingSet.has(ref)));
    const state = chosen.size === 0 ? 'none' : chosen.size === acting.length ? 'all' : 'partial';
    const invert = state === 'partial' && !inverted;

    if (invert) {
        return {
            selected: [
                ...selected.filter((ref) => !actingSet.has(ref)),
                ...acting.filter((ref) => !chosen.has(ref)),
            ],
            inverted: true,
        };
    }

    if (state === 'none') {
        return { selected: [...new Set([...selected, ...acting])], inverted: false };
    }

    // all selected, or partial right after an invert: clear the acting set
    return { selected: selected.filter((ref) => !actingSet.has(ref)), inverted: false };
}

/** One card row's checkbox. */
export function toggleRef(selected, ref) {
    return selected.includes(ref)
        ? selected.filter((r) => r !== ref)
        : [...selected, ref];
}

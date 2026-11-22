function collapsedRows({ results, visibleLimit, mode, selected }) {
    return (results ?? []).filter((row, index) =>
        index < visibleLimit || (mode === 'suggestions' && row.location_id === selected?.location_id));
}

export function hasHiddenPutawayRows(state) {
    return (state.results?.length ?? 0) > collapsedRows(state).length;
}

export default function putawayResultRows(state) {
    const { results, showMore, selected, selectedCurrent } = state;
    const visible = showMore ? results ?? [] : collapsedRows(state);

    // Refresh the selected row's facts without changing the server's order.
    return visible.map(row => selectedCurrent && row.location_id === selected?.location_id
        ? { ...row, ...selected, ranking_explanation: row.status === selected.status ? row.ranking_explanation : null }
        : row);
}

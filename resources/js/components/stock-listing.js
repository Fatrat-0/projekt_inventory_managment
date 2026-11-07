import listingView from './listing-view';

export default () => ({
    ...listingView('stock'),
    minimumEdits: {},
    registerMinimum(id, value, editing) {
        if (!Object.hasOwn(this.minimumEdits, id)) this.minimumEdits[id] = { value: String(value), editing };
    },
});

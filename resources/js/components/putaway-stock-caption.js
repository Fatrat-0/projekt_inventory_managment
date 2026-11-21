const pieces = new Intl.NumberFormat('hu-HU');

export default function stockCaption(balance) {
    if (balance?.before == null) return 'Nem állapítható meg';
    const before = pieces.format(balance.before);
    if (balance.after == null) return before + ' db · utána nem becsülhető';
    if (balance.before === balance.after) return before + ' db · változatlan';

    return before + ' → ' + pieces.format(balance.after) + ' db';
}

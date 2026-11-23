const budapest = new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'Europe/Budapest', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
});
const zoneOffset = new Intl.DateTimeFormat('hu-HU', {
    timeZone: 'Europe/Budapest', timeZoneName: 'longOffset',
});

export function budapestLocalMinute(value) {
    const instant = value ? new Date(value) : null;
    return instant && Number.isFinite(instant.getTime()) ? budapest.format(instant).replace(' ', 'T') : '';
}

export function planningInstant(value) {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) return null;

    const civil = Date.parse(value + ':00Z');
    if (!Number.isFinite(civil) || new Date(civil).toISOString().slice(0, 16) !== value) return null;

    // At a clock change a Budapest minute can map to zero or two instants.
    const day = 86400000;
    const offsets = new Set([civil - day, civil + day].map(time =>
        zoneOffset.formatToParts(time).find(part => part.type === 'timeZoneName').value.replace('GMT', ''),
    ));
    const matches = [...offsets].filter(offset => budapestLocalMinute(value + ':00' + offset) === value);
    return matches.length === 1 ? value + ':00' + matches[0] : null;
}

export function planningFieldError(input, value, instant) {
    if (input.validity.rangeUnderflow) return input.dataset.pastMessage;
    if (!input.validity.valid) return 'Adj meg érvényes dátumot és időpontot.';
    if (value && !instant) return 'Ez az időpont magyarországi idő szerint nem létezik vagy kétszer fordul elő az óraátállításkor. Válassz másik időpontot.';
    return '';
}

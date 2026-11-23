<template x-if="{{ $putawayLocation }}?.discrepancy_severity">
    <p :class="{{ $putawayLocation }}.discrepancy_severity === 'critical' ? 'text-rose-900' : 'text-amber-950'"
       class="my-1 flex items-start gap-1 text-xs font-semibold">
        <x-icon name="warning" />
        <span x-text="{{ $putawayLocation }}.discrepancy_severity === 'critical'
            ? 'Kritikus terhelési eltérés – további elhelyezés tiltott'
            : 'Terhelési korlátozás – a terhelést a helyszínen újra kell ellenőrizni'"></span>
    </p>
</template>

@if ($showSubmissionMessage ?? true)
    <p x-show="submissionError" role="alert" class="text-sm text-amber-950" x-text="submissionError"></p>
@endif
<template x-if="Object.keys(fieldErrors).length">
    <ul class="list-disc space-y-1 ps-5 text-sm text-amber-950">
        <template x-for="(error, index) in Object.values(fieldErrors).flat()" :key="index">
            <li x-text="error"></li>
        </template>
    </ul>
</template>

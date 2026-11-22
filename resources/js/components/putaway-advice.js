// Each workspace supplies its own eligibility, input key and request handling.
export default function queuePutawayAdvice() {
    clearTimeout(this.adviceTimer);
    const key = this.adviceKey;
    if (!key || key === this.lastAdviceKey) return;
    this.adviceTimer = setTimeout(() => {
        if (key === this.adviceKey && key !== this.lastAdviceKey) {
            this.loadLocations();
        }
    }, 380);
}

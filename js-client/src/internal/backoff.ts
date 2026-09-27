/**
 * Exponential backoff with an optional cap and proportional jitter.
 *
 * `attempt` is zero-based and represents the number of failures so far, so
 * the first retry waits `base` ms, the second `base * 2`, the third
 * `base * 4`, and so on.
 */
export function computeBackoffDelay(
  attempt: number,
  base: number,
  max: number,
  jitter: number,
  random: () => number = Math.random,
): number {
  const safeAttempt = Number.isFinite(attempt) ? Math.max(0, Math.trunc(attempt)) : 0;
  const exponential = base * 2 ** safeAttempt;
  const capped = Math.min(exponential, max);

  if (jitter <= 0) {
    return capped;
  }

  const ratio = Math.min(Math.max(jitter, 0), 1);
  // Full jitter: uniform in [(1 - ratio) * capped, (1 + ratio) * capped],
  // clamped so the delay never goes negative.
  const spread = capped * ratio;
  const offset = (random() * 2 - 1) * spread;
  return Math.max(0, Math.min(capped + offset, max));
}

/** Promise-based sleep, extracted so tests can inject a fake. */
export function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

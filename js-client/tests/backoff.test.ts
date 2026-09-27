import { describe, expect, it, vi } from 'vitest';
import { computeBackoffDelay } from '../src/internal/backoff';

describe('computeBackoffDelay', () => {
  it('grows exponentially from the base delay', () => {
    expect(computeBackoffDelay(0, 250, 30_000, 0)).toBe(250);
    expect(computeBackoffDelay(1, 250, 30_000, 0)).toBe(500);
    expect(computeBackoffDelay(2, 250, 30_000, 0)).toBe(1_000);
    expect(computeBackoffDelay(3, 250, 30_000, 0)).toBe(2_000);
  });

  it('caps the delay at backoffMax', () => {
    expect(computeBackoffDelay(20, 250, 30_000, 0)).toBe(30_000);
  });

  it('is deterministic when jitter is disabled', () => {
    const random = vi.fn(() => 0.5);
    expect(computeBackoffDelay(2, 250, 30_000, 0, random)).toBe(1_000);
    expect(random).not.toHaveBeenCalled();
  });

  it('keeps jittered delays within the configured spread', () => {
    const low = computeBackoffDelay(2, 250, 30_000, 0.5, () => 0);
    const high = computeBackoffDelay(2, 250, 30_000, 0.5, () => 1);
    expect(low).toBe(500);
    expect(high).toBe(1_500);
  });

  it('never produces a negative delay for aggressive jitter', () => {
    expect(computeBackoffDelay(0, 250, 30_000, 1, () => 0)).toBe(0);
  });

  it('never exceeds backoffMax even with upward jitter', () => {
    expect(computeBackoffDelay(10, 250, 1_000, 1, () => 1)).toBe(1_000);
  });

  it('treats invalid attempt counts as zero', () => {
    expect(computeBackoffDelay(-5, 250, 30_000, 0)).toBe(250);
    expect(computeBackoffDelay(Number.NaN, 250, 30_000, 0)).toBe(250);
  });
});

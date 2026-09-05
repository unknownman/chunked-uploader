<?php

declare(strict_types=1);

// File: src/Core/Contracts/ProgressTrackerInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Derives completion and progress metrics from the persisted upload state.
 *
 * A pure read-model contract: implementations compute informational values from
 * the immutable UploadState and perform no writes. Keeping progress concerns
 * behind this contract prevents the upload coordinator from becoming a god
 * object and lets resumability endpoints query progress independently.
 */
interface ProgressTrackerInterface
{
    /**
     * Returns the upload completion percentage in the range 0.0 to 100.0.
     *
     * The percentage is the ratio of already-persisted chunk indices to the
     * declared total number of chunks. An empty upload yields 0.0 and a
     * complete upload yields exactly 100.0.
     *
     * @param UploadState $state Immutable upload snapshot to compute against
     *
     * @return float Percentage between 0.0 and 100.0 inclusive
     */
    public function getPercentage(UploadState $state): float;

    /**
     * Returns whether every declared chunk index has been persisted.
     *
     * @param UploadState $state Immutable upload snapshot to inspect
     */
    public function isComplete(UploadState $state): bool;

    /**
     * Returns the zero-based chunk indices that have not been persisted yet.
     *
     * The returned list is sorted ascending and is empty once the upload is
     * complete. These indices drive resumability: clients retry exactly the
     * chunks the server still needs.
     *
     * @param UploadState $state Immutable upload snapshot to inspect
     *
     * @return int[] Ascending list of missing zero-based chunk indices
     */
    public function getMissingChunkIndices(UploadState $state): array;
}
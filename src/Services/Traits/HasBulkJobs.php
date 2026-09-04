<?php


namespace nickdnk\Klaviyo\Services\Traits;

use nickdnk\Klaviyo\Services\BulkJobs;

trait HasBulkJobs
{

    /** @var array<string, BulkJobs> */
    private array $bulkJobFamilies = [];

    /**
     * Memoised accessor for one bulk-job family, keyed by its API path
     * (`profile-bulk-import-jobs`, `catalog-item-bulk-create-jobs`, …).
     *
     * @template T of \nickdnk\Klaviyo\Resources\Shared\IdentifiableResource
     * @return BulkJobs<T>
     */
    protected function bulkJobs(string $path): BulkJobs
    {

        return $this->bulkJobFamilies[$path] ??= new BulkJobs($this->request(...), $path);

    }

}

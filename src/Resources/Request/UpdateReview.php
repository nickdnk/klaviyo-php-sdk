<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Review;

/**
 * PATCH /api/reviews/{id}. Moderation only: the single writable attribute is `status`, an
 * object whose `value` is one of `featured`, `pending`, `published`, `rejected` or
 * `unpublished`. `rejected` is the one value that carries a `rejection_reason` object, whose
 * `reason` is required and whose `status_explanation` is free text shown alongside it.
 *
 * @property array|null $status  {value, rejection_reason?{reason, status_explanation?}}
 */
class UpdateReview extends Review
{

    public function __construct(string $id, ?string $status = null, ?string $rejectionReason = null,
        ?string $statusExplanation = null
    )
    {

        parent::__construct($id);

        if ($status === null) {
            return;
        }

        $value = ['value' => $status];

        if ($rejectionReason !== null) {
            $value['rejection_reason'] = self::filterNulls([
                'reason'             => $rejectionReason,
                'status_explanation' => $statusExplanation,
            ]);
        }

        $this->status = $value;
    }

}

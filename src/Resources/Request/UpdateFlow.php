<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Flow;

/**
 * PATCH /api/flows/{id}: sets the status of the flow and of every action inside it.
 *
 * @property string $status  draft|manual|live
 */
class UpdateFlow extends Flow
{

    public function __construct(string $id, string $status)
    {

        parent::__construct($id);
        $this->status = $status;
    }

}

<?php


namespace nickdnk\Klaviyo\Resources\Request;


/**
 * Partial update of an existing profile (PATCH /api/profiles/{id}/). Same attributes as
 * CreateProfile / ProfileRequest; requires a Klaviyo profile id.
 *
 * @property string $id
 */
class PatchProfile extends ProfileRequest
{

    public function __construct(string $id) { parent::__construct($id); }

}

<?php


namespace nickdnk\Klaviyo\Resources\Request;

/**
 * New-profile payload for POST /api/profiles/ or the embedded profile on event / subscribe
 * requests. No Klaviyo id — one is assigned by Klaviyo.
 */
class CreateProfile extends ProfileRequest
{

    public function __construct() { parent::__construct(); }

}

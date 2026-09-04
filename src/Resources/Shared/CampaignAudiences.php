<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * The list / segment ids a campaign sends to, both on create-update requests and in responses.
 *
 * @property string[]|null $included
 * @property string[]|null $excluded
 */
class CampaignAudiences extends Resource
{

    /**
     * @param string[] $included list / segment ids the campaign sends to
     * @param string[] $excluded list / segment ids held back from the send
     */
    public function __construct(array $included = [], array $excluded = [])
    {

        $this->included = $included ? array_values($included) : null;
        $this->excluded = $excluded ? array_values($excluded) : null;
    }

}

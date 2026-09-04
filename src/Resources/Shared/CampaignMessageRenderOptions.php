<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * `render_options` of an SMS {@see CampaignMessageDefinition}: the boilerplate Klaviyo adds
 * around the message body.
 *
 * @property bool|null $shorten_links
 * @property bool|null $add_org_prefix
 * @property bool|null $add_info_link
 * @property bool|null $add_opt_out_language
 */
class CampaignMessageRenderOptions extends Resource
{

    public function __construct(?bool $shortenLinks = null, ?bool $addOrgPrefix = null, ?bool $addInfoLink = null,
        ?bool $addOptOutLanguage = null
    )
    {

        $this->shorten_links = $shortenLinks;
        $this->add_org_prefix = $addOrgPrefix;
        $this->add_info_link = $addInfoLink;
        $this->add_opt_out_language = $addOptOutLanguage;
    }

}

<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\AssignTemplateToCampaignMessage;
use nickdnk\Klaviyo\Resources\Request\UpdateCampaignMessage;
use nickdnk\Klaviyo\Resources\Response\Campaign;
use nickdnk\Klaviyo\Resources\Response\CampaignMessage;
use nickdnk\Klaviyo\Resources\Response\Image as ResponseImage;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Resources\Shared\Image;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * The individual channel messages of a campaign. Messages are created and removed with their
 * campaign ({@see CampaignService::create()}), so this service reads and edits existing ones:
 * their definition, the template they render and the image they carry. Campaign, template and
 * image are all to-one relations.
 */
class CampaignMessageService extends BaseService
{

    use HasGet;
    use HasRelationships;
    use HasUpdate;

    private const string PATH_ASSIGN_TEMPLATE = 'campaign-message-assign-template';

    /**
     * @link https://developers.klaviyo.com/en/reference/get_campaign_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): CampaignMessage|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_campaign_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateCampaignMessage $message, ?Query $query = null, bool $returnRequest = false): CampaignMessage|RequestInterface
    {

        return $this->updateTrait($message, $query, $returnRequest);

    }

    /**
     * Snapshots a reusable template into a non-reusable version owned by the message.
     *
     * @link https://developers.klaviyo.com/en/reference/assign_template_to_campaign_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function assignTemplate(string $messageId, string $templateId, bool $returnRequest = false): CampaignMessage|RequestInterface
    {

        $result = $this->request(
                           'POST',
                           self::PATH_ASSIGN_TEMPLATE,
                           new AssignTemplateToCampaignMessage($messageId, $templateId),
            returnRequest: $returnRequest,
        );

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_campaign_for_campaign_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function campaign(string $messageId, ?Query $query = null, bool $returnRequest = false): Campaign|RequestInterface|null
    {

        return $this->relatedOneTrait($messageId, 'campaign', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_campaign_id_for_campaign_message
     * @return Campaign|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function campaignId(string $messageId, bool $returnRequest = false): Campaign|RequestInterface|null
    {

        return $this->relatedOneIdTrait($messageId, 'campaign', $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_template_for_campaign_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function template(string $messageId, ?Query $query = null, bool $returnRequest = false): Template|RequestInterface|null
    {

        return $this->relatedOneTrait($messageId, 'template', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_template_id_for_campaign_message
     * @return Template|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function templateId(string $messageId, bool $returnRequest = false): Template|RequestInterface|null
    {

        return $this->relatedOneIdTrait($messageId, 'template', $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_image_for_campaign_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function image(string $messageId, ?Query $query = null, bool $returnRequest = false): ResponseImage|RequestInterface|null
    {

        return $this->relatedOneTrait($messageId, 'image', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_image_id_for_campaign_message
     * @return ResponseImage|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function imageId(string $messageId, bool $returnRequest = false): ResponseImage|RequestInterface|null
    {

        return $this->relatedOneIdTrait($messageId, 'image', $returnRequest);

    }

    /**
     * Points the message at a different image. The body is a single identifier rather than the
     * identifier list a to-many relationship takes, and Klaviyo answers 204.
     *
     * @link https://developers.klaviyo.com/en/reference/update_image_for_campaign_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function updateImage(string $messageId, string $imageId, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request(
                           'PATCH',
                           $this->apiPath() . '/' . $messageId . '/relationships/image',
                           new Image($imageId),
            returnRequest: $returnRequest,
        );

    }

    // endregion

    protected function apiPath(): string
    {

        return 'campaign-messages';
    }
}

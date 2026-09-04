<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateConversationMessage;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use Psr\Http\Message\RequestInterface;

/**
 * Outbound messages into a Klaviyo conversation. Send-only: a conversation's inbound history
 * is not exposed here, and the endpoint requires account-level enablement of the
 * conversations API.
 */
class ConversationMessageService extends BaseService
{

    use HasCreate;

    /**
     * Klaviyo answers 202 with an empty body; delivery happens asynchronously.
     *
     * @link https://developers.klaviyo.com/en/reference/create_conversation_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateConversationMessage $message, ?Query $query = null, bool $returnRequest = false): ?RequestInterface
    {

        return $this->createTrait($message, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'conversation-messages';
    }
}

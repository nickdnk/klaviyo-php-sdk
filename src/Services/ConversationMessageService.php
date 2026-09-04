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
 * Outbound messages into a Klaviyo conversation, the account's two-way SMS support threads.
 *
 * - Send-only: a conversation's history is read from the profile it belongs to
 *   ({@see ProfileService::conversations()}).
 * - Requires account-level enablement of the conversations API; without it every call answers
 *   403.
 *
 * @link https://developers.klaviyo.com/en/reference/conversations_api_overview
 */
class ConversationMessageService extends BaseService
{

    use HasCreate;

    /**
     * Sends a message to a real recipient; delivery happens asynchronously.
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

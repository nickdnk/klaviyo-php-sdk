<?php


namespace nickdnk\Klaviyo\Resources;

use nickdnk\Klaviyo\Resources\Response\Account;
use nickdnk\Klaviyo\Resources\Response\BulkImportJob;
use nickdnk\Klaviyo\Resources\Response\Campaign;
use nickdnk\Klaviyo\Resources\Response\CampaignMessage;
use nickdnk\Klaviyo\Resources\Response\CampaignRecipientEstimation;
use nickdnk\Klaviyo\Resources\Response\CampaignRecipientEstimationJob;
use nickdnk\Klaviyo\Resources\Response\CampaignSendJob;
use nickdnk\Klaviyo\Resources\Response\CampaignValuesReport;
use nickdnk\Klaviyo\Resources\Response\CatalogCategory;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItem;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariant;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Response\Conversation;
use nickdnk\Klaviyo\Resources\Response\Coupon;
use nickdnk\Klaviyo\Resources\Response\CouponCode;
use nickdnk\Klaviyo\Resources\Response\CouponCodeBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CustomMetric;
use nickdnk\Klaviyo\Resources\Response\DataSource;
use nickdnk\Klaviyo\Resources\Response\Event;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\FlowAction;
use nickdnk\Klaviyo\Resources\Response\FlowMessage;
use nickdnk\Klaviyo\Resources\Response\FlowSeriesReport;
use nickdnk\Klaviyo\Resources\Response\FlowValuesReport;
use nickdnk\Klaviyo\Resources\Response\Form;
use nickdnk\Klaviyo\Resources\Response\FormSeriesReport;
use nickdnk\Klaviyo\Resources\Response\FormValuesReport;
use nickdnk\Klaviyo\Resources\Response\FormVersion;
use nickdnk\Klaviyo\Resources\Response\Image;
use nickdnk\Klaviyo\Resources\Response\ImportError;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\MappedMetric;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Response\MetricAggregate;
use nickdnk\Klaviyo\Resources\Response\MetricProperty;
use nickdnk\Klaviyo\Resources\Response\ObjectIngestionLog;
use nickdnk\Klaviyo\Resources\Response\ObjectRecord;
use nickdnk\Klaviyo\Resources\Response\ObjectSchema;
use nickdnk\Klaviyo\Resources\Response\ObjectType;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\PushToken;
use nickdnk\Klaviyo\Resources\Response\Review;
use nickdnk\Klaviyo\Resources\Response\Segment;
use nickdnk\Klaviyo\Resources\Response\SegmentSeriesReport;
use nickdnk\Klaviyo\Resources\Response\SegmentValuesReport;
use nickdnk\Klaviyo\Resources\Response\SourceMapping;
use nickdnk\Klaviyo\Resources\Response\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Response\SuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Response\TagGroup;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Resources\Response\TemplateUniversalContent;
use nickdnk\Klaviyo\Resources\Response\TrackingSetting;
use nickdnk\Klaviyo\Resources\Response\WebFeed;
use nickdnk\Klaviyo\Resources\Response\Webhook;
use nickdnk\Klaviyo\Resources\Response\WebhookTopic;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\ProfileObjectSchema;
use nickdnk\Klaviyo\Resources\Shared\ProfileObjectType;

/**
 * Maps a JSON:API `type` string to the response class that hydrates it. This is the one
 * file every new entity touches: add its response class to {@see self::CLASSES} and
 * {@see \nickdnk\Klaviyo\APIClient::hydrateResponse()} picks it up, including
 * for nested `relationships` and webhook payloads.
 *
 * Types without an entry hydrate as plain arrays, so a missing registration degrades
 * rather than fails. Identifier-only types that never carry attributes (`profile-object-type`,
 * `profile-object-schema`) register their Shared class directly.
 */
final class TypeRegistry
{

    /** @var list<class-string<IdentifiableResource>> */
    private const array CLASSES
        = [
            Account::class,
            BulkImportJob::class,
            Campaign::class,
            CampaignMessage::class,
            CampaignRecipientEstimation::class,
            CampaignRecipientEstimationJob::class,
            CampaignSendJob::class,
            CampaignValuesReport::class,
            CatalogCategory::class,
            CatalogCategoryBulkCreateJob::class,
            CatalogCategoryBulkDeleteJob::class,
            CatalogCategoryBulkUpdateJob::class,
            CatalogItem::class,
            CatalogItemBulkCreateJob::class,
            CatalogItemBulkDeleteJob::class,
            CatalogItemBulkUpdateJob::class,
            CatalogVariant::class,
            CatalogVariantBulkCreateJob::class,
            CatalogVariantBulkDeleteJob::class,
            CatalogVariantBulkUpdateJob::class,
            Conversation::class,
            Coupon::class,
            CouponCode::class,
            CouponCodeBulkCreateJob::class,
            CustomMetric::class,
            DataSource::class,
            Event::class,
            Flow::class,
            FlowAction::class,
            FlowMessage::class,
            FlowSeriesReport::class,
            FlowValuesReport::class,
            Form::class,
            FormSeriesReport::class,
            FormValuesReport::class,
            FormVersion::class,
            Image::class,
            ImportError::class,
            KlaviyoList::class,
            MappedMetric::class,
            Metric::class,
            MetricAggregate::class,
            MetricProperty::class,
            ObjectIngestionLog::class,
            ObjectRecord::class,
            ObjectSchema::class,
            ObjectType::class,
            Profile::class,
            ProfileObjectSchema::class,
            ProfileObjectType::class,
            PushToken::class,
            Review::class,
            Segment::class,
            SegmentSeriesReport::class,
            SegmentValuesReport::class,
            SourceMapping::class,
            SuppressionCreateJob::class,
            SuppressionDeleteJob::class,
            Tag::class,
            TagGroup::class,
            Template::class,
            TemplateUniversalContent::class,
            TrackingSetting::class,
            WebFeed::class,
            Webhook::class,
            WebhookTopic::class,
        ];

    /** @var array<string, class-string<IdentifiableResource>>|null */
    private static ?array $byType = null;

    /**
     * @return class-string<IdentifiableResource>|null
     */
    public static function resolve(string $type): ?string
    {

        return self::map()[$type] ?? null;

    }

    /**
     * @return array<string, class-string<IdentifiableResource>>
     */
    public static function map(): array
    {

        if (self::$byType === null) {
            self::$byType = [];
            foreach (self::CLASSES as $class) {
                self::$byType[$class::type()] = $class;
            }
        }

        return self::$byType;

    }

}

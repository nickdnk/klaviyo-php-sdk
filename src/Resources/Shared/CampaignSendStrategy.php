<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * When a campaign goes out. Klaviyo models this as a `method`-discriminated union, so build
 * one through the named constructor for the method you want — each sets exactly the fields
 * that method requires.
 *
 * @property string|null                      $method               static|throttled|immediate|smart_send_time
 * @property string|null                      $datetime             static / throttled
 * @property string|null                      $date                 smart_send_time, as `YYYY-MM-DD`
 * @property int|null                         $throttle_percentage  throttled: 1|2|5|10|11|13|14|17|20|25|33|50
 * @property CampaignSendStrategyOptions|null $options              static
 */
class CampaignSendStrategy extends Resource
{

    public function __construct(?string $method = null)
    {

        $this->method = $method;
    }

    /**
     * Sends as soon as the campaign is triggered.
     */
    public static function immediate(): self
    {

        return new self('immediate');

    }

    /**
     * Sends at a fixed ISO 8601 datetime, optionally in each recipient's own timezone.
     */
    public static function at(string $datetime, ?CampaignSendStrategyOptions $options = null): self
    {

        $strategy = new self('static');
        $strategy->datetime = $datetime;
        $strategy->options = $options;

        return $strategy;

    }

    /**
     * Spreads the send from $datetime onwards, delivering $throttlePercentage of the audience
     * per hour.
     */
    public static function throttled(string $datetime, int $throttlePercentage): self
    {

        $strategy = new self('throttled');
        $strategy->datetime = $datetime;
        $strategy->throttle_percentage = $throttlePercentage;

        return $strategy;

    }

    /**
     * Sends on $date (`YYYY-MM-DD`), letting Klaviyo pick each recipient's time of day.
     */
    public static function smartSendTime(string $date): self
    {

        $strategy = new self('smart_send_time');
        $strategy->date = $date;

        return $strategy;

    }

    protected static function nested(): array
    {

        return ['options' => CampaignSendStrategyOptions::class];
    }

}

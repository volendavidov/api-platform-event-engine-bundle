<?php

declare(strict_types=1);

namespace ADS\Bundle\ApiPlatformEventEngineBundle\Message;

use ADS\Bundle\ApiPlatformEventEngineBundle\Message\Callback\ImmutableObject\CallbackRequestBody;
use ADS\Bundle\ApiPlatformEventEngineBundle\Message\Callback\ValueObject\CallbackUrl;

trait CallbackMessageLogic
{
    /**
     * The url that will be called if everything is executed.
     * If no 'callback_url' is provided, the callback call will not be executed.
     */
    private ?CallbackUrl $callbackUrl = null;

    public function callbackUrl(): ?CallbackUrl
    {
        return $this->callbackUrl;
    }

    public static function __defaultCallbackEvent(): string
    {
        return 'success';
    }

    /**
     * @inheritDoc
     */
    public static function __callbackEvents(): array
    {
        return [
            self::__defaultCallbackEvent() => CallbackRequestBody::class,
        ];
    }

    /**
     * @inheritDoc
     */
    public static function __callbackEvent(array $callbackResponses): string
    {
        return self::__defaultCallbackEvent();
    }

    /**
     * @inheritDoc
     */
    public static function __callbackRequestBody(string $callbackEvent, array $callbackResponses): array
    {
        return ['event' => $callbackEvent];
    }
}

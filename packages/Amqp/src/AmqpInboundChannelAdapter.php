<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use AMQPConnectionException;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Amqp\AmqpMessage;
use Interop\Queue\Consumer;
use Interop\Queue\Message as EnqueueMessage;

/**
 * Class InboundEnqueueGateway
 * @package Ecotone\Amqp
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class AmqpInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    private bool $initialized = false;
    private QueueChannel $queueChannel;

    public function __construct(
        CachedConnectionFactory         $cachedConnectionFactory,
        InboundChannelAdapterEntrypoint $inboundAmqpGateway,
        private AmqpAdmin               $amqpAdmin,
        bool                            $declareOnStartup,
        string                          $queueName,
        int                             $receiveTimeoutInMilliseconds,
        InboundMessageConverter         $inboundMessageConverter,
        ConversionService $conversionService
    ) {
        parent::__construct(
            $cachedConnectionFactory,
            $inboundAmqpGateway,
            $declareOnStartup,
            $queueName,
            $receiveTimeoutInMilliseconds,
            $inboundMessageConverter,
            $conversionService
        );
        $this->queueChannel = QueueChannel::create();
    }

    public function initialize(): void
    {
        $this->amqpAdmin->declareQueueWithBindings($this->queueName, $this->connectionFactory->createContext());
    }

    /**
     * @param AmqpMessage $sourceMessage
     */
    public function enrichMessage(EnqueueMessage $sourceMessage, MessageBuilder $targetMessage): MessageBuilder
    {
        if ($sourceMessage->getContentType()) {
            $targetMessage = $targetMessage->setContentType(MediaType::parseMediaType($sourceMessage->getContentType()));
        }

        return $targetMessage;
    }

    public function receiveMessage(int $timeout = 0): ?Message
    {
        try {
            if ($this->declareOnStartup && $this->initialized === false) {
                $this->initialize();

                $this->initialized = true;
            }

            /** @var AmqpReconnectableConnectionFactory $connectionFactory */
            $connectionFactory = $this->connectionFactory->getInnerConnectionFactory();
            $queueChannel = $this->queueChannel;
            $subscriptionConsumer = $connectionFactory->getSubscriptionConsumer($this->queueName, function (EnqueueMessage $receivedMessage, Consumer $consumer) use ($queueChannel) {
                $message = $this->inboundMessageConverter->toMessage($receivedMessage, $consumer, $this->conversionService);
                $message = $this->enrichMessage($receivedMessage, $message);

                $queueChannel->send($message->build());

                return false;
            });

            $subscriptionConsumer->consume($timeout ?: $this->receiveTimeoutInMilliseconds);

            return $this->queueChannel->receive();
        } catch (AMQPConnectionException $exception) {
            throw new ConnectionException('Failed to connect to AMQP broker', 0, $exception);
        }
    }

    public function connectionException(): string
    {
        return AMQPConnectionException::class;
    }
}

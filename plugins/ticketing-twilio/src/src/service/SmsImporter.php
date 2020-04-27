<?php

declare(strict_types=1);


namespace TicketingTwilio\Service;


use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\GuzzleException;
use TicketingTwilio\Plugin;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Ubnt\UcrmPluginSdk\Exception\JsonException;
use Ubnt\UcrmPluginSdk\Service\PluginConfigManager;
use Ubnt\UcrmPluginSdk\Service\UcrmApi;

class SmsImporter
{
    /**
     * @var TwilioClientFactory
     */
    private $twilioClientFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var PluginConfigManager
     */
    private $pluginConfigManager;

    public function __construct(
        TwilioClientFactory $twilioClientFactory,
        Logger $logger,
        PluginConfigManager $pluginConfigManager
    ) {
        $this->twilioClientFactory = $twilioClientFactory;
        $this->logger = $logger;
        $this->pluginConfigManager = $pluginConfigManager;
    }

    public function importToTicketing(): void
    {
        $config = $this->pluginConfigManager->loadConfig();
        $configLastImportedDate = new DateTimeImmutable($config[Plugin::MANIFEST_CONFIGURATION_KEY_LAST_IMPORTED_DATE]);
        $client = $this->twilioClientFactory->create();

        $dateSentAfter = $configLastImportedDate->setTimezone(new DateTimeZone('GMT'))->modify('-1 day')->format('Y-m-d');
        $this->logger->info(sprintf('Starts read of messages sent after %s (GMT).', $dateSentAfter));

        foreach ($client->messages->read(['dateSentAfter' => $dateSentAfter]) as $messageInstance) {
            if ($messageInstance->direction !== 'inbound') {
                continue;
            }

            $this->createTicketComment($messageInstance);
        }
        $this->logger->info('Ends read of messages.');
    }

    private function createTicketComment(MessageInstance $messageInstance): void 
    {
        $api = UcrmApi::create();
        $this->logger->info('Creating ticket.');
        $ticket = $api->post(
            'ticketing/tickets',
            [
                'subject' => mb_substr(sprintf('Message from Twilio SMS: %s ', $messageInstance->body), 0, 120),
                'createdAt' => $messageInstance->dateCreated->format(DATE_ATOM),
                'activity' => [
                    [
                        'createdAt' => $messageInstance->dateCreated->format(DATE_ATOM),
                        'comment' => [
                            'body' => $messageInstance->body,
                        ],
                    ],
                ],
            ]
        );

        /**
         * Error here, we cannot create Ticket without Client or Email
         * @todo after CRM update
         */
        $this->logger->info(sprintf('Ticket ID %s created.', $ticket['id'] ?? ''));
    }
}

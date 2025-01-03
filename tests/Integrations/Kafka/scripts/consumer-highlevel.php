<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\KafkaConsumer;

$conf = new Conf();
$conf->set('bootstrap.servers', 'kafka_integration:9092');
$conf->set('group.id', 'consumer-highlevel');
$conf->set('enable.partition.eof', 'true');
$conf->set('auto.offset.reset', 'earliest');

// Track partitions that have been fully consumed
$partitionsEof = [];

$consumer = new KafkaConsumer($conf);
$consumer->subscribe(['test-highlevel']);

echo "Consumer started, waiting for messages...\n";

do {
    $message = $consumer->consume(5000);

    switch ($message->err) {
        case RD_KAFKA_RESP_ERR_NO_ERROR:
            // Process the message
            echo sprintf("Message consumed: %s\n", $message->payload);
            // Headers
            echo sprintf("Headers: %s\n", json_encode($message->headers));

            // Commit the message offset after processing it
            $consumer->commit($message);

            break;

        case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            // Mark the partition as fully consumed
            echo sprintf("Partition %d fully consumed\n", $message->partition);
            $partitionsEof[$message->partition] = true;
            break;

        case RD_KAFKA_RESP_ERR__TIMED_OUT:
            // Ignore timeout errors
            echo "Timed out waiting for messages...\n";
            break;

        default:
            // Handle other errors
            echo sprintf("Error: %s\n", $message->errstr());
            exit(1);
    }

    // Get the current assignment of partitions
    $assignments = $consumer->getAssignment();

    // Check if all partitions have been fully consumed
    if (count($assignments) > 0 && count($partitionsEof) === count($assignments)) {
        echo "All partitions fully consumed. Exiting...\n";
        break;
    }
} while (true);

$consumer->close();

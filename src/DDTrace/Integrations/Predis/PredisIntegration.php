<?php

namespace DDTrace\Integrations\Predis;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\NodeConnectionInterface;

const VALUE_PLACEHOLDER = "?";
const VALUE_MAX_LEN = 100;
const VALUE_TOO_LONG_MARK = "...";
const CMD_MAX_LEN = 1000;

class PredisIntegration extends Integration
{
    const NAME = 'predis';
    const SYSTEM = 'redis';

    const DEFAULT_SERVICE_NAME = 'redis';

    /**
     * Add instrumentation to PDO requests
     */
    public function init(): int
    {
        $integration = $this;

        \DDTrace\trace_method('Predis\Client', '__construct', function (SpanData $span, $args) {
            Integration::handleOrphan($span);

            $span->name = 'Predis.Client.__construct';
            $span->type = Type::REDIS;
            $span->resource = 'Predis.Client.__construct';
            PredisIntegration::storeConnectionMetaAndService($this, $args);
            PredisIntegration::setMetaAndServiceFromConnection($this, $span);
        });

        \DDTrace\trace_method('Predis\Client', 'connect', function (SpanData $span, $args) {
            Integration::handleOrphan($span);

            $span->name = 'Predis.Client.connect';
            $span->type = Type::REDIS;
            $span->resource = 'Predis.Client.connect';
            PredisIntegration::setMetaAndServiceFromConnection($this, $span);
        });

        \DDTrace\trace_method('Predis\Client', 'executeCommand', function (SpanData $span, $args) use ($integration) {
            Integration::handleOrphan($span);

            $span->name = 'Predis.Client.executeCommand';
            $span->type = Type::REDIS;
            PredisIntegration::setMetaAndServiceFromConnection($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);

            // We default resource name to 'Predis.Client.executeCommand', but if we are able below to extract the query
            // then we replace it with the query
            $span->resource = 'Predis.Client.executeCommand';

            if (\count($args) == 0) {
                return;
            }

            $command = $args[0];
            $arguments = $command->getArguments();
            array_unshift($arguments, $command->getId());
            $span->meta['redis.args_length'] = count($arguments);
            $query = PredisIntegration::formatArguments($arguments);
            $span->resource = $query;
            $span->meta['redis.raw_command'] = $query;
        });

        \DDTrace\trace_method('Predis\Client', 'executeRaw', function (SpanData $span, $args) use ($integration) {
            Integration::handleOrphan($span);

            $span->name = 'Predis.Client.executeRaw';
            $span->type = Type::REDIS;
            PredisIntegration::setMetaAndServiceFromConnection($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);

            // We default resource name to 'Predis.Client.executeRaw', but if we are able below to extract the query
            // then we replace it with the query
            $span->resource = 'Predis.Client.executeRaw';

            if (\count($args) == 0) {
                return;
            }
            $arguments = $args[0];
            $query = PredisIntegration::formatArguments($arguments);
            $span->resource = $query;
            $span->meta['redis.args_length'] = count($arguments);
            $span->meta['redis.raw_command'] = $query;
        });

        \DDTrace\trace_method(
            'Predis\Pipeline\Pipeline',
            'executePipeline',
            [
                'prehook' => function (SpanData $span, $args) {
                    Integration::handleOrphan($span);

                    $span->name = 'Predis.Pipeline.executePipeline';
                    $span->resource = $span->name;
                    $span->type = Type::REDIS;
                    PredisIntegration::setMetaAndServiceFromConnection($this->getClient(), $span);
                    if (\count($args) < 2) {
                        return;
                    }
                    $commands = $args[1];
                    $span->meta['redis.pipeline_length'] = count($commands);
                },
            ]
        );

        return Integration::LOADED;
    }

    /**
     * This function is a clone of PredisIntegration::storeConnectionParams.
     * Store connection params to be reused to tags following predids queries.
     *
     * @param Predis\Client $predis
     * @param array $args
     * @return void
     */
    public static function storeConnectionMetaAndService($predis, $args)
    {
        $tags = [];
        $connection = $predis->getConnection();

        if ($connection instanceof NodeConnectionInterface) {
            $connectionParameters = $connection->getParameters();

            $tags[Tag::TARGET_HOST] = $connectionParameters->host;
            $tags[Tag::TARGET_PORT] = $connectionParameters->port;

            if (\dd_trace_env_config("DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST")) {
                $service = \DDTrace\Util\Normalizer::normalizeHostUdsAsService(
                    'redis-' . (isset($connectionParameters->path)
                        ? $connectionParameters->path
                        : $connectionParameters->host)
                );
                ObjectKVStore::put($predis->getConnection(), 'service', $service);
            }
        }

        if (isset($args[1])) {
            $options = $args[1];

            if (is_array($options)) {
                $parameters = isset($options['parameters']) ? $options['parameters'] : [];
            } elseif ($options instanceof OptionsInterface) {
                $parameters = $options->__get('parameters') ?: [];
            }

            if (is_array($parameters) && isset($parameters['database'])) {
                $tags['out.redis_db'] = $parameters['database'];
            }
        }

        ObjectKVStore::put($predis->getConnection(), 'connection_meta', $tags);
    }

    /**
     * This function is almost a clone of PredisIntegration::setConnectionTags.
     * Store connection tags into a successive span not having direct access to those values.
     *
     * @param Predis\Client $predis
     * @param DDTrace\SpanData $span
     * @return void
     */
    public static function setMetaAndServiceFromConnection($predis, SpanData $span)
    {
        $service = ObjectKVStore::get($predis->getConnection(), 'service');
        if ($service) {
            $span->meta[Tag::SERVICE_NAME] = $service;
        } else {
            Integration::handleInternalSpanServiceName($span, PredisIntegration::DEFAULT_SERVICE_NAME);
        }
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = PredisIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = PredisIntegration::SYSTEM;

        foreach (ObjectKVStore::get($predis->getConnection(), 'connection_meta', []) as $tag => $value) {
            $span->meta[$tag] = $value;
        }
    }

    /**
     * This function is a clone of PredisIntegration::formatArguments.
     * Format a command by removing unwanted values
     *
     * Restrict what we keep from the values sent (with a SET, HGET, LPUSH, ...):
     * - Skip binary content
     * - Truncate
     */
    public static function formatArguments($arguments)
    {
        $len = 0;
        $out = [];

        foreach ($arguments as $argument) {
            // crude test to skip binary
            if (strpos($argument, "\0") !== false) {
                continue;
            }

            $cmd = (string)$argument;

            if (strlen($cmd) > VALUE_MAX_LEN) {
                $cmd = substr($cmd, 0, VALUE_MAX_LEN) . VALUE_TOO_LONG_MARK;
            }

            if (($len + strlen($cmd)) > CMD_MAX_LEN) {
                $prefix = substr($cmd, 0, CMD_MAX_LEN - $len);
                $out[] = $prefix . VALUE_TOO_LONG_MARK;
                break;
            }

            $out[] = $cmd;
            $len += strlen($cmd);
        }

        return implode(' ', $out);
    }
}

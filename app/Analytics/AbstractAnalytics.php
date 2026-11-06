<?php

declare(strict_types=1);

namespace Modules\CMS\Analytics;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch as ElasticsearchResponse;
use Exception;
use Http\Promise\Promise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

abstract class AbstractAnalytics
{
    /**
     * Get Elasticsearch client.
     */
    protected function getElasticsearchClient(): Client
    {
        return resolve(Client::class);
    }

    /**
     * Get cache key based on filters.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function getCacheKey(string $metric, array $filters = []): string
    {
        if ($filters === []) {
            return 'analytics_' . $metric;
        }

        try {
            $encoded_filters = json_encode($filters, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $encoded_filters = '';
        }

        return 'analytics_' . $metric . '_' . md5($encoded_filters);
    }

    /**
     * Get term-based metrics from Elasticsearch.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function getTermBasedMetrics(Model $model, string $field, array $filters = [], int $size = 10): array
    {
        $client = $this->getElasticsearchClient();

        $query = [
            'index' => $model->searchableAs(),
            'body' => [
                'size' => 0,
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'match' => [
                                    'entity' => $model->getTable(),
                                ],
                            ],
                        ],
                    ],
                ],
                'aggs' => [
                    'by_term' => [
                        'terms' => [
                            'field' => $field,
                            'size' => $size,
                        ],
                    ],
                ],
            ],
        ];

        // Aggiungi filtri alla query se presenti
        foreach ($filters as $filter_field => $value) {
            $query['body']['query']['bool']['must'][] = ['match' => [$filter_field => $value]];
        }

        try {
            $response = $client->search($query);
            $results = $this->parseElasticsearchSearchResponse($response);

            return $this->elasticsearchAggregationBuckets($results, 'aggregations', 'by_term', 'buckets');
        } catch (Exception $exception) {
            // Log dell'errore
            Log::error('Elasticsearch term-based metrics query failed', [
                'error' => $exception->getMessage(),
                'field' => $field,
                'index' => $model->searchableAs(),
                'filters' => $filters,
            ]);

            return [];
        }
    }

    /**
     * Get geo-based metrics from Elasticsearch.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function getGeoBasedMetrics(Model $model, string $geo_field, array $filters = []): array
    {
        $client = $this->getElasticsearchClient();

        $query = [
            'index' => $model->searchableAs(),
            'body' => [
                'size' => 0,
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'match' => [
                                    'entity' => $model->getTable(),
                                ],
                            ],
                        ],
                    ],
                ],
                'aggs' => [
                    'geo_clusters' => [
                        'geohash_grid' => [
                            'field' => $geo_field,
                            'precision' => 5,
                        ],
                    ],
                ],
            ],
        ];

        // Aggiungi filtri alla query se presenti
        foreach ($filters as $field => $value) {
            $query['body']['query']['bool']['must'][] = ['match' => [$field => $value]];
        }

        try {
            $response = $client->search($query);
            $results = $this->parseElasticsearchSearchResponse($response);

            return $this->elasticsearchAggregationBuckets($results, 'aggregations', 'geo_clusters', 'buckets');
        } catch (Exception $exception) {
            // Log dell'errore
            Log::error('Elasticsearch geo-based metrics query failed', [
                'error' => $exception->getMessage(),
                'index' => $model->searchableAs(),
                'filters' => $filters,
            ]);

            return [];
        }
    }

    /**
     * Get distribution metrics by field.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function getDistributionByField(Model $model, string $field, array $filters = [], int $size = 10): array
    {
        $client = $this->getElasticsearchClient();

        $query = [
            'index' => $model->searchableAs(),
            'body' => [
                'size' => 0,
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'match' => [
                                    'entity' => $model->getTable(),
                                ],
                            ],
                        ],
                    ],
                ],
                'aggs' => [
                    'distribution' => [
                        'terms' => [
                            'field' => $field,
                            'size' => $size,
                        ],
                    ],
                ],
            ],
        ];

        // Aggiungi filtri alla query se presenti
        foreach ($filters as $filter_field => $value) {
            $query['body']['query']['bool']['must'][] = ['match' => [$filter_field => $value]];
        }

        try {
            $response = $client->search($query);
            $results = $this->parseElasticsearchSearchResponse($response);

            return $this->elasticsearchAggregationBuckets($results, 'aggregations', 'distribution', 'buckets');
        } catch (Exception $exception) {
            // Log dell'errore
            Log::error('Elasticsearch distribution metrics query failed', [
                'error' => $exception->getMessage(),
                'field' => $field,
                'index' => $model->searchableAs(),
                'filters' => $filters,
            ]);

            return [];
        }
    }

    /**
     * Get time-based metrics.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function getTimeBasedMetrics(Model $model, string $date_field, string $interval = 'day', array $filters = [], ?Carbon $start_date = null, ?Carbon $end_date = null): array
    {
        $client = $this->getElasticsearchClient();

        // Default all'ultimo mese se non specificato
        $start_date ??= Date::now()->subMonth();
        $end_date ??= Date::now();

        $query = [
            'index' => $model->searchableAs(),
            'body' => [
                'size' => 0,
                'query' => [
                    'bool' => [
                        'must' => [
                            ['match' => ['entity' => $model->getTable()]],
                            [
                                'range' => [
                                    $date_field => [
                                        'gte' => $start_date->toIso8601String(),
                                        'lte' => $end_date->toIso8601String(),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'aggs' => [
                    'time_series' => [
                        'date_histogram' => [
                            'field' => $date_field,
                            'calendar_interval' => $interval,
                        ],
                    ],
                ],
            ],
        ];

        // Aggiungi filtri alla query se presenti
        foreach ($filters as $filter_field => $value) {
            $query['body']['query']['bool']['must'][] = ['match' => [$filter_field => $value]];
        }

        try {
            $response = $client->search($query);
            $results = $this->parseElasticsearchSearchResponse($response);

            return $this->elasticsearchAggregationBuckets($results, 'aggregations', 'time_series', 'buckets');
        } catch (Exception $exception) {
            // Log dell'errore
            Log::error('Elasticsearch time-based metrics query failed', [
                'error' => $exception->getMessage(),
                'date_field' => $date_field,
                'interval' => $interval,
                'index' => $model->searchableAs(),
                'filters' => $filters,
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseElasticsearchSearchResponse(ElasticsearchResponse|Promise $response): array
    {
        if (! $response instanceof ElasticsearchResponse) {
            throw new RuntimeException('Unexpected async Elasticsearch response.');
        }

        return $response->asArray();
    }

    /**
     * @param  array<string, mixed>  $results
     * @return list<array<string, mixed>>
     */
    protected function elasticsearchAggregationBuckets(array $results, string ...$path): array
    {
        $node = $results;

        foreach ($path as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return [];
            }

            $node = $node[$segment];
        }

        if (! is_array($node)) {
            return [];
        }

        $buckets = [];

        foreach ($node as $bucket) {
            if (! is_array($bucket)) {
                continue;
            }

            $buckets[] = $bucket;
        }

        return $buckets;
    }
}

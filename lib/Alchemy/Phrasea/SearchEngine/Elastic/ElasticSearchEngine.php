<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Elastic;

use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\RecordIndexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\TermIndexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Search\SearchQuery;
use Alchemy\Phrasea\SearchEngine\SearchEngineInterface;
use Alchemy\Phrasea\SearchEngine\SearchEngineOptions;
use Alchemy\Phrasea\SearchEngine\SearchEngineResult;
use Alchemy\Phrasea\Exception\RuntimeException;
use Doctrine\Common\Collections\ArrayCollection;
use Alchemy\Phrasea\Model\Entities\FeedEntry;
use Alchemy\Phrasea\Application;
use Elasticsearch\Client;

class ElasticSearchEngine implements SearchEngineInterface
{
    const FLAG_ALLOW_BOTH = 'allow_both';
    const FLAG_SET_ONLY = 'set_only';
    const FLAG_UNSET_ONLY = 'unset_only';

    private $app;
    /** @var Client */
    private $client;
    private $dateFields;
    private $indexName;
    private $configurationPanel;
    private $locales;

    public function __construct(Application $app, Client $client, $indexName)
    {
        $this->app = $app;
        $this->client = $client;
        $this->locales = array_keys($app['locales.available']);

        if ('' === trim($indexName)) {
            throw new \InvalidArgumentException('The provided index name is invalid.');
        }

        $this->indexName = $indexName;
    }

    public function getIndexName()
    {
        return $this->indexName;
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'ElasticSearch';
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus()
    {
        $data = $this->client->info();
        $version = $data['version'];
        unset($data['version']);

        foreach ($version as $prop => $value) {
            $data['version:'.$prop] = $value;
        }

        $ret = [];

        foreach ($data as $key => $value) {
            $ret[] = [$key, $value];
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigurationPanel()
    {
        if (!$this->configurationPanel) {
            $this->configurationPanel = new ConfigurationPanel($this, $this->app['conf']);
        }

        return $this->configurationPanel;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableDateFields()
    {
        if (!$this->dateFields) {
            foreach ($this->app['phraseanet.appbox']->get_databoxes() as $databox) {
                foreach ($databox->get_meta_structure() as $databox_field) {
                    if ($databox_field->get_type() != \databox_field::TYPE_DATE) {
                        continue;
                    }

                    $this->dateFields[] = $databox_field->get_name();
                }
            }

            $this->dateFields = array_unique($this->dateFields);
        }

        return $this->dateFields;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableSort()
    {
        return [
            SearchEngineOptions::SORT_RELEVANCE => $this->app->trans('pertinence'),
            SearchEngineOptions::SORT_CREATED_ON => $this->app->trans('date dajout'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultSort()
    {
        return SearchEngineOptions::SORT_RELEVANCE;
    }

    /**
     * {@inheritdoc}
     */
    public function isStemmingEnabled()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableOrder()
    {
        return [
            SearchEngineOptions::SORT_MODE_DESC => $this->app->trans('descendant'),
            SearchEngineOptions::SORT_MODE_ASC  => $this->app->trans('ascendant'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function hasStemming()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableTypes()
    {
        return [self::GEM_TYPE_RECORD, self::GEM_TYPE_STORY];
    }

    /**
     * {@inheritdoc}
     */
    public function addRecord(\record_adapter $record)
    {
        $this->app['elasticsearch.indexer.record_indexer']->indexSingleRecord($record);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removeRecord(\record_adapter $record)
    {
        $this->doExecute('delete', [
            'index' => $this->indexName,
            'type'  => self::GEM_TYPE_RECORD,
            'id'    => sprintf('%s_%s', $record->get_sbas_id(), $record->get_record_id()), // @todo use RecordHelper::getUniqueRecordId
        ]);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function updateRecord(\record_adapter $record)
    {
        $this->addRecord($record);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addStory(\record_adapter $story)
    {
        $this->addRecord($story);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removeStory(\record_adapter $story)
    {
        $this->removeRecord($story);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function updateStory(\record_adapter $story)
    {
        $this->addRecord($story);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addFeedEntry(FeedEntry $entry)
    {
        throw new RuntimeException('ElasticSearch engine does not support feed entry indexing.');
    }

    /**
     * {@inheritdoc}
     */
    public function removeFeedEntry(FeedEntry $entry)
    {
        throw new RuntimeException('ElasticSearch engine does not support feed entry indexing.');
    }

    /**
     * {@inheritdoc}
     */
    public function updateFeedEntry(FeedEntry $entry)
    {
        throw new RuntimeException('ElasticSearch engine does not support feed entry indexing.');
    }

    /**
     * {@inheritdoc}
     */
    public function query($string, $offset, $perPage, SearchEngineOptions $options = null)
    {
        $options = $options ?: new SearchEngineOptions();

        $searchQuery = $this->app['query_parser']->parse($string);

        // Contains the full thesaurus paths to search on
        $pathsToFilter = [];
        // Contains the thesaurus values by fields (synonyms, translations, etc)
        $collectFields = [];

        // Only search in thesaurus for full text search
        if ($searchQuery->isFullTextOnly()) {
            $termFields = $this->expendToAnalyzedFieldsNames('value', null, $this->app['locale']);
            $termsQuery = $searchQuery->getElasticsearchQuery($termFields);

            $params = $this->createTermQueryParams($termsQuery, $options);
            $terms = $this->doExecute('search', $params);

            foreach ($terms['hits']['hits'] as $term) {
                // Skip paths with very low score
                if ($term['_score'] < 1) {
                    continue;
                }

                $pathsToFilter[$term['_source']['path']] = $term['_score'];

                if (null === $term['_source']['fields']) {
                    continue;
                }

                foreach ($term['_source']['fields'] as $field) {
                    $collectFields['caption.'.$field][] = $term['_source']['value'];
                }
            }
            $pathsToFilter = array_unique($pathsToFilter);
        }

        if (empty($collectFields)) {
            // @todo a list of field by default? all fields?
            $searchFieldNames = ['caption.*'];
        } else {
            $searchFieldNames = array_keys($collectFields);
        }

        $recordFields = $this->expendToAnalyzedFieldsNames($searchFieldNames, null, $this->app['locale']);

        $recordQuery = [
            'bool' => [
                'should' => [
                    $searchQuery->getElasticsearchQuery($recordFields)
                ]
            ]
        ];

        foreach ($pathsToFilter as $path => $score) {
            // Also match incomplete path. /a/b/c will return /a/b/c/d records
            $recordQuery['bool']['should'][] = [
                'match' => [
                    'concept_paths' => array(
                        'query' => $path,
                        'boost' => $score,
                    )
                ]
            ];

            // Add signal for exact path only
            $recordQuery['bool']['should'][] = [
                'term' => [
                    'concept_paths.raw' => array(
                        'value' => $path,
                        'boost' => $score,
                    )
                ]
            ];
        }

        $params = $this->createRecordQueryParams($recordQuery, $options, null);
        $params['body']['from'] = $offset;
        $params['body']['size'] = $perPage;

        // Debug at the moment. See https://phraseanet.atlassian.net/browse/PHRAS-322
        $params['body']['aggs'] = array (
            'Keywords' => array ('terms' =>
                array ('field' => 'caption.Keywords.raw', 'size' => 20),
            ),
            'Photographer' => array ('terms' =>
                array ('field' => 'caption.Photographer.raw', 'size' => 20),
            ),
            'Headline' => array ('terms' =>
                array ('field' => 'caption.Headline.raw', 'size' => 20),
            ),
            'City' => array ('terms' =>
                array ('field' => 'caption.City.raw', 'size' => 20),
            ),
            'Country' => array ('terms' =>
                array ('field' => 'caption.Country.raw', 'size' => 20),
            ),
        );

        $res = $this->doExecute('search', $params);

        $results = new ArrayCollection();
        $suggestions = new ArrayCollection();

        $n = 0;
        foreach ($res['hits']['hits'] as $hit) {
            $results[] = ElasticsearchRecordHydrator::hydrate($hit['_source'], $n++);
        }

        $query['_ast'] = $searchQuery->dump();
        $query['_paths'] = $pathsToFilter;
        $query['_richFields'] = $collectFields;
        $query['query'] = json_encode($params);

        return new SearchEngineResult($results, json_encode($query), $res['took'], $offset,
            $res['hits']['total'], $res['hits']['total'], null, null, $suggestions, [],
            $this->indexName, $res['aggregations']);
    }

    /**
     * {@inheritdoc}
     */
    public function autocomplete($query, SearchEngineOptions $options)
    {
        throw new RuntimeException('Elasticsearch engine currently does not support auto-complete.');
    }

    /**
     * {@inheritdoc}
     */
    public function excerpt($query, $fields, \record_adapter $record, SearchEngineOptions $options = null)
    {
        //@todo implements

        return array();
    }

    /**
     * {@inheritdoc}
     */
    public function resetCache()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function clearCache()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllCache(\DateTime $date = null)
    {
    }

    private function createTermQueryParams($query, SearchEngineOptions $options)
    {
        $params = [
            'index' => $this->indexName,
            'type'  => TermIndexer::TYPE_NAME,
            'body'  => [],
            'size'  => 20,
        ];

        $params['body']['query'] = $query;

        return $params;
    }

    private function createRecordQueryParams($ESQuery, SearchEngineOptions $options, \record_adapter $record = null)
    {
        $params = [
            'index' => $this->indexName,
            'type'  => RecordIndexer::TYPE_NAME,
            'body'  => [
                'sort'   => $this->createSortQueryParams($options),
            ]
        ];

        $query_filters = $this->createQueryFilters($options);
        $acl_filters = $this->createACLFilters();

        $ESQuery = ['filtered' => ['query' => $ESQuery]];

        if (count($query_filters) > 0) {
            $ESQuery['filtered']['filter']['bool']['must'][] = $query_filters;
        }

        if (count($acl_filters) > 0) {
            $ESQuery['filtered']['filter']['bool']['must'][] = $acl_filters;
        }

        $params['body']['query'] = $ESQuery;

        return $params;
    }

    private function createACLFilters()
    {
        // No ACLs if no user
        if (false === $this->app['authentication']->isAuthenticated()) {
            return [];
        }

        $acl = $this->app['acl']->get($this->app['authentication']->getUser());

        $grantedCollections = array_keys($acl->get_granted_base(['actif']));

        if (count($grantedCollections) === 0) {
            return ['bool' => ['must_not' => ['match_all' => new \stdClass()]]];
        }

        $flagNamesMap = $this->getFlagsKey($this->app['phraseanet.appbox']);
        // Get flags rules
        $flagRules = $this->getFlagsRules($acl, $grantedCollections);
        // Get intersection between collection ACLs and collection chosen by end user
        $aclRules = $this->getACLsByCollection($flagRules, $flagNamesMap);

        return $this->buildACLsFilters($aclRules);
    }

    private function createQueryFilters(SearchEngineOptions $options)
    {
        $filters = [];

        $filters[]['term']['record_type'] = $options->getSearchType() === SearchEngineOptions::RECORD_RECORD ?
                    SearchEngineInterface::GEM_TYPE_RECORD : SearchEngineInterface::GEM_TYPE_STORY;

        if ($options->getDateFields() && ($options->getMaxDate() || $options->getMinDate())) {
            $range = [];
            if ($options->getMaxDate()) {
                $range['lte'] = $options->getMaxDate()->format(Mapping::DATE_FORMAT_CAPTION_PHP);
            }
            if ($options->getMinDate()) {
                $range['gte'] = $options->getMinDate()->format(Mapping::DATE_FORMAT_CAPTION_PHP);
            }

            foreach ($options->getDateFields() as $dateField) {
                $filters[]['range']['caption.'.$dateField->get_name()] = $range;
            }
        }

        if ($options->getRecordType()) {
            $filters[]['term']['phrasea_type'] = $options->getRecordType();
        }

        if (count($options->getCollections()) > 0) {
            $filters[]['terms']['base_id'] = array_map(function($collection) {
                return $collection->get_base_id();
            }, $options->getCollections());
        }

        if (count($options->getStatus()) > 0) {
            $status_filters = [];
            $flagNamesMap = $this->getFlagsKey($this->app['phraseanet.appbox']);

            foreach ($options->getStatus() as $databoxId => $status) {
                $status_filter = $databox_status =[];
                $status_filter[] = ['term' => ['databox_id' => $databoxId]];
                foreach ($status as $n => $v) {
                    if (!isset($flagNamesMap[$databoxId][$n])) {
                        continue;
                    }

                    $label = $flagNamesMap[$databoxId][$n];
                    $databox_status[] = ['term' => [sprintf('flags.%s', $label) => (bool) $v]];
                };
                $status_filter[] = $databox_status;

                $status_filters[] = ['bool' => ['must' => $status_filter]];
            }
            $filters[]['bool']['should'] = $status_filters;
        }

        return $filters;
    }

    private function createSortQueryParams(SearchEngineOptions $options)
    {
        $sort = [];
        if ($options->getSortBy() === SearchEngineOptions::SORT_RELEVANCE) {
            $sort['_score'] = $options->getSortOrder();
        } else {
            $sort['created_on'] = $options->getSortOrder();
        }

        return $sort;
    }

    private function doExecute($method, array $params)
    {
        $res = call_user_func([$this->client, $method], $params);

        if (isset($res['error'])) {
            throw new RuntimeException('Unable to execute method '.$method);
        }

        return $res;
    }

    /**
     * @param array|string $fields
     * @param array|null $locales
     * @param null $currentLocale
     * @return array
     */
    public function expendToAnalyzedFieldsNames($fields, $locales = null, $currentLocale = null)
    {
        $fieldsExpended = [];

        if (!$locales) {
            $locales = $this->locales;
        }

        foreach ((array) $fields as $field) {
            foreach ($locales as $locale) {
                $boost = "";

                if ($locale === $currentLocale) {
                    $boost = "^5";
                }

                $fieldsExpended[] = sprintf('%s.%s%s', $field, $locale, $boost);
            }
            $fieldsExpended[] = sprintf('%s.%s', $field, 'light^10');
        }

        return $fieldsExpended;
    }

    private function getFlagsKey(\appbox $appbox)
    {
        $flags = [];
        foreach ($appbox->get_databoxes() as $databox) {
            $databoxId = $databox->get_sbas_id();
            $status = $databox->get_statusbits();
            foreach($status as $bit => $stat) {
                $flags[$databoxId][$bit] = RecordHelper::normalizeFlagKey($stat['labelon']);
            }
        }

        return $flags;
    }

    private function getFlagsRules(\ACL $acl, array $collections)
    {
        $rules = [];
        foreach ($collections as $collectionId) {
            $databoxId = \phrasea::sbasFromBas($this->app, $collectionId);
            foreach (range(0, 31) as $bit) {
                $rules[$databoxId][$collectionId][$bit] = $this->computeAccess(
                    $acl->get_mask_xor($collectionId),
                    $acl->get_mask_and($collectionId),
                    $bit
                );
            }
        }

        return $rules;
    }

    /**
     *    Truth table for status rights
     *
     *    +-----------+
     *    | and | xor |
     *    +-----------+
     *    |  0  |  0  | -> BOTH STATES ARE CHECKED
     *    +-----------+
     *    |  1  |  0  | -> UNSET STATE IS CHECKED
     *    +-----------+
     *    |  0  |  1  | -> UNSET STATE IS CHECKED (not possible)
     *    +-----------+
     *    |  1  |  1  | -> SET STATE IS CHECKED
     *    +-----------+
     *
     */
    private function computeAccess($and, $xor, $bit)
    {
        $xorBit = \databox_status::bitIsSet($xor, $bit);
        $andBit = \databox_status::bitIsSet($and, $bit);

        if (!$xorBit && !$andBit) {
            return self::FLAG_ALLOW_BOTH;
        }

        if ($xorBit && $andBit) {
            return self::FLAG_SET_ONLY;
        }

        return self::FLAG_UNSET_ONLY;
    }

    private function getACLsByCollection(array $flagACLs, array $flagNamesMap)
    {
        $rules = [];

        foreach ($flagACLs as $databoxId => $bases) {
            foreach ($bases as $baseId => $bit) {
                $rules[$baseId] = [];
                foreach ($bit as $n => $rule) {
                    if (!isset($flagNamesMap[$databoxId][$n])) {
                        continue;
                    }

                    $label = $flagNamesMap[$databoxId][$n];
                    $rules[$baseId][$label] = $rule;
                }
            }
        }

        return $rules;
    }

    private function buildACLsFilters(array $aclRules)
    {
        $filters = [];

        foreach ($aclRules as $baseId => $flagsRules) {
            $ruleFilter = $baseFilter = [];

            // filter on base
            $baseFilter['term']['base_id'] = $baseId;
            $ruleFilter['bool']['must'][] = $baseFilter;

            // filter by flags
            foreach ($flagsRules as $flagName => $flagRule) {
                // only add filter if one of the status state is not allowed / allowed
                if ($flagRule === self::FLAG_ALLOW_BOTH) {
                    continue;
                }
                $flagFilter = [];

                $flagField = sprintf('flags.%s', $flagName);
                $flagFilter['term'][$flagField] = $flagRule === self::FLAG_SET_ONLY ? true : false;

                $ruleFilter['bool']['must'][] = $flagFilter;
            }

            $filters[] = $ruleFilter;
        }

        return ['bool' => ['should' => $filters]];
    }
}

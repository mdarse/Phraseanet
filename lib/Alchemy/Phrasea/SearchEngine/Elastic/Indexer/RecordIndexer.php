<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Elastic\Indexer;

use Alchemy\Phrasea\SearchEngine\Elastic\BulkOperation;
use Alchemy\Phrasea\SearchEngine\Elastic\ElasticSearchEngine;
use Alchemy\Phrasea\SearchEngine\Elastic\Exception\Exception;
use Alchemy\Phrasea\SearchEngine\Elastic\Exception\MergeException;
use Alchemy\Phrasea\SearchEngine\Elastic\Fetcher\AbstractRecordFetcher;
use Alchemy\Phrasea\SearchEngine\Elastic\Fetcher\RecordPoolFetcher;
use Alchemy\Phrasea\SearchEngine\Elastic\Fetcher\SingleRecordFetcher;
use Alchemy\Phrasea\SearchEngine\Elastic\Mapping;
use Alchemy\Phrasea\SearchEngine\Elastic\Fetcher\RecordFetcher;
use Alchemy\Phrasea\SearchEngine\Elastic\RecordHelper;
use Alchemy\Phrasea\SearchEngine\Elastic\StringUtils;
use Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus;
use Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus\Helper as ThesaurusHelper;
use media_subdef;

class RecordIndexer
{
    const TYPE_NAME = 'record';

    /**
     * @var \appbox
     */
    private $appbox;

    /**
     * @var \Alchemy\Phrasea\SearchEngine\Elastic\ElasticSearchEngine
     */
    private $elasticSearchEngine;

    /**
     * @var array
     */
    private $locales;

    /**
     * @note this value is lazy loaded
     *
     * @var array
     */
    private $dataStructure;

    public function __construct(Thesaurus $thesaurus, ElasticSearchEngine $elasticSearchEngine, \appbox $appbox, array $locales)
    {
        $this->thesaurus = $thesaurus;
        $this->appbox = $appbox;
        $this->elasticSearchEngine = $elasticSearchEngine;
        $this->locales = $locales;
    }

    public function populateIndex(BulkOperation $bulk)
    {
        $recordHelper = new RecordHelper($this->appbox);

        foreach ($this->appbox->get_databoxes() as $databox) {
            $fetcher = new RecordFetcher($databox, $recordHelper);
            $fetcher->setBatchSize(200);
            while ($records = $fetcher->fetch()) {
                foreach ($records as $record) {
                    $params = array();
                    $params['id'] = $record['id'];
                    $params['type'] = self::TYPE_NAME;
                    $params['index'] = $this->elasticSearchEngine->getIndexName();
                    $params['body'] = $this->transform($record);
                    $bulk->index($params);
                }
            }
        }
    }

    public function index(BulkOperation $bulk, AbstractRecordFetcher $fetcher)
    {
        while ($records = $fetcher->fetch()) {
            foreach ($records as $record) {
                $params = array();
                // header
                $params['id'] = $record['id'];
                $params['type'] = self::TYPE_NAME;
                $params['index'] = $this->elasticSearchEngine->getIndexName();
                // body
                $params['body'] = $this->transform($record);
                $bulk->index($params);
            }
        }
        $bulk->flush();
    }

    public function update(BulkOperation $bulk, AbstractRecordFetcher $fetcher)
    {
        while ($records = $fetcher->fetch()) {
            foreach ($records as $record) {
                $params = array();
                // header
                $params['id'] = $record['id'];
                $params['type'] = self::TYPE_NAME;
                $params['index'] = $this->elasticSearchEngine->getIndexName();
                // doc
                $params['doc'] = $this->transform($record);
                $bulk->update($params);
            }
        }
        $bulk->flush();
    }

    public function delete(BulkOperation $bulk, AbstractRecordFetcher $fetcher)
    {
        while ($records = $fetcher->fetch()) {
            foreach ($records as $record) {
                $params = array();
                // header
                $params['id'] = $record['id'];
                $params['type'] = self::TYPE_NAME;
                $params['index'] = $this->elasticSearchEngine->getIndexName();

                $bulk->delete($params);
            }
        }
        $bulk->flush();
    }

    private function findLinkedConcepts($structure, array $record)
    {
        $client = $this->elasticSearchEngine->getClient();
        $searchParams['index'] = $this->elasticSearchEngine->getIndexName();
        $searchParams['type']  = TermIndexer::TYPE_NAME;
        $shoulds = [];
        $paths   = [];

        foreach ($structure as $field => $options) {
            // @todo is thesaurus_concept_inference the right option?
            if (isset($record['caption'][$field]) && $options['thesaurus_concept_inference']) {
                $shoulds[] = ["multi_match" => [
                    'fields' => $this->elasticSearchEngine->expendToAnalyzedFieldsNames(array('value', 'context')),
                    'query'  =>
                        is_string($record['caption'][$field])
                            ? mb_substr($record['caption'][$field], 0, 120) // Cut short to avoid maxClauseCount
                            : implode(' ', $record['caption'][$field]),
                    'operator' => is_array($record['caption'][$field]) ? 'or' : 'and',
                ]];
            }
        }

        if (empty($shoulds)) {
            return [];
        }

        $searchParams['body']['query']['filtered']['query'] = array('bool' => array('should' => $shoulds));

        // Only search in the databox of the record itself
        $searchParams['body']['query']['filtered']['filter'] = array('term' => array('databox_id' => $record['databox_id']));
        $searchParams['body']['size'] = 20;
        $searchParams['body']['fields'] = ['path'];

        $queryResponse = $client->search($searchParams);

        foreach ($queryResponse['hits']['hits'] as $hit) {
            foreach ($hit['fields']['path'] as $path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    public function getMapping()
    {
        $mapping = new Mapping();
        $mapping
            // Identifiers
            ->add('record_id', 'integer')  // Compound primary key
            ->add('databox_id', 'integer') // Compound primary key
            ->add('base_id', 'integer') // Unique collection ID
            ->add('collection_id', 'integer') // Useless collection ID (local to databox)
            ->add('collection_name', 'string')->notAnalyzed() // Collection name
            ->add('uuid', 'string')->notAnalyzed()
            ->add('sha256', 'string')->notAnalyzed()
            // Mandatory metadata
            ->add('original_name', 'string')->notAnalyzed()
            ->add('mime', 'string')->notAnalyzed()
            ->add('type', 'string')->notAnalyzed()
            ->add('record_type', 'string')->notAnalyzed() // record or story
            // Dates
            ->add('created_on', 'date')->format(Mapping::DATE_FORMAT_MYSQL)
            ->add('updated_on', 'date')->format(Mapping::DATE_FORMAT_MYSQL)
            // Inferred thesaurus concepts
            ->add('concept_paths', 'string')
                ->analyzer('thesaurus_path', 'indexing')
                ->analyzer('keyword', 'searching')
                ->addRawVersion()
        ;

        // Index title
        $titleMapping = new Mapping();
        $titleMapping->add('default', 'string')->notAnalyzed()->notIndexed();
        foreach ($this->locales as $locale) {
            $titleMapping->add($locale, 'string')->notAnalyzed()->notIndexed();
        }
        $mapping->add('title', $titleMapping);

        // Minimal subdefs mapping info for display purpose
        $subdefMapping = new Mapping();
        $subdefMapping->add('path', 'string')->notAnalyzed()->notIndexed();
        $subdefMapping->add('height', 'integer')->notIndexed();
        $subdefMapping->add('width', 'integer')->notIndexed();

        $subdefsMapping = new Mapping();
        $subdefsMapping->add('thumbnail', $subdefMapping);
        $subdefsMapping->add('thumbnailgif', $subdefMapping);
        $subdefsMapping->add('preview', $subdefMapping);

        $mapping->add('subdefs', $subdefsMapping);

        // Caption mapping
        $captionMapping = new Mapping();
        $mapping->add('caption', $captionMapping);
        $privateCaptionMapping = new Mapping();
        $mapping->add('private_caption', $privateCaptionMapping);
        foreach ($this->getFieldsStructure() as $name => $params) {
            $m = $params['private'] ? $privateCaptionMapping : $captionMapping;
            $m->add($name, $params['type']);

            if ($params['type'] === Mapping::TYPE_DATE) {
                $m->format(Mapping::DATE_FORMAT_CAPTION);
            }

            if ($params['type'] === Mapping::TYPE_STRING) {
                if (!$params['indexable'] && !$params['to_aggregate']) {
                    $m->notIndexed();
                } elseif (!$params['indexable'] && $params['to_aggregate']) {
                    $m->notAnalyzed();
                    $m->addRawVersion();
                } else {
                    $m->addRawVersion();
                    $m->addAnalyzedVersion($this->locales);
                }
            }
        }

        // EXIF
        $mapping->add('exif', $this->getExifMapping());

        // Status
        $mapping->add('flags', $this->getFlagsMapping());

        return $mapping->export();
    }


    private function getFieldsStructure()
    {
        if (!empty($this->dataStructure)) {
            return $this->dataStructure;
        }

        $fields = array();

        foreach ($this->appbox->get_databoxes() as $databox) {
            //printf("Databox %d\n", $databox->get_sbas_id());
            foreach ($databox->get_meta_structure() as $fieldStructure) {
                $field = array();
                // Field type
                switch ($fieldStructure->get_type()) {
                    case \databox_field::TYPE_DATE:
                        $field['type'] = 'date';
                        break;
                    case \databox_field::TYPE_NUMBER:
                        $field['type'] = 'double';
                        break;
                    case \databox_field::TYPE_STRING:
                    case \databox_field::TYPE_TEXT:
                        $field['type'] = 'string';
                        break;
                    default:
                        throw new Exception(sprintf('Invalid field type "%s", expected "date", "number" or "string".', $fieldStructure->get_type()));
                        break;
                }

                $name = $fieldStructure->get_name();

                // Business rules
                $field['private'] = $fieldStructure->isBusiness();
                $field['indexable'] = $fieldStructure->is_indexable();
                $field['to_aggregate'] = (bool) $fieldStructure->isAggregable();

                // Thesaurus concept inference
                // $xpath = "/thesaurus/te[@id='T26'] | /thesaurus/te[@id='T24']";
                $helper = new ThesaurusHelper();

                // TODO Not the real option yet
                $field['thesaurus_concept_inference'] = $field['type'] === 'string';
                // TODO Find thesaurus path prefixes
                $field['thesaurus_prefix'] = '/categories';

                //printf("Field \"%s\" <%s> (private: %b)\n", $name, $field['type'], $field['private']);

                // Since mapping is merged between databoxes, two fields may
                // have conflicting names. Indexing is the same for a given
                // type so we reject only those with different types.
                if (isset($fields[$name])) {
                    if ($fields[$name]['type'] !== $field['type']) {
                        throw new MergeException(sprintf("Field %s can't be merged, incompatible types (%s vs %s)", $name, $fields[$name]['type'], $field['type']));
                    }

                    if ($fields[$name]['indexable'] !== $field['indexable']) {
                        throw new MergeException(sprintf("Field %s can't be merged, incompatible indexable state", $name));
                    }

                    if ($fields[$name]['to_aggregate'] !== $field['to_aggregate']) {
                        throw new MergeException(sprintf("Field %s can't be merged, incompatible to_aggregate state", $name));
                    }
                    // TODO other structure incompatibilities

                    //printf("Merged with previous \"%s\" field\n", $name);
                }

                $fields[$name] = $field;
            }
        }

        $this->dataStructure = $fields;
        return $this->dataStructure;
    }

    // @todo Add call to addAnalyzedVersion ?
    private function getExifMapping()
    {
        $mapping = new Mapping();
        $mapping
            ->add(media_subdef::TC_DATA_WIDTH, 'integer')
            ->add(media_subdef::TC_DATA_HEIGHT, 'integer')
            ->add(media_subdef::TC_DATA_COLORSPACE, 'string')->notAnalyzed()
            ->add(media_subdef::TC_DATA_CHANNELS, 'integer')
            ->add(media_subdef::TC_DATA_ORIENTATION, 'integer')
            ->add(media_subdef::TC_DATA_COLORDEPTH, 'integer')
            ->add(media_subdef::TC_DATA_DURATION, 'float')
            ->add(media_subdef::TC_DATA_AUDIOCODEC, 'string')->notAnalyzed()
            ->add(media_subdef::TC_DATA_AUDIOSAMPLERATE, 'integer')
            ->add(media_subdef::TC_DATA_VIDEOCODEC, 'string')->notAnalyzed()
            ->add(media_subdef::TC_DATA_FRAMERATE, 'float')
            ->add(media_subdef::TC_DATA_MIMETYPE, 'string')->notAnalyzed()
            ->add(media_subdef::TC_DATA_FILESIZE, 'long')
            // TODO use geo point type for lat/long
            ->add(media_subdef::TC_DATA_LONGITUDE, 'float')
            ->add(media_subdef::TC_DATA_LATITUDE, 'float')
            ->add(media_subdef::TC_DATA_FOCALLENGTH, 'float')
            ->add(media_subdef::TC_DATA_CAMERAMODEL, 'string')
            ->add(media_subdef::TC_DATA_FLASHFIRED, 'boolean')
            ->add(media_subdef::TC_DATA_APERTURE, 'float')
            ->add(media_subdef::TC_DATA_SHUTTERSPEED, 'float')
            ->add(media_subdef::TC_DATA_HYPERFOCALDISTANCE, 'float')
            ->add(media_subdef::TC_DATA_ISO, 'integer')
            ->add(media_subdef::TC_DATA_LIGHTVALUE, 'float')
        ;

        return $mapping;
    }

    private function getFlagsMapping()
    {
        $mapping = new Mapping();

        foreach ($this->appbox->get_databoxes() as $databox) {
            foreach ($databox->get_statusbits() as $bit => $status) {
                $key = RecordHelper::normalizeFlagKey($status['labelon']);
                // We only add to mapping new statuses
                if (!$mapping->has($key)) {
                    $mapping->add($key, 'boolean');
                }
            }
        }

        return $mapping;
    }

    /**
     * Inspired by ESRecordSerializer
     *
     * @todo complete, with all the other transformations
     * @param $record
     */
    private function transform($record)
    {
        $dateFields = $this->elasticSearchEngine->getAvailableDateFields();

        $structure = $this->getFieldsStructure();
        $databox = $this->appbox->get_databox($record['databox_id']);

        foreach ($databox->get_statusbits() as $bit => $status) {
            $key = RecordHelper::normalizeFlagKey($status['labelon']);

            $record['flags'][$key] = \databox_status::bitIsSet($record['flags_bitmask'], $bit);
        }

        foreach ($dateFields as $field) {
            if (!isset($record['caption'][$field])) {
                continue;
            }

            try {
                $date = new \DateTime($record['caption'][$field]);
                $record['caption'][$field] = $date->format(Mapping::DATE_FORMAT_CAPTION_PHP);
            } catch (\Exception $e) {
                $record['caption'][$field] = null;
                continue;
            }
        }

        $record['concept_paths'] = $this->findLinkedConcepts($structure, $record);

        return $record;
    }
}

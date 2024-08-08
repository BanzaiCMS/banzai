<?php
declare(strict_types=1);

namespace Banzai\Search;

use Elasticsearch\ClientBuilder;
use Elasticsearch\Client;
use Flux\Logger\LoggerInterface;
use Flux\Config\Config;
use Banzai\Domain\Articles\ArticlesGateway;


class ElasticService
{
    const TYPE_ARTICLE = 'article';

    protected ?array $config = null;
    public ?Client $client = null;

    protected string $index = 'cms';

    public function __construct(protected LoggerInterface $logger, protected Config $params, protected ArticlesGateway $articles)
    {
        $this->loadConfigFromFile();

        if (empty($this->config))
            return;

        if (!empty($this->config['index']))
            $this->index = $this->config['index'];

        $this->client = ClientBuilder::create()->setHosts($this->config['hosts'])->build();

    }

    private function loadConfigFromFile()
    {

        $filename = $this->params->get('path.config') . 'elastic.json';

        if (empty($filename))
            return;

        if (!file_exists($filename))
            return;

        $content = file_get_contents($filename);
        $data = json_decode($content, true);

        if (empty($data))
            return;
        $this->config = $data;

    }

    public function indexDocument(string $id, array $body, ?string $index, string $type = self::TYPE_ARTICLE)
    {
        if (empty($index))
            $index = $this->index;

        $params = array(
            'index' => $index,
            'type' => $type,
            'id' => $id,
            'body' => $body
        );

        $this->client->index($params);
    }

    public function indexArticle(int $id, array $body)
    {
        $params = array(
            'index' => $this->index,
            'type' => self::TYPE_ARTICLE,
            'id' => $id,
            'body' => $body
        );

        $this->client->index($params);

    }

    public function search(string $field, string $search, ?string $index): array
    {
        if (is_null($this->client))
            return array();

        if (empty($index))
            $index = $this->$index;

        $params = [
            'index' => $index,
            'body' => [
                'query' => [
                    'match' => [
                        $field => $search
                    ]
                ]
            ]
        ];


        return $this->client->search($params);

    }

    public function query(string $query, int $limit = 10): array
    {
        if (is_null($this->client))
            return array();

        $params = [
            'index' => $this->index,
            'size' => $limit,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            'multi_match' => [
                                'query' => $query,
                                'type' => 'phrase_prefix',
                                'cutoff_frequency' => '0.0007',
                                'operator' => 'or',
                                'fields' => ["Title", "ContentText", "Path", "Description"]
                            ]
                        ]
                    ]
                ]
            ]
        ];


        $response = $this->client->search($params);

        if ($response['hits']['total']['value'] >= 1)
            return $response;


        // nothing found we are trying again with fuzzy search

        $params = [
            'index' => $this->index,
            'size' => $limit,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            'multi_match' => [
                                'query' => $query,
                                'type' => 'best_fields',
                                'cutoff_frequency' => '0.0007',
                                'operator' => 'or',
                                'fields' => ["Path^5", "Title^3", "Description^2", "ContentText^2"],
                                "fuzziness" => "AUTO"
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->client->search($params);

        if ($response['hits']['total']['value'] >= 1)
            return $response;
        else
            return array();

    }

    public function processQueryResponse(array $response): string
    {

        if ($response['hits']['total']['value'] < 1) {
            return '';
        }

        $hits = $response['hits']['hits'];
        $ret = '';

        foreach ($hits as $entry) {
            $title = $entry['_source']['Title'];
            $url = $entry['_source']['Path'];
            $description = $entry['_source']['ContentText'];

            $title = mb_strimwidth($title, 0, 60, '...');
            $url = mb_strimwidth($url, 0, 60, '...');
            $description = mb_strimwidth($description, 0, 160, '...');

            $ret .= $title . "\n" . $description . "\n" . $url . "\n\n";
        }

        return $ret;
    }

    public function mapArticleToSearch(array $art): array
    {

        $ret = array();
        $ret['Title'] = $art['titel2'];
        $ret['Path'] = $art['fullurl'];
        $ret['Created'] = $art['verfassdat'];
        $ret['Modified'] = $art['lastchange'];
        $ret['ContentHTML'] = $art['langtext'];
        $ret['ContentText'] = strip_tags($art['langtext']);

        $ret['Description'] = $art['description'];
        return $ret;
    }

    public function indexAllArticles()
    {
        // first we delete the index if it does not exist
        if ($this->client->indices()->exists(['index' => $this->index]))
            $this->client->indices()->delete(['index' => $this->index]);

        $list = $this->articles->getTeaserlist(null, array(), 10, 'all');
        foreach ($list as $art) {
            $data = $this->mapArticleToSearch($art);
            $this->indexArticle($art['article_id'], $data);
        }

        // print_r($list);
        exit(0);
    }
}

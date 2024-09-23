<?php
declare(strict_types=1);

namespace Banzai\Domain\Blocks;

use function json_decode;
use Twig\Environment as TwigEnvironment;
use Flux\Database\DatabaseInterface;
use Flux\Events\EventDispatcherInterface;
use Flux\Logger\LoggerInterface;
use Flux\Config\Config;
use Banzai\Domain\Users\User;
use Banzai\Http\KernelEvents;


class BlocksGateway implements BlocksInterface
{
    const   string BLOCKS_TABLE = 'content_blocks';

    protected array $Config = array();

    protected array $Templates = array();

    protected bool $NoConfig = true;

    public function __construct(protected EventDispatcherInterface $dispatcher,
                                protected DatabaseInterface        $db,
                                protected LoggerInterface          $logger,
                                protected Config                   $params,
                                protected TwigEnvironment          $twig,
                                protected User                     $user
    )
    {


    }

    protected function checkConfig(): void
    {
        if ($this->NoConfig) {
            $this->Config = $this->params->loadConfig('blocks');
            $this->NoConfig = false;

            foreach ($this->Config as $type => $data)
                $this->Templates[$type] = $data['template'];

        }


    }

    public function hasBlocksConfig(): bool
    {
        return $this->params->ConfigFileExists('blocks');
    }

    public function getBlockConfig(): array
    {
        $this->checkConfig();
        return $this->Config;
    }

    public function getBlocktypeNames(): array
    {
        $this->checkConfig();

        $erg = array();
        foreach ($this->Config as $type => $data)
            $erg[$type] = $data['name'];
        return $erg;
    }

    public function getBlocktype($blocktype): array
    {
        $this->checkConfig();

        return $this->Config[$blocktype] ?? array();

    }

    public function getTemplateName($blocktype): string
    {
        $this->checkConfig();

        return $this->Templates[$blocktype] ?? '';
    }

    public function getNodeBlockNames(): array
    {
        $this->checkConfig();

        $sql = 'SELECT block_id,block_name FROM ' . self::BLOCKS_TABLE . ' WHERE block_children_allowed="yes"';
        return $this->db->getlist($sql, null, 'block_id', 'block_name');
    }


    public function createBlocksSubTree(int $ArticleID = 0, int $BlockID = 0, bool $OnlyPublished = true): array
    {
        $bind = array();

        // if we have a parent block, we ignore the article
        if ($BlockID > 0) {
            $sql = 'SELECT * FROM ' . self::BLOCKS_TABLE . ' WHERE parent_block_id=:blockid';
            $bind['blockid'] = $BlockID;
        } else {
            $sql = 'SELECT * FROM ' . self::BLOCKS_TABLE . ' WHERE (article_id=:articleid OR (article_id=0 AND parent_block_id=0))';
            $bind['articleid'] = $ArticleID;
        }

        if ($OnlyPublished)
            $sql .= ' AND block_isactive="yes"';

        $sql .= ' ORDER BY block_order';
        $liste = $this->db->getlist($sql, $bind);

        if (empty($liste))
            return array();

        $ret = array();

        $count = 1;

        foreach ($liste as $entry) {

            // ignore this entry if we do not have sufficient permission
            if (!empty($entry['block_permcode']))
                if (!$this->user->hasPermission($entry['permcode']))
                    continue;

            if (!empty($entry['block_data']))
                $entry['block_data'] = json_decode($entry['block_data'], true);

            $entry['position'] = $count++;

            $entry['config'] = $this->getBlocktype($entry['block_code']);
            $entry['block_template'] = $this->getTemplateName($entry['block_code']);

            if (!empty($entry['config']['onPostResponseEvent'])) {
                $callb = new $entry['config']['onPostResponseEvent']($entry);
                $this->dispatcher->addListener(KernelEvents::POSTRESPONSE, $callb);
            }

            $subelements = $this->createBlocksSubTree($ArticleID, $entry['block_id'], OnlyPublished: $OnlyPublished);
            if (!empty($subelements))
                $entry['children'] = $subelements;

            $ret[] = $entry;

        }

        return $ret;
    }

    public function createBlocksTree(int $ArticleID, bool $OnlyPublished = true, bool $GroupedAreas = true): array
    {
        $tree = $this->createBlocksSubTree($ArticleID, OnlyPublished: $OnlyPublished);

        if (empty($tree) || (!$GroupedAreas))
            return $tree;

        $ret = array();

        foreach ($tree as $entry) {
            $group = $entry['block_area'];
            if (empty($group))
                $ret['default'][] = $entry;
            else
                $ret[$group][] = $entry;

        }
        return $ret;

    }

    public function renderBlocksTree(TwigEnvironment $twig, array $data, array $blocks): array
    {
        $newblocks = array();

        if (empty($blocks))
            return array();

        foreach ($blocks as $entry) {
            if (!empty($entry['children'])) {
                $entry['children'] = $this->renderBlocksTree($twig, $data, $entry['children']);
            }

            $data['block'] = $entry;    // to be able to access all response data from within template

            $template = $this->twig->load($entry['block_template']);
            $entry['content'] = $template->render($data);
            $newblocks[] = $entry;
        }
        return $newblocks;
    }


}

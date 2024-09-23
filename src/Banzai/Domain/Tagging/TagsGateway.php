<?php

namespace Banzai\Domain\Tagging;

use Flux\Logger\LoggerInterface;
use Flux\Database\DatabaseInterface;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Products\ProductsGateway;
use Banzai\Domain\Customers\CustomersGateway;

class TagsGateway
{
    const string TAGGING_TABLE = 'taggings';
    const string TAGLIST_TABLE = 'taglist';

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {
    }

    public function rebuild_taglist(): void
    {

        $this->db->statement('TRUNCATE ' . self::TAGLIST_TABLE);

        $this->db->statement(self::TAGGING_TABLE);

        $liste = $this->db->getlist('SELECT article_id,keywords FROM ' . ArticlesGateway::ART_TABLE . ' WHERE keywords<>""');
        foreach ($liste as $art)
            $this->updatetags('article', $art['article_id'], $art['keywords']);

        $liste = $this->db->getlist('SELECT products_id,keywords FROM ' . ProductsGateway::PRODUCTS_TABLE . ' WHERE keywords<>""');
        foreach ($liste as $prod)
            $this->updatetags('products', $prod['products_id'], $prod['keywords']);

        $liste = $this->db->getlist('SELECT products_id,addon_tags FROM ' . ProductsGateway::PRODUCTS_TABLE . ' WHERE addon_tags<>""');
        foreach ($liste as $prod)
            $this->updatetags('paddon', $prod['products_id'], $prod['addon_tags']);

        $liste = $this->db->getlist('SELECT adr_id,adr_keywords FROM ' . CustomersGateway::CUSTOMER_TABLE . ' WHERE adr_type="customer" AND adr_keywords<>""');
        foreach ($liste as $cust)
            $this->updatetags('customer', $cust['adr_id'], $cust['adr_keywords']);

    }

    public function gettags(int $objid, string $objclass = 'article'): array
    {
        return $this->db->getlist('SELECT l.tagname FROM ' . self::TAGLIST_TABLE . ' l JOIN ' . self::TAGGING_TABLE . ' t ON t.tagnameid=l.tagnameid ' . ' WHERE l.objclass=? AND t.objid=?', array($objclass, $objid));

    }

    public function hastag(int $objid = 0, string $tag = '', string $objclass = 'article'): bool
    {

        $data = $this->db->get('SELECT l.tagname FROM ' . self::TAGLIST_TABLE . ' l JOIN ' . self::TAGGING_TABLE . ' t ON t.tagnameid=l.tagnameid ' . ' WHERE l.objclass=? AND t.objid=? AND l.tagname=?', array($objclass, $objid, $tag));

        return !empty($data);

    }

    public function toString(array $tags = array()): string
    {
        if (empty($tags))
            return '';

        $sep = '';
        $ret = '';

        foreach ($tags as $tag) {
            if (!empty($tag))
                if (!empty($tag['tagname'])) {
                    $ret .= $sep . $tag['tagname'];
                    if (empty($sep))
                        $sep = ';';
                }
        }

        return $ret;
    }

    public function addtag(int $objid = 0, string $tag = '', string $objclass = 'article', string $tagstr = ''): bool
    {

        if (empty($tag) || empty($objclass))
            return false;

        $tagentry = $this->db->get('SELECT * FROM ' . self::TAGLIST_TABLE . ' WHERE objclass=? AND tagname=?', array($objclass, $tag));

        if (empty($tagentry)) {
            $data = array();
            $data['tagcount'] = 1;
            $data['tagname'] = $tag;
            $data['visname'] = $tagstr;
            $data['objclass'] = $objclass;
            $data['created'] = $this->db->timestamp();
            $tagnameid = $this->db->add(self::TAGLIST_TABLE, $data);
        } else {
            $tagnameid = $tagentry['tagnameid'];
            $data = array();
            $data['tagcount'] = $tagentry['tagcount'] + 1;
            $data['tagnameid'] = $tagentry['tagnameid'];
            $this->db->put(self::TAGLIST_TABLE, $data, array('tagnameid'), false);
        }

        if ($tagnameid < 1)
            return false;

        $data = array();
        $data['created'] = $this->db->timestamp();
        $data['objclass'] = $objclass;
        $data['objid'] = $objid;
        $data['tagnameid'] = $tagnameid;
        if ($this->db->add(self::TAGGING_TABLE, $data) > 0)
            return true;
        else
            return false;
    }


    public function updatetagname(string $tag, string $objclass = 'article', string $tagstr = ''): void
    {

        $tagentry = $this->db->get('SELECT tagnameid,visname FROM ' . self::TAGLIST_TABLE . ' WHERE objclass=? AND tagname=?', array($objclass, $tag));

        if (empty($tagentry))
            return;

        if ($tagentry['visname'] == $tagstr)
            return;

        $data = array();
        $data['visname'] = $tagstr;
        $data['tagnameid'] = $tagentry['tagnameid'];
        $this->db->put(self::TAGLIST_TABLE, $data, array('tagnameid'), false);
    }


    public function deletetag(int $objid, string $tag, string $objclass = 'article'): bool
    {


        $tagentry = $this->db->get('SELECT tagnameid,visname,tagcount FROM ' . self::TAGLIST_TABLE . ' WHERE objclass=? AND tagname=?', array($objclass, $tag));

        if (empty($tagentry))
            return false;

        if ($tagentry['tagcount'] > 1) {
            $data = array();
            $data['tagnameid'] = $tagentry['tagnameid'];
            $data['tagcount'] = $tagentry['tagcount'] - 1;
            $this->db->put(self::TAGLIST_TABLE, $data, array('tagnameid'), false);
        } else {
            $data = array();
            $data['tagnameid'] = $tagentry['tagnameid'];
            $this->db->del(self::TAGLIST_TABLE, $data);
        }


        $data = array();
        $data['objclass'] = $objclass;
        $data['objid'] = $objid;
        $data['tagnameid'] = $tagentry['tagnameid'];
        $this->db->del(self::TAGGING_TABLE, $data);

        return true;
    }

    public function updatetags(string $objclass, int $objid, $tags): void
    {

        $dellist = array();
        $addlist = array();

        if (!is_array($tags)) {
            $tags = str_replace(';', ',', $tags);
            $tags = explode(',', $tags);
        }

        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (!empty($tag)) {
                $tagidx = strtolower($tag);
                $tagidx = str_replace("'", '', $tagidx);
                $tagidx = str_replace('"', '', $tagidx);
                $tagidx = str_replace("\\", '', $tagidx);

                // Semikolon and comma should not be in Tags
                $tagidx = str_replace(';', '', $tagidx);
                $tagidx = str_replace(",", '', $tagidx);

                if (!empty($tagidx))
                    $addlist[$tagidx] = $tag;
            }
        }

        $oldtags = $this->gettags($objid, $objclass);
        foreach ($oldtags as $erg) {
            $tag = $erg['tagname'];

            if (isset($addlist[$tag])) {
                $this->updatetagname($tag, $objclass, $addlist[$tag]);
                unset($addlist[$tag]);
            } else
                $dellist[$tag] = $tag;
        }

        // add list contains all new tags to be created, dellist contains all tags to be deleted ...

        foreach ($addlist as $tag => $tagname)
            $this->addtag($objid, $tag, $objclass, $tagname);

        foreach ($dellist as $tag)
            $this->deletetag($objid, $tag, $objclass);
    }

    public function getrelatedtags(string $basetag, string $objclass = 'article'): array
    {

        $ret = array();
        $tags = array();

        $base = $this->db->get('SELECT tagnameid FROM ' . self::TAGLIST_TABLE . ' WHERE tagname=? AND objclass=?', array($basetag, $objclass));
        if (empty($base))
            return array();

        $bind = array();
        $bind['tagnameid'] = $base['tagnameid'];
        $bind['objclassa'] = $objclass;
        $bind['objclassb'] = $objclass;
        $bind['basetag'] = $basetag;


        $sql = 'SELECT l.tagname FROM ' . self::TAGLIST_TABLE . ' l  JOIN ' . self::TAGGING_TABLE . ' e ON l.tagnameid=e.tagnameid  JOIN ' . self::TAGGING_TABLE .
            ' s ON s.objid=e.objid  WHERE s.tagnameid=:tagnameid AND s.objclass= :objclassa AND e.objclass=:objclassb AND l.tagname<>:basetag';

        $liste = $this->db->getlist($sql, $bind);

        $taglist = array();
        $maxcount = 0;
        $sumcount = 0;
        $tagcount = 0;

        foreach ($liste as $erg)
            $tags[$erg['tagname']] += 1;

        if (!empty($tags))
            foreach ($tags as $tagname => $anzahl) {
                $erg = $this->db->get('SELECT * FROM ' . self::TAGLIST_TABLE . ' WHERE tagname=? AND objclass=?', array($tagname, $objclass));
                if (empty($erg))
                    continue;

                $erg['tagcount'] = $anzahl;
                $taglist[] = $erg;
                $tc = $erg['tagcount'];
                if ($maxcount < $tc)
                    $maxcount = $tc;
                $sumcount = $sumcount + $tc;
                $tagcount = $tagcount + 1;
            }

        $ret['tags'] = $taglist;
        $ret['maxcount'] = $maxcount;
        $ret['sumcount'] = $sumcount;
        $ret['tagcount'] = $tagcount;

        return $ret;
    }

    public function getalltags(int $mintagcount = 0, string $objclass = 'article'): array
    {

        if ($mintagcount > 0)
            $liste = $this->db->getlist('SELECT * FROM ' . self::TAGLIST_TABLE . ' WHERE tagcount >=? AND objclass=? ORDER BY tagname', array($mintagcount, $objclass));
        else
            $liste = $this->db->getlist('SELECT * FROM ' . self::TAGLIST_TABLE . ' WHERE objclass=? ORDER BY tagname', array($objclass));

        $taglist = array();
        $maxcount = 0;
        $sumcount = 0;
        $tagcount = 0;

        foreach ($liste as $erg) {
            $taglist[] = $erg;
            $tc = $erg['tagcount'];
            if ($maxcount < $tc)
                $maxcount = $tc;
            $sumcount = $sumcount + $tc;
            $tagcount = $tagcount + 1;
        }

        $ret = array();
        $ret['tags'] = $taglist;
        $ret['maxcount'] = $maxcount;
        $ret['sumcount'] = $sumcount;
        $ret['tagcount'] = $tagcount;

        return $ret;
    }


}

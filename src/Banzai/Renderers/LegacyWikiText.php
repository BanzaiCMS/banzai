<?php

namespace Banzai\Renderers;

use Banzai\Core\Application;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Files\FilesGateway;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Domain\Pictures\PicturesGateway;
use Banzai\Domain\Products\ProductsGateway;
use Banzai\Domain\Videos\VideosGateway;

class LegacyWikiText
{
    public function __construct(protected DatabaseInterface $db,
                                protected LoggerInterface $logger,
                                protected FoldersGateway $folders,
                                protected ArticlesGateway $articles,
                                protected ProductsGateway $products,
                                protected PicturesGateway $pictures,
                                protected FilesGateway $files)
    {

    }


//  WIKI-Funktionen, zum Anzeigen, Parsen etc. von WIKI-Artikel-Format
//
//  einige Formatierungen des Wiki-Formates uebernommen
//  in der Artikel-Tabelle ist dieses das "strukturierter Text" Format
//  aehnlich dem "BBS" format
//


//  Formatierung          Aktion
//
//  {{ Kommentar }}       Einschliesslich Klammern alles wegloeschen
//  {| Tabelle   |}           "
//  ====  Kleine Ueberschrift ====        <H4> Ueberschrift</h2>
//  ===  Unterueberschrift ===        <H3> Ueberschrift</h2>
//  ==   Hauptueberschrift ==         <H2> Ueberschrift</h2>
//  '''Fett'''            <b>Fett</b>
//  ''Kursiv''			  <i>Kursiv<i>
//  '''''FettundKursiv'''''<i><strong>kjhklh</strong></i>
//  *                     Liste
//  \n                    <br>
//  #REDIRECT             Siehe Auch
//  #DELETED


//  ----                  Linie
// Externe Links
//  [LINK]                <a href="LINK">LINK</a>
//  [LINK TEXT]           <a href="LINK">TEXT</a>
//  [LINK|TEXT]           <a href="LINK">TEXT</a>
//
//
// Interne Wiki Links
//
//  [[LINK]]              <a href="LINK">LINK</a>
//  [[LINK|TEXT]]         <a href="LINK" title="TEXT">TEXT</A>
//
//  Falls Link mit "#Anum" beginnt
//  wird die URL des Artikels Nummer "num" genommen
//  Bei   #Inum wird ein Bild genommen
//
//  Namespaces ...
// [[Namespace:Link]]

    /**
     * @param $msg
     * @param string $absurl
     * @return mixed|string
     */
    public static function wiki_parsetext($msg, $absurl = 'no')
    {

        //      return $msg;
        global $in_p_element;

        $msg_out = "";
        $inkl = 0;
        $cstr = "";

        $in_p_element = false;

        //	$msg = wiki_do_delete($msg,"{|","|}");
        $msg = self::wiki_do_delete($msg, "{{", "}}"); // Kommentare ggf. in HTML-Kommentare wechseln

        // **************************** START ZEICHENWEISE PARSEN ******************

        $l = strlen($msg);

        if ($l < 1)
            return $msg;

        $prec = "";
        $ordch = 0;

        $escape = 0;
        $anzkla = 0;

        for ($i = 0; $i < $l; $i++) {
            $c = substr($msg, $i, 1);

            if ($c == '^') {
                $escape = $escape + 1;
                continue;
            }

            if ($escape == 1) {
                $escape = 0;
                $msg_out .= '&#' . ord($c) . ';';
                continue;
            }

            if ($escape == 2) {
                $ordch = $ordch . $c;
                continue;
            }

            if ($escape == 3) {
                $escape = 0;
                $msg_out .= '&#' . $ordch . ';';
                $ordch = '';
            }

            if ($inkl == 0) { // noch kein Zeichen erkannt ....
                $prec = $c;
                if ($c == "[") { // erste eckige Klammer ...
                    $inkl = 1;
                    $cstr = "";
                    continue;
                }

                $msg_out .= $c;
                continue;
            }


            // Hier sind wir nun in der eckige_klammer routine


            if (($c == "[") && ($inkl == 1) && ($prec == "[") && (strlen($cstr) == 0)) {
                $inkl = 2;
                $prec = $c;
                continue;
            }


            if ($c == "[") {
                $cstr .= $c;
                $anzkla = $anzkla + 1;
                $prec = $c;
                continue;
            }


            if (($c == "]") && ($anzkla > 0)) { // Noch nicht letzte/ende
                $cstr .= $c;
                $anzkla = $anzkla - 1;
                $prec = $c;
                continue;
            }


            if (($c == "]") && ($inkl == 1)) { // ende eine klammer ...
                $msg_out .= self::wiki_do_einkl($cstr);
                $cstr = "";
                $anzkla = 0;
                $prec = "";
                $inkl = 0;
                continue;
            }


            if (($c == "]") && ($inkl == 2) && ($prec == "]")) { // ende zwei klammer ...
                $msg_out .= self::wiki_do_zweikl($cstr, $absurl);
                $cstr = "";
                $anzkla = 0;
                $prec = "";
                $inkl = 0;
                continue;
            }

            if (($c == "]") && ($inkl == 2)) { // erste von zwei endklammer ende zwei klammer ...
                $prec = $c;
                continue;
            }


            // Kein Steuerzeichen aber in substring:
            $cstr .= $c;
            $prec = $c;
        } // ende von for schleife durch string


        // Falls etwas nicht zum ende geschlossen wurde:
        $msg_out .= $cstr;

        $msg = $msg_out;

        // **************************** ENDE ZEICHENWEISE PARSEN ******************


        // **************************** START ZEILENWEISE PARSEN ******************


        // $msg .= "\r";	// Fer letzte Zeile workaround
        $lines = explode("\n", $msg);
        $msg = '';
        $inliste = 0;
        $tabnum = 0;
        $inrow = 0;
        $inbq = 0;
        $even = 0;
        $altfound = 1;
        $throw = 0;

        foreach ($lines as $zeile) {

            $c = substr($zeile, 0, 1); // erstes Zeichen


            // $lz = substr($zeile,strlen($zeile)-1,1);	// letztes Zeichen
            // if (($lz=='\n') || ($lz=='\r'))
            //	$meizei = substr($zeile,0,strlen($zeile)-1);
            // else
            $meizei = $zeile;

            $found = 0;

            // Liste
            if ($c == '*') {
                if ($in_p_element) {
                    $in_p_element = false;
                    $msg .= '</p>';
                }

                $altinliste = $inliste;
                $inliste = 1;

                if (substr($zeile, 0, 2) == '**')
                    $inliste = 2;

                if (substr($zeile, 0, 3) == '***')
                    $inliste = 3;

                if (substr($zeile, 0, 4) == '****')
                    $inliste = 4;

                if ($inliste > $altinliste) // nur auf maximal eins beschraenken
                    $inliste = $altinliste + 1;

                if ($altinliste == $inliste)
                    $msg .= '</li>';

                if ($altinliste > $inliste)
                    for ($lauf = $inliste; $lauf < $altinliste; $lauf++)
                        $msg .= "</ul></li>\n";

                if ($altinliste < $inliste)
                    // for ($lauf=$altinliste;$lauf<$inliste;$lauf++)
                    $msg .= '<ul class="wlist' . $inliste . '">';

                $tedee = substr($meizei, $inliste);
                $posi = strpos($tedee, "|");

                if ($posi === false) { // Achtung: 3 Gleichheits-Zeichen
                    $msg .= '<li class="wlist">' . $tedee . "\n";
                } else {
                    $re = substr($tedee, $posi + 1);
                    $li = substr($tedee, 0, $posi);

                    if (strpos($li, 'class') === false)
                        $cla = 'class="wlist" ';
                    else
                        $cla = '';
                    $msg .= '<li ' . $cla . $li . '>' . $re . "\n";
                }
                $found = 1;
            } else {
                if ($inliste > 1) {
                    // $msg .='</li>';


                    for ($lauf = 1; $lauf < $inliste; $lauf++)
                        $msg .= "</ul></li>\n";
                    $inliste = 1;
                }
                if ($inliste == 1) {
                    $msg .= "</li></ul>\n";
                    $inliste = 0;
                }

            } // else


            // PRE
            /*
            if ($c==' ') {
                if ($inpre==0) {
                    $inpre = 1;
                    $msg .= "<pre>\n";
                }
                $msg .= substr($meizei,1) . "\n";
                $found=1;
            } else {
                if ($inpre==1) {
                    $msg .= "</pre>\n";
                    $inpre=0;
                }
            }
            */

            if (!defined('INSCCMS_TWIG')) {

                if ($c == ' ') {
                    if ($inbq == 0) {
                        $inbq = 1;
                        $msg .= "<blockquote>\n";
                    }
                    $msg .= substr($meizei, 1) . "<br/>\n";
                    $found = 1;
                } else {
                    if ($inbq == 1) {
                        $msg .= "</blockquote>\n";
                        $inbq = 0;
                    }
                }
            }

            $zweili = substr($meizei, 0, 2);

            // Tabellenbehandlung ...
            if ($zweili == '{|') { // Tabellenanfang
                if ($tabnum > 0) // untertabelle ...  TODO inrow check
                    $msg .= '<td>';
                $tabnum = $tabnum + 1;
                if (strpos(substr($meizei, 2), 'class') === false)
                    $cla = 'class="wtable" ';
                else
                    $cla = '';

                if ($in_p_element) {
                    $in_p_element = false;
                    $msg .= '</p>';
                }

                $msg .= '<div class="wtable"><table ' . $cla . substr($meizei, 2) . ">\n";
                $found = 1;
                $inrow = 0;
            }
            if (($found == 0) && ($tabnum > 0) && ($zweili == '|}')) { // Tabellenende
                if ($tabnum > 1)
                    $msg .= "</tr></table></div></td>\n";
                else
                    $msg .= "</tr></table></div>\n"; // TODO inrow check
                $tabnum = $tabnum - 1;
                $found = 1;
            }
            if (($found == 0) && ($tabnum > 0) && ($zweili == '|+')) { // Beschriftung
                $msg .= '<caption class="wtable">' . substr($meizei, 2) . "</caption>\n";
                $found = 1;
            }

            if (($found == 0) && ($tabnum > 0) && ($zweili == '|-')) { // n�chste Zeile
                if ($even == 1)
                    $evstr = 'class="wtable" ';
                else
                    $evstr = 'class="wtable_even" ';
                if ($inrow == 0) {
                    $inrow = 1;
                    $msg .= '<tr ' . $evstr . '>';
                }
                $even = 1 - $even;
                if ($even == 1)
                    $evstr = 'class="wtable" ';
                else
                    $evstr = 'class="wtable_even" ';

                if (strpos(substr($meizei, 2), 'class') === false)
                    $cla = $evstr;
                else
                    $cla = '';
                $msg .= '</tr><tr ' . $cla . substr($meizei, 2) . ">\n";
                $found = 1;

                $tdrow = 0;

            }

            if (($found == 0) && ($tabnum > 0) && ($c == '|')) { // Feld
                if ($inrow == 0) {
                    $inrow = 1;
                    $msg .= '<tr>';
                    $tdrow = 0;
                }

                $felder = explode('||', substr($meizei, 1));
                // $tdrow=0;
                foreach ($felder as $tedee) {
                    $tdrow = $tdrow + 1;
                    /*
                    if ($even==1)
                        $evstr = '';
                    else
                        $evstr = 'e';
                    */
                    $evstr = 'class="col' . $tdrow . '" ';

                    $posi = strpos($tedee, "|");
                    if ($posi === false) { // Achtung: 3 Gleichheits-Zeichen
                        $msg .= '<td ' . $evstr . '>' . $tedee . "</td>";
                    } else {
                        $re = substr($tedee, $posi + 1);
                        $li = substr($tedee, 0, $posi);

                        if (strpos($li, 'class') === false)
                            $cla = $evstr;
                        else
                            $cla = '';
                        $msg .= '<td ' . $cla . $li . '>' . $re . "</td>\n";
                    }
                }
                $msg .= "\n";
                $found = 1;
            } // feld


            if (($found == 0) && ($tabnum > 0) && ($c == '!')) { // Feld
                if ($inrow == 0) {
                    $inrow = 1;
                    $msg .= '<tr>';
                    $throw = 0;
                }
                $throw = $throw + 1;
                $evstr = 'class="col' . $throw . '" ';
                $tedee = substr($meizei, 1);
                $posi = strpos($tedee, "|");
                if ($posi === false) { // Achtung: 3 Gleichheits-Zeichen
                    $msg .= '<th ' . $evstr . '>' . $tedee . "</th>\n";
                } else {
                    $re = substr($tedee, $posi + 1);
                    $li = substr($tedee, 0, $posi);
                    if (strpos($li, 'class') === false)
                        $cla = 'class="wtable" ';
                    else
                        $cla = '';

                    $msg .= '<th ' . $cla . $li . '>' . $re . "</th>\n";
                }
                $found = 1;
            } // ende feld


            // ende tabellenbearbeitung


            // wenn nichts gefunden ...
            // $msg .= $meizei;


            if ($found == 0) {

                /*

                if ($pos===false)
                    $msg .= $meizei .  "<br/>\n";
                else
                    $msg .= $meizei . "\n";
                */

                if (empty ($msg) || ($altfound !== false))
                    $msg .= $meizei;
                else
                    $msg .= '<br/>' . $meizei;

            }

            $pos = strpos($meizei, "==");
            if ($pos === false)
                $pos = strpos($meizei, "----");
            $altfound = $pos;

        } // ende zeilen weise parsen


        // wenn noch in Liste, dann </ul> nachschieben.
        if ($inliste > 1) {
            for ($lauf = 1; $lauf < $inliste; $lauf++)
                $msg .= "</ul></li>\n";
            $inliste = 1;
        }
        if ($inliste == 1) {
            $msg .= "</ul>\n";
            $inliste = 0;
        }

        // blockquote schliessen
        if ($inbq == 1) {
            $msg .= "</blockquote>\n";
            $inbq = 0;
        }

        if (count($lines) > 1)
            $msg .= '<br/>';

        // **************************** ENDE ZEILENWEISE PARSEN ******************
        // wird schon am anfang gemacht $msg = str_replace('&','&amp;',$msg);


        $msg = str_replace('----', '<hr/>', $msg);

        $msg = self::wiki_do_repl($msg, '---', '---', '', '', 'addanchor');

        $msg = self::wiki_do_repl($msg, "======", "======", "<h1>", "</h1>");
        $msg = self::wiki_do_repl($msg, "====", "====", "<h4>", "</h4>");
        $msg = self::wiki_do_repl($msg, "===", "===", "<h3>", "</h3>");
        $msg = self::wiki_do_repl($msg, "==", "==", "<h2>", "</h2>");
        // $msg= wiki_do_repl($msg,"=","=","<h1>","</h1>");


        $msg = self::wiki_do_repl($msg, '{::', '::}', '<div', '</div>', 'self::wikiblock');

        //	$msg= wiki_do_repl($msg,"=","=","<h1>","</h1>");
        $msg = self::wiki_do_repl($msg, "'''''", "'''''", "<i><strong>", "</strong></i>");
        $msg = self::wiki_do_repl($msg, "'''", "'''", "<strong>", "</strong>");
        $msg = self::wiki_do_repl($msg, "''", "''", "<i>", "</i>");
        $msg = self::wiki_do_repl($msg, "__", "__", "<span style='text-decoration: underline'>", "</span>");
        $msg = self::wiki_do_repl($msg, "~~", "~~", "<del>", "</del>");

        $msg = self::wiki_do_repl($msg, '&lt;sup&gt;', '&lt;/sup&gt;', '<sup>', '</sup>');
        $msg = self::wiki_do_repl($msg, '&lt;sub&gt;', '&lt;/sub&gt;', '<sub>', '</sub>');

        $msg = str_replace('\\\\', "<br/>", $msg); /*    backslash immer escapen */
        // $msg = str_replace("\n","<br>",$msg);


        $msg = str_replace("#REDIRECT", "Siehe auch: ", $msg);
        $msg = str_replace("#redirect", "Siehe auch: ", $msg);

        $msg = str_replace("#DELETED", "Dieser Text ist als gel&ouml;scht markiert.  ", $msg);

        // Zum Schluss, damit alle anderen Wiki-Transformationen erledigt sind ...
        $msg = self::wiki_do_repl($msg, '{:', ':}', '<div', '</div>', 'wikiextract');

        return $msg;
    }

// externer Link
// Parsen einer Klammer, d.h. externer Link
    /**
     * @param $msg
     * @return string
     */
    public static function wiki_do_einkl($msg)
    {
        //	$bla = "<i>" . $msg . "</i>";
        // global $header_doctype;


        $pos = strpos($msg, "|");
        if ($pos === false)
            $pos = strpos($msg, " ");
        if ($pos === false) { // Achtung: 3 Gleichheits-Zeichen
            $url = $msg;
        } else {
            $title = substr($msg, $pos + 1);
            $url = substr($msg, 0, $pos);
        }


        if (empty ($title))
            $title = $url;
        $liti = $title;
        if (!empty ($title)) {
            if (substr($title, 0, 2) == '#I') { // Steuercode z.B. ein Bild ...
                $utut = '[[' . $title . ']]';
                $title = self::wiki_parsetext($utut);
            }
        }

        if (substr($url, 0, 1) == '#') { // kein relativer In-Page Link
            $target = '';
        } else {
            $target = ' target="_blank" ';
            $pos = strpos($url, '://');
            if ($pos === false) { // relative URL
                $pos = strpos($url, '@');
                if ($pos !== false)
                    $url = 'mailto:' . $url;
                else
                    $url = 'http://' . htmlspecialchars($url, ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8');
            }
        }


        // if ($header_doctype=='<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">')
        //	$bla = '<a class="wlinke" href ="'.$url.'" title="'.$liti.'">"'.$title.'</a>';
        // else
        return '<a class="wlinke" href ="' . $url . '" title="' . $liti . '" ' . $target . '>' . $title . '</a>';

    }

    /**
     * Parsen zweier Klammer, d.h. interner Link mit Sonderbedeutung
     * @param string $msg
     * @param string $absurlstring
     * @return array|false|mixed|string
     */
    public static function wiki_do_zweikl(string $msg, string $absurlstring = 'no')
    {
        global $cat_id;
        global $itemdir;

        $absurl = ($absurlstring == 'yes');
        $editoki = Application::get('user')->hasPermission('edit_articles');

        $bla = '';
        $namespace = '';
        $sublink = '';
        $ut = '';

        $pos = strpos($msg, "|");
        if ($pos === false) { // Achtung: 3 Gleichheits-Zeichen
            // $ut = $msg;
            // $url = str_replace(" ","_",$msg);
            $url = $msg;

            // $ut  = $msg;
        } else {
            $ut = substr($msg, $pos + 1);
            $url = substr($msg, 0, $pos);

            // $url = str_replace(" ","_",$url);
        }

        // namespace ist Links vom "|" (falls dieser vorhanden ist)
        $pos = strpos($url, ":");
        if ($pos !== false) { // Namespaces
            $namespace = substr($url, 0, $pos);
            $url = substr($url, $pos + 1);
        }

        $pos = !empty ($namespace);
        // $pos = false;


        if ($pos && function_exists('get_namespaceobj')) { // Namespace gesetzt // TODO move function from include to class
            $nspa = get_namespaceobj($namespace);

            if (is_array($nspa)) { // Link setzen ...
                $url = rawurlencode($url);
                if ($nspa ['type'] == 'internal')
                    $url = $nspa ['inturl'] . $url . '.html';
                else
                    $url = $nspa ['exturl'] . $url;
            }
        }

        $nofo = '';

        // #A Artikel
        if ($pos === false) {
            $pos = strpos($url, "#A");

            if ($pos !== false) {
                $subnu = substr($url, $pos + 2);
                $supo = strpos($subnu, "#");
                if ($supo === false) {
                    $artnum = $subnu;
                } else {
                    $artnum = substr($subnu, 0, $supo);
                    $sublink = substr($subnu, $supo);
                    // $sublink  = '#' . urlencode(substr($subnu,$supo+1));
                    $sublink = substr($sublink, 1);
                    $sublink = str_replace(" ", "_", $sublink);
                    $sublink = str_replace(',', '', $sublink);
                    $sublink = rawurlencode($sublink);
                    $sublink = '#id' . str_replace('%', '', $sublink);

                }

                if (!empty ($ut)) {
                    if (substr($ut, 0, 2) == '#I') { // Steuercode z.B. ein Bild ...
                        $utut = '[[' . $ut . ']]';
                        $ut = self::wiki_parsetext($utut, $absurlstring);
                        $wlinki = 'wlinkp';
                    }
                }

                // $artnum = substr($url,$pos+2);
                $artobj = Application::get(ArticlesGateway::class)->getArticle((int)$artnum);

                if (is_array($artobj)) {

                    if (isset($artobj ['visible_sitemaps']))
                        if ($artobj ['visible_sitemaps'] == 'no')
                            $nofo = ' rel="nofollow" ';

                    if (empty ($ut))
                        if (isset($artobj ['titel2']))
                            $ut = $artobj ['titel2'];

                    // $uti = \Banzai\Renderers\RenderersGateway::RenderText($artobj['kurztext'],$artobj['kurztext_type']);
                    if (empty ($artobj ['linktitle']))
                        $uti = $artobj ['titel1'];
                    else
                        $uti = $artobj ['linktitle'];
                    if (empty ($uti))
                        $uti = $artobj ['pagetitle'];
                    if ($editoki)
                        $uti = $url;
                    $url = $artobj ['fullurl'];

                }
            }
        }

        // #P produkt
        if ($pos === false) {
            $pos = strpos($url, "#P");

            if ($pos !== false) {
                $subnu = substr($url, $pos + 2);
                $supo = strpos($subnu, "#");
                if ($supo === false) {
                    $artnum = $subnu;
                } else {
                    $artnum = substr($subnu, 0, $supo);
                    $sublink = substr($subnu, $supo);
                    // $sublink  = '#' . urlencode(substr($subnu,$supo+1));
                    $sublink = substr($sublink, 1);
                    $sublink = str_replace(" ", "_", $sublink);
                    $sublink = str_replace(',', '', $sublink);
                    $sublink = urlencode($sublink);
                    $sublink = '#id' . str_replace('%', '', $sublink);

                }

                if (!empty ($ut)) {
                    if (substr($ut, 0, 2) == '#I') { // Steuercode z.B. ein Bild ...
                        $utut = '[[' . $ut . ']]';
                        $ut = self::wiki_parsetext($utut);
                    }
                }

                // $artnum = substr($url,$pos+2);
                if (function_exists('get_prod_obj_from_id'))    // TODO move function from include to class
                    $artobj = get_prod_obj_from_id($artnum);
                else
                    $artobj = array();

                if (!empty($artobj)) {
                    if (empty ($ut))
                        $ut = $artobj ['titel2'];

                    // $uti = \Banzai\Renderers\RenderersGateway::RenderText($artobj['kurztext'],$artobj['kurztext_type']);
                    $uti = $artobj ['titel1'];
                    if ($editoki)
                        $uti = $url;
                    $url = $artobj ['fullurl'];

                }
            }
        }

        // #T Teaser auf Artikel
        if ($pos === false) {
            $pos = strpos($url, '#T');

            if ($pos !== false) {
                $artnum = (int)substr($url, $pos + 2);

                if (!empty ($ut)) {
                    if (substr($ut, 0, 2) == '#I') { // Steuercode z.B. ein Bild ...
                        $utut = '[[' . $ut . ']]';
                        $ut = self::wiki_parsetext($utut);
                        $wlinki = 'wlinkp';
                    }
                }

                /** @var ArticlesGateway $AG */
                $ag = Application::get(ArticlesGateway::class);

                $artobj = $ag->getArticle($artnum);
                if (!empty($artobj)) {
                    $bla = $ag->showArticle($artobj, 'teaser', 'noshow', $ut);
                }
            }
        }

        // #I Image
        if ($pos === false) {
            $pos = strpos($url, "#I");
            if ($pos !== false) {
                $picid = substr($url, $pos + 2);
                $bla = Application::get(PicturesGateway::class)->getPictureHTML($picid, $absurlstring);
            }
        }


        // #V Video
        if ($pos === false) {
            $pos = strpos($url, "#V");
            if ($pos !== false) {
                $vidid = substr($url, $pos + 2);
                /** @var VideosGateway $VideosGateway */
                $VideosGateway = Application::get('Banzai\Domain\Videos\VideosGateway');
                $vida = $VideosGateway->getVideoInfo($vidid);
                if (!empty($vida)) {
                    $inci = $itemdir . $vida ['object_template'] . '.php';
                    $obuf = '';
                    if (file_exists($inci)) {
                        ob_start(); // start output buffer
                        include($inci);
                        $obuf = ob_get_contents(); // read ob2 ("b")
                        ob_end_clean();
                        $obuf = str_replace("\n", '', $obuf);
                    }
                    if (empty ($obuf))
                        $bla = '<a href="' . $vida ['fullurl'] . '">' . $vida ['title'] . '</a>';
                    else
                        $bla = $obuf;
                }
            }
        }

        // #E Extern/embedded (z.B. Video)
        if ($pos === false) {
            $pos = strpos($url, "#E");
            if ($pos !== false) {
                $estring = substr($url, $pos + 2);
                $inci = $itemdir . 'extern.php';
                $obuf = '';
                if (file_exists($inci)) {
                    ob_start(); // start output buffer
                    include($inci);
                    $obuf = ob_get_contents(); // read ob2 ("b")
                    ob_end_clean();
                    $obuf = str_replace("\n", '', $obuf);
                }
                $bla = $obuf;
            }
        }

        // #F File
        if ($pos === false) {
            $pos = strpos($url, "#F");
            if ($pos !== false) {
                $fid = substr($url, $pos + 2);
                $pida = Application::get(FilesGateway::class)->getFileInfo($fid);

                if (is_array($pida)) {
                    if (!empty ($ut))
                        $pida ['title'] = $ut;

                    //	$ut = $pida['title'];
                    // if (empty($ut))
                    //	$ut = $pida['url'];
                    // $uti = $pida['descr'];
                    // $uti = \Banzai\Renderers\RenderersGateway::RenderText($pida['descr'],$pida['descr_type']);
                    // $uti = $pida['descr'];
                    // if ($editoki)
                    //	$uti = $url;
                    // $url = $pida['fullurl'];
                    $inci = $itemdir . $pida ['object_template'] . '.php';
                    $obuf = '';
                    if (file_exists($inci)) {
                        ob_start(); // start output buffer
                        include($inci);
                        $obuf = ob_get_contents(); // read ob2 ("b")
                        ob_end_clean();
                        $obuf = str_replace("\n", '', $obuf);
                    }
                    if (empty ($obuf)) {
                        if ($pida ['newwindow'] == 'yes')
                            $nw = 'target = "_blank" ';
                        else
                            $nw = '';
                        $bla = '<a ' . $nw . 'href="' . $pida ['fullurl'] . '">' . $pida ['title'] . '</a>';
                    } else
                        $bla = $obuf;
                }
            }
        }

        if (($pos === false) && ($editoki) && (empty ($namespace))) { // Nicht gefunden in in Editor ...
            $artid = Application::get(ArticlesGateway::class)->getArticleIDFromURL($url, $cat_id);

            if ($artid > 0) {
                $uti = '#A' . $artid;
                $pos = true;
                $url = $url . '.html';
            }
        }

        if ($pos === false) { // Nichts gefunden ...
            if ((strpos($url, '.') === false) && (substr($url, -1) != '/')) // kein Punkt vorhanden und kein letztes "/" slassh
                $url = rawurlencode($url) . '.html';
        }
        if (empty ($ut))
            $ut = $msg;

        if (empty ($uti))
            $uti = $ut;

        if (empty ($wlinki))
            $wlinki = 'wlinki';

        if (empty ($bla))
            $bla = '<a class="' . $wlinki . '" href ="' . $url . $sublink . '" title="' . $uti . '" ' . $nofo . ' >' . $ut . '</a>';

        return $bla;
    }

    /**
     * @param $msg
     * @param $start
     * @param $ende
     * @return string
     */
    public static function wiki_do_delete($msg, $start, $ende)
    {

        $msg_out = "";
        $msg_in = $msg;

        while (strlen($msg_in) > 0) {

            $pos = strpos($msg_in, $start);

            if ($pos === false) { // nicht vorhanden
                $msg_out .= $msg_in;
                return $msg_out;
            };

            $msg_out .= substr($msg_in, 0, $pos);

            $msg_in = substr($msg_in, $pos + strlen($start));

            $pos = strpos($msg_in, $ende);

            if ($pos === false) { // nicht vorhanden
                return $msg_out;
            };

            $msg_in = substr($msg_in, $pos + strlen($ende));

            // Hier koennten wir jetzt was mit $inhalt machen TODO
        };

        return $msg_out;
    }


    /**
     * @param $inhalt
     * @param $praein
     * @param $postin
     * @return string
     */
    public static function wikiblock($inhalt, $praein, $postin)
    {

        $pos = strpos($inhalt, ':');

        if ($pos === false) { // nicht vorhanden
            $ret = $praein . ' class="wblock">' . $inhalt . $postin;
            return $ret;
        }

        $cla = substr($inhalt, 0, $pos);
        $in2 = substr($inhalt, $pos + 1);

        if (substr($cla, 0, 1) == '#')
            $name = ' id="' . substr($cla, 1, strlen($cla) - 1) . '"';
        else
            $name = ' class="' . $cla . '"';

        $ret = $praein . $name . '>' . $in2 . $postin;

        return $ret;
    }

    /**
     * @param $inhalt
     * @param $praein
     * @param $postin
     * @return string
     */
    public static function wikiextract($inhalt, $praein, $postin)
    {

        global $wiki_extractblock;

        $pos = strpos($inhalt, ':');

        if ($pos === false) { // nicht vorhanden
            $ret = ''; // $praein .' class="wblock">'.$inhalt . $postin ;
            return $ret;
        }

        $cla = substr($inhalt, 0, $pos);
        $in2 = substr($inhalt, $pos + 1);

        // $in3 = wiki_parsetext($in2,$bla);
        $ele ['class'] = $cla;
        $ele ['content'] = $in2;
        // $zurueck[] = $ele;
        $wiki_extractblock [] = $ele;
        return '';
    }

    /**
     * @param $inhalt
     * @param $praein
     * @param $postin
     * @param null $zurueck
     * @return string
     */
    public static function addanchor($inhalt, $praein, $postin, $zurueck = null)
    {

        // deaktivieren ...
        // weil Links in Ueberschriften nicht validieren ... und alles kaputt machen ...
        // $ret = $praein .$inhalt . $postin ;
        // return $ret;


        // if ( (empty($inhalt)) || ($inhalt==' ') || ($inhalt=='&nbsp;')   ) {
        //	$ret = $praein .$inhalt . $postin ;
        //	return $ret;
        // }


        // $url = str_replace(" ","_",$inhalt);
        // $url = str_replace(',','',$url);
        // $url = htmlentities($url,ENT_QUOTES,INS_CHARSET_ENCODING);
        // $url = str_replace('%','',$url);


        // $url = makeSEOCleanURL($inhalt);


        $url = $inhalt;

        // $ret = '<a name="' . $url . '" id="' . $url . '"></a>' . $praein . $inhalt . $postin ;
        // $ret = '<a id="id' . $url . '"></a>' . $praein . $inhalt . $postin ;
        $ret = '<a id="' . $url . '"></a>';
        return $ret;
    }

    /**
     * @param $msg
     * @param $start
     * @param $ende
     * @param $neustart
     * @param $neuende
     * @param null $callfunc
     * @return string
     */
    public static function wiki_do_repl($msg, $start, $ende, $neustart, $neuende, $callfunc = NULL)
    {

        $msg_out = "";
        $msg_in = $msg;

        while (strlen($msg_in) > 0) {

            $pos = strpos($msg_in, $start);

            if ($pos === false) { // nicht vorhanden
                $msg_out .= $msg_in;
                return $msg_out;
            };

            $msg_out .= substr($msg_in, 0, $pos);

            $msg_in = substr($msg_in, $pos + strlen($start));

            $pos = strpos($msg_in, $ende);

            if ($pos === false) { // nicht vorhanden
                return $msg_out;
            };

            $inhalt = substr($msg_in, 0, $pos);
            $msg_in = substr($msg_in, $pos + strlen($ende));

            // Hier koennten wir jetzt was mit $inhalt machen TODO
            if (isset ($callfunc))
                $msg_out .= call_user_func($callfunc, $inhalt, $neustart, $neuende);
            else
                $msg_out .= $neustart . $inhalt . $neuende;
        };

        return $msg_out;
    }

}

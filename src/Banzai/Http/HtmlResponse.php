<?php
declare(strict_types=1);

namespace Banzai\Http;

use Flux\Psr7\Response as Psr7Response;
use Banzai\Core\Application;
use Flux\Psr7\TempStream;
use Banzai\Domain\Blocks\BlocksGateway;
use Twig\Environment as TwigEnvironment;

class HtmlResponse extends Psr7Response implements ResponseInterface
{
    use ResponseTrait;

    public function __construct(protected TwigEnvironment $twig, protected BlocksGateway $bg, protected array $data = array(), protected int $statuscode = 200, array $headers = array(), protected array $cookies = array(), protected string $charset = 'UTF-8', protected string $language = 'de')
    {
        parent::__construct(new TempStream());

        foreach ($headers as $name => $value)
            $this->initHeader($name, $value);

    }

    public static function create(array $data = array(), int $status = 200, array $headers = array(), array $cookies = array(), string $charset = 'UTF-8', string $language = 'de'): static
    {
        return new static(Application::get('twig'), Application::get(BlocksGateway::class), $data, $status, $headers, $cookies, $charset, $language);
    }


    public function setCharset(string $charset): static
    {
        $this->charset = $charset;
        $this->initHeader('Content-Type', 'text/html; charset=' . $this->charset, true);

        return $this;
    }

    protected function render(): string
    {
        $this->setRenderState();

        // render blocks
        if (!empty($this->data['blocks'])) {
            $newblocks = array();
            foreach ($this->data['blocks'] as $index => $blocktree)
                $newblocks[$index] = $this->bg->renderBlocksTree($this->twig, $this->data, $blocktree);
            $this->data['blocks'] = $newblocks;
        }

        $template = $this->twig->load($this->data['template']);
        return $template->render($this->data);

    }

    public function sendContent()
    {
        $body = $this->getBody();

        if (!$this->getRenderState())
            $body->write($this->render());

        $body->rewind();
        echo $body->getContents();

    }

    public function send()
    {
        $this->sendHeaders();
        $this->sendContent();

    }

    public function withRequestTracking(): bool
    {
        return true;
    }

}
